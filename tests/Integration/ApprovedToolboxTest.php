<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\CodeMode\PublishingAuthorization;
use App\CodeMode\PublishingTools;
use App\CodeMode\DemoIdentityProvider;
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
        $container->setParameter('kernel.build_dir', dirname(__DIR__, 2).'/var/cache/test');
        $container->register('native.tools', Toolbox::class)->setArguments([[new PublishingTools()]]);
        $container->register('native.policy', PublishingAuthorization::class);
        $container->register('native.identity', DemoIdentityProvider::class);
        (new CodeModeBundle())->getContainerExtension()->load([[
            'identity_provider' => 'native.identity', 'ai_toolbox' => 'native.tools',
            'ai_authorization' => 'native.policy', 'ai_tools' => ['publishing_list'],
            'image' => 'sha256:'.str_repeat('a', 64), 'docker_command' => ['docker'],
            'discovery' => 'compact',
        ]], $container);
        $container->getDefinition(SessionFactory::class)->setPublic(true);
        $container->compile();
        self::assertSame(['publishing_list'], array_column($container->get(SessionFactory::class)->catalog()->all(), 'name'));
        $session = $container->get(SessionFactory::class)->create($this->identity());
        self::assertSame(\CodeMode\DiscoveryMode::Compact, $session->discoveryMode());
        $compact = $session->search('publishing')[0];
        $full = $session->describe(['publishing_list'])[0];
        self::assertFalse($compact['requiresDescribe']);
        self::assertEquals($full['inputSchema'], $compact['inputSchema']);
        self::assertSame($full['php']['returns'], $compact['returns']);
    }

    public function testOnlyApprovedToolsAreAdaptedAndSymfonyLifecycleStillRuns(): void
    {
        $dispatcher = new EventDispatcher(); $events = [];
        foreach ([ToolCallRequested::class, ToolCallSucceeded::class] as $event) {
            $dispatcher->addListener($event, static function ($e) use (&$events) { $events[] = $e::class; });
        }
        $adapter = new ApprovedToolbox(new Toolbox([new PublishingTools()], eventDispatcher: $dispatcher), ['publishing_list'], new PublishingAuthorization());
        $tools = $adapter->tools();
        self::assertCount(1, $tools);
        $tool = $tools[0];
        self::assertSame('integer', $tool->definition()->inputSchema->properties->page->type);
        self::assertSame('object', $tool->definition()->outputSchema->type);
        $value = $this->broker($tool)->call('publishing_list', (object) ['page' => 1], $this->identity(), ['publishing_list']);
        self::assertCount(12, $value->items);
        self::assertSame([ToolCallRequested::class, ToolCallSucceeded::class], $events);
    }

    public function testDraftUnprivilegedUnknownAndMalformedCallsNeverReachHandler(): void
    {
        $dispatcher = new EventDispatcher(); $invocations = 0;
        $dispatcher->addListener(ToolCallRequested::class, static function () use (&$invocations) { ++$invocations; });
        $tool = (new ApprovedToolbox(new Toolbox([new PublishingTools()], eventDispatcher: $dispatcher), ['publishing_read'], new PublishingAuthorization()))->tools()[0];
        foreach ([[49, $this->identity()], [999, $this->identity()], [[], $this->identity()], [1, new ExecutionIdentity('unprivileged')]] as [$id, $identity]) {
            try { $this->broker($tool)->call('publishing_read', (object) ['id' => $id], $identity, ['publishing_read']); self::fail('Forbidden read ran.'); }
            catch (SandboxException $e) { self::assertSame('tool_denied', $e->reason); }
        }
        try { $this->broker($tool)->call('publishing_read', (object) ['id' => 1, 'permissions' => ['admin']], $this->identity(), ['publishing_read']); self::fail('Unexpected parameter admitted.'); }
        catch (SandboxException $e) { self::assertSame('invalid_arguments', $e->reason); }
        try { $this->broker($tool)->call('publishing_list', (object) ['page' => 1], $this->identity(), ['publishing_read']); self::fail('Unapproved tool ran.'); }
        catch (SandboxException $e) { self::assertSame('tool_denied', $e->reason); }
        self::assertSame(0, $invocations);
    }

    public function testPolicyIsRecheckedAndUpstreamDenialCannotBecomeArticleData(): void
    {
        $policy = new class implements ToolAuthorizationInterface {
            public int $checks = 0;
            public function isGranted(ExecutionIdentity $identity, string $tool, \stdClass $arguments): bool { return 1 === ++$this->checks; }
        };
        $tool = (new ApprovedToolbox(new Toolbox([new PublishingTools()]), ['publishing_read'], $policy))->tools()[0];
        try { $this->broker($tool)->call('publishing_read', (object) ['id' => 1], $this->identity(), ['publishing_read']); self::fail('Changed authorization was ignored.'); }
        catch (SandboxException $e) { self::assertSame('tool_failed', $e->reason); }
        self::assertSame(2, $policy->checks);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ToolCallRequested::class, static fn ($e) => $e->deny('sensitive upstream diagnostic'));
        $tool = (new ApprovedToolbox(new Toolbox([new PublishingTools()], eventDispatcher: $dispatcher), ['publishing_read'], new PublishingAuthorization()))->tools()[0];
        try { $this->broker($tool)->call('publishing_read', (object) ['id' => 1], $this->identity(), ['publishing_read']); self::fail('Denial string passed the article schema.'); }
        catch (SandboxException $e) { self::assertSame('tool_failed', $e->reason); self::assertStringNotContainsString('sensitive', $e->getMessage()); }
    }

    public function testMissingAndNonReadOnlyToolsFailClosed(): void
    {
        $mutator = new #[AsTool('delete_article', 'Delete an article')] class { public function __invoke(): string { return 'should not run'; } };
        foreach ([['missing'], ['delete_article']] as $names) {
            try { (new ApprovedToolbox(new Toolbox([$mutator]), $names, new PublishingAuthorization()))->tools(); self::fail('Unsafe catalog accepted.'); }
            catch (\LogicException) { self::assertTrue(true); }
        }
        $this->expectException(\InvalidArgumentException::class);
        new ApprovedToolbox(new Toolbox([]), [], new PublishingAuthorization());
    }

    private function identity(): ExecutionIdentity { return new ExecutionIdentity('reader', ['articles.read_published']); }
    private function broker($tool): ToolBroker { return new ToolBroker([new Tool($tool->definition()->name, $tool->definition()->inputSchema, $tool->authorize(...), $tool->invoke(...))]); }
}
