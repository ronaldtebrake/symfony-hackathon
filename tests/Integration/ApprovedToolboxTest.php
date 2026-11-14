<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\CodeMode\DemoIdentityProvider;
use App\CodeMode\PublishingAuthorization;
use App\CodeMode\PublishingTools;
use CodeMode\ExecutionIdentity;
use CodeMode\SandboxException;
use CodeMode\Symfony\Ai\ApprovedToolbox;
use CodeMode\Symfony\Ai\ToolAuthorizationInterface;
use CodeMode\Symfony\CodeModeBundle;
use CodeMode\Symfony\SessionFactory;
use CodeMode\Tool;
use CodeMode\ToolBroker;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ApprovedToolboxTest extends TestCase
{
    public function testBundleConfigurationCanUseExistingAiToolsWithoutCustomReadServices(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', \dirname(__DIR__, 2).'/var/cache/test');
        $container->register('native.tools', Toolbox::class)->setArguments([[new PublishingTools()]]);
        $container->register('native.policy', PublishingAuthorization::class);
        $container->register('native.identity', DemoIdentityProvider::class);
        (new CodeModeBundle())->getContainerExtension()->load([[
            'identity_provider' => 'native.identity', 'ai_toolbox' => 'native.tools',
            'ai_authorization' => 'native.policy', 'ai_tools' => ['publishing_list'],
            'mode' => 'php', 'image' => 'sha256:'.str_repeat('a', 64), 'docker_command' => ['docker'],
        ]], $container);
        $container->getDefinition(SessionFactory::class)->setPublic(true);
        $container->compile();
        $this->assertSame(['publishing_list'], array_column($container->get(SessionFactory::class)->catalog()->all(), 'name'));
        $session = $container->get(SessionFactory::class)->create($this->identity());
        $this->assertSame(\CodeMode\DiscoveryMode::Full, $session->discoveryMode());
        $this->assertSame(['name', 'description'], array_keys($session->search('publishing')[0]));
        $full = $session->describe(['publishing_list'])[0];
        $this->assertArrayHasKey('inputSchema', $full);
        $this->assertArrayHasKey('outputSchema', $full);
        $this->assertArrayHasKey('php', $full);
        $this->assertNull(\CodeMode\DiscoveryMode::tryFrom('compact'));
    }

    public function testOnlyApprovedToolsAreAdaptedAndSymfonyLifecycleStillRuns(): void
    {
        $dispatcher = new EventDispatcher();
        $events = [];
        foreach ([ToolCallRequested::class, ToolCallSucceeded::class] as $event) {
            $dispatcher->addListener($event, static function ($e) use (&$events) { $events[] = $e::class; });
        }
        $adapter = new ApprovedToolbox(new Toolbox([new PublishingTools()], eventDispatcher: $dispatcher), ['publishing_list'], new PublishingAuthorization());
        $tools = $adapter->tools();
        $this->assertCount(1, $tools);
        $tool = $tools[0];
        $this->assertSame('integer', $tool->definition()->inputSchema->properties->page->type);
        $this->assertSame('object', $tool->definition()->outputSchema->type);
        $value = $this->broker($tool)->call('publishing_list', (object) ['page' => 1], $this->identity(), ['publishing_list']);
        $this->assertCount(12, $value->items);
        $this->assertSame([ToolCallRequested::class, ToolCallSucceeded::class], $events);
    }

    public function testDraftUnprivilegedUnknownAndMalformedCallsNeverReachHandler(): void
    {
        $dispatcher = new EventDispatcher();
        $invocations = 0;
        $dispatcher->addListener(ToolCallRequested::class, static function () use (&$invocations) { ++$invocations; });
        $tool = (new ApprovedToolbox(new Toolbox([new PublishingTools()], eventDispatcher: $dispatcher), ['publishing_read'], new PublishingAuthorization()))->tools()[0];
        foreach ([[49, $this->identity()], [999, $this->identity()], [[], $this->identity()], [1, new ExecutionIdentity('unprivileged')]] as [$id, $identity]) {
            try {
                $this->broker($tool)->call('publishing_read', (object) ['id' => $id], $identity, ['publishing_read']);
                $this->fail('Forbidden read ran.');
            } catch (SandboxException $e) {
                $this->assertSame('tool_denied', $e->reason);
            }
        }
        try {
            $this->broker($tool)->call('publishing_read', (object) ['id' => 1, 'permissions' => ['admin']], $this->identity(), ['publishing_read']);
            $this->fail('Unexpected parameter admitted.');
        } catch (SandboxException $e) {
            $this->assertSame('invalid_arguments', $e->reason);
        }
        try {
            $this->broker($tool)->call('publishing_list', (object) ['page' => 1], $this->identity(), ['publishing_read']);
            $this->fail('Unapproved tool ran.');
        } catch (SandboxException $e) {
            $this->assertSame('tool_denied', $e->reason);
        }
        $this->assertSame(0, $invocations);
    }

    public function testPolicyIsRecheckedAndUpstreamDenialCannotBecomeArticleData(): void
    {
        $policy = new class implements ToolAuthorizationInterface {
            public int $checks = 0;

            public function isGranted(ExecutionIdentity $identity, string $tool, \stdClass $arguments): bool
            {
                return 1 === ++$this->checks;
            }
        };
        $tool = (new ApprovedToolbox(new Toolbox([new PublishingTools()]), ['publishing_read'], $policy))->tools()[0];
        try {
            $this->broker($tool)->call('publishing_read', (object) ['id' => 1], $this->identity(), ['publishing_read']);
            $this->fail('Changed authorization was ignored.');
        } catch (SandboxException $e) {
            $this->assertSame('tool_failed', $e->reason);
        }
        $this->assertSame(2, $policy->checks);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ToolCallRequested::class, static fn ($e) => $e->deny('sensitive upstream diagnostic'));
        $tool = (new ApprovedToolbox(new Toolbox([new PublishingTools()], eventDispatcher: $dispatcher), ['publishing_read'], new PublishingAuthorization()))->tools()[0];
        try {
            $this->broker($tool)->call('publishing_read', (object) ['id' => 1], $this->identity(), ['publishing_read']);
            $this->fail('Denial string passed the article schema.');
        } catch (SandboxException $e) {
            $this->assertSame('tool_failed', $e->reason);
            $this->assertStringNotContainsString('sensitive', $e->getMessage());
        }
    }

    public function testMissingAndNonReadOnlyToolsFailClosed(): void
    {
        $mutator = new #[AsTool('delete_article', 'Delete an article')] class {
            public function __invoke(): string
            {
                return 'should not run';
            }
        };
        foreach ([['missing'], ['delete_article']] as $names) {
            try {
                (new ApprovedToolbox(new Toolbox([$mutator]), $names, new PublishingAuthorization()))->tools();
                $this->fail('Unsafe catalog accepted.');
            } catch (\LogicException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new ApprovedToolbox(new Toolbox([]), [], new PublishingAuthorization());
    }

    private function identity(): ExecutionIdentity
    {
        return new ExecutionIdentity('reader', ['articles.read_published']);
    }

    private function broker($tool): ToolBroker
    {
        return new ToolBroker([new Tool($tool->definition()->name, $tool->definition()->inputSchema, $tool->authorize(...), $tool->invoke(...))]);
    }
}
