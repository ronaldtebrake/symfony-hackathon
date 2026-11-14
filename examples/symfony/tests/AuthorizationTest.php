<?php

declare(strict_types=1);

use Adoption\ArticleCatalog;
use Adoption\ArticleTool;
use Adoption\LocalReader;
use Adoption\ReadPolicy;
use CodeMode\ExecutionIdentity;
use CodeMode\SandboxException;
use CodeMode\Symfony\Ai\ApprovedToolbox;
use CodeMode\Tool;
use CodeMode\ToolBroker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Toolbox;

final class AuthorizationTest extends TestCase
{
    public function testAllowedReadUsesOriginalSymfonyToolbox(): void
    {
        $catalog = new ArticleCatalog();
        $adapter = new ApprovedToolbox(new Toolbox([new ArticleTool($catalog)]), ['publication_read'], new ReadPolicy($catalog));
        $tools = $adapter->tools();
        $this->assertCount(1, $tools);
        $this->assertSame('publication_read', $tools[0]->definition()->name);
        $tool = $tools[0];
        $broker = new ToolBroker([new Tool($tool->definition()->name, $tool->definition()->inputSchema, $tool->authorize(...), $tool->invoke(...))]);
        $result = $broker->call('publication_read', (object) ['id' => 101], (new LocalReader())->identity(), ['publication_read']);
        $this->assertEquals((object) ['id' => 101, 'title' => 'A Symfony application owns its tools'], $result);
    }

    #[DataProvider('deniedCalls')]
    public function testDeniedCallsNeverReachHandler(array $arguments, string $name, array $permissions): void
    {
        $catalog = new ArticleCatalog();
        $policy = new ReadPolicy($catalog);
        $invocations = 0;
        $tool = (new ApprovedToolbox(new Toolbox([new ArticleTool($catalog)]), ['publication_read'], $policy))->tools()[0];
        $broker = new ToolBroker([new Tool('publication_read', $tool->definition()->inputSchema,
            static fn (ExecutionIdentity $identity, stdClass $args): bool => $policy->isGranted($identity, 'publication_read', $args),
            static function () use (&$invocations): never {
                ++$invocations;
                throw new RuntimeException('Handler must not run.');
            })]);
        try {
            $broker->call($name, (object) $arguments, new ExecutionIdentity('test-reader', $permissions), ['publication_read']);
            $this->fail('Expected a denied call.');
        } catch (SandboxException $error) {
            $this->assertSame('tool_denied', $error->reason);
            $this->assertSame(0, $invocations);
        }
    }

    public static function deniedCalls(): iterable
    {
        $permission = ['publication.read_published'];
        yield 'private' => [['id' => 202], 'publication_read', $permission];
        yield 'missing' => [['id' => 999], 'publication_read', $permission];
        yield 'no host permission' => [['id' => 101], 'publication_read', []];
        yield 'unknown tool' => [['id' => 101], 'publication_count', $permission];
        yield 'missing argument' => [[], 'publication_read', $permission];
        yield 'string ID' => [['id' => '101'], 'publication_read', $permission];
        yield 'array ID' => [['id' => [101]], 'publication_read', $permission];
        yield 'null ID' => [['id' => null], 'publication_read', $permission];
        yield 'guest permission' => [['id' => 202, 'permissions' => $permission], 'publication_read', $permission];
    }

    public function testDataBoundaryAlsoRejectsPrivateContent(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No permitted article.');
        (new ArticleTool(new ArticleCatalog()))->read(202);
    }
}
