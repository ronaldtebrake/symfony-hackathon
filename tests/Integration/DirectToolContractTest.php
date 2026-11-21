<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use CodeMode\Symfony\Http\CatalogStateStore;
use CodeMode\Symfony\Http\CatalogTools;
use CodeMode\Symfony\Http\DirectTools;
use CodeMode\Symfony\Http\SecurityIdentityProvider;
use CodeMode\Symfony\ReadToolInterface;
use CodeMode\Symfony\SessionFactory;
use CodeMode\ToolDefinition;
use CodeMode\ToolVisibilityInterface;
use Mcp\Capability\Registry;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class DirectToolContractTest extends TestCase
{
    public function testHostCannotAdvertiseAnUnapprovedToolName(): void
    {
        $registry = new Registry();
        $direct = $this->direct($registry, 'approved', names: ['unapproved']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"unapproved" is outside the approved catalog');
        $direct->load($registry);
    }

    public function testListingOnlyChecksVisibilityForDirectNames(): void
    {
        $registry = new Registry();
        $policy = $this->createMock(ToolVisibilityInterface::class);
        $policy->expects($this->once())->method('isVisible')->with($this->anything(), 'read')->willReturn(true);
        $direct = $this->direct($registry, 'approved', policy: $policy);
        $direct->load($registry);
        $result = $direct->handle((new ListToolsRequest())->withId(1), $this->createStub(SessionInterface::class))->result;
        $this->assertSame(['read'], array_column($result->tools, 'name'));
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testAnotherHandlerCannotClaimTheApprovedName(bool $beforeRegistration): void
    {
        $registry = new Registry();
        $direct = $this->direct($registry, 'approved');
        if (!$beforeRegistration) {
            $direct->load($registry);
        }
        $registry->registerTool(Tool::fromArray(['name' => 'read', 'inputSchema' => ['type' => 'object']]), fn () => $this->fail('Wrong handler ran.'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('read');
        if ($beforeRegistration) {
            $direct->load($registry);
        } else {
            $direct->handle((new CallToolRequest('read', []))->withId(1), $this->createStub(SessionInterface::class));
        }
    }

    #[TestWith(['2025-11-25', null])]
    #[TestWith(['2026-07-28', 'approved'])]
    public function testScalarOutputHonorsTheNegotiatedRevision(string $revision, ?string $structured): void
    {
        $registry = new Registry();
        $direct = $this->direct($registry, 'approved');
        $direct->load($registry);
        $session = $this->createStub(SessionInterface::class);
        $session->method('get')->willReturn($revision);
        $result = $direct->handle((new CallToolRequest('read', []))->withId(1), $session)->result;
        $this->assertFalse($result->isError);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('"approved"', $result->content[0]->text);
        $this->assertSame($structured, $result->structuredContent);
    }

    public function testValueOutsideOutputContractIsWithheld(): void
    {
        $registry = new Registry();
        $direct = $this->direct($registry, ['unexpected']);
        $direct->load($registry);
        $result = $direct->handle((new CallToolRequest('read', []))->withId(1), $this->createStub(SessionInterface::class))->result;
        $this->assertTrue($result->isError);
        $this->assertSame('tool_failed', $result->structuredContent['error']['reason']);
        $this->assertSame(0, $result->structuredContent['completedCalls']);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertStringNotContainsString('unexpected', $result->content[0]->text);
    }

    private function direct(Registry $registry, mixed $value, array $names = ['read'], ?ToolVisibilityInterface $policy = null): DirectTools
    {
        $tool = $this->createStub(ReadToolInterface::class);
        $tool->method('definition')->willReturn(new ToolDefinition('read', 'Read a label.', (object) ['type' => 'object'], (object) ['type' => 'string']));
        $tool->method('authorize')->willReturn(true);
        $tool->method('invoke')->willReturn($value);
        $other = $this->createStub(ReadToolInterface::class);
        $other->method('definition')->willReturn(new ToolDefinition('catalog_only', 'A searchable tool.', (object) ['type' => 'object']));
        $sessions = new SessionFactory([$tool, $other], visibility: $policy);
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('editor', null), 'main'));
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $identity = new SecurityIdentityProvider($tokens, $authorization, []);
        $store = new CatalogStateStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
        $requests = new RequestStack();
        $requests->push(Request::create('/mcp', 'POST'));

        return new DirectTools($names, $sessions, $identity, new CatalogTools($sessions, $identity, $store), $registry, 20, $requests);
    }
}
