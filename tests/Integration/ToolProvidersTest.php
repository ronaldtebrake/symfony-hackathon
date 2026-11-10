<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\CodeMode\{PublishingAuthorization, PublishingTools, DemoIdentityProvider};
use App\Demo\BackendFactory;
use CodeMode\{BrokerWorker, ExecutionIdentity, ExecutionLimits, SandboxException};
use CodeMode\Provider\{McpProvider, ProviderRegistry};
use CodeMode\Symfony\Ai\ApprovedToolbox;
use CodeMode\Symfony\{CodeModeBundle, SessionFactory};
use CodeMode\Symfony\Provider\SymfonyAiProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Event\{ToolCallRequested, ToolCallSucceeded};
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\Component\DependencyInjection\{ContainerBuilder, Reference};
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ToolProvidersTest extends TestCase
{
    public function testSymfonyAdapterKeepsNativeSchemasLifecycleAndDenials(): void
    {
        $events = []; $dispatcher = new EventDispatcher();
        foreach ([ToolCallRequested::class, ToolCallSucceeded::class] as $class) {
            $dispatcher->addListener($class, static function ($event) use (&$events) { $events[] = $event::class; });
        }
        $approved = new ApprovedToolbox(new Toolbox([new PublishingTools()], eventDispatcher:$dispatcher), ['publishing_read'], new PublishingAuthorization());
        $registry = new ProviderRegistry([new SymfonyAiProvider('articles', $approved, ['read'=>'publishing_read'])]);
        $definition = $registry->catalog()->describe(['articles.read'])[0];
        self::assertEquals($approved->tools()[0]->definition()->inputSchema, $definition->inputSchema);
        self::assertEquals($approved->tools()[0]->definition()->outputSchema, $definition->outputSchema);
        $identity = new ExecutionIdentity('reader', ['articles.read_published']);
        $value = $registry->broker()->call('articles.read', (object) ['id'=>1], $identity, ['articles.read']);
        self::assertSame(1, $value->id);
        self::assertSame([ToolCallRequested::class, ToolCallSucceeded::class], $events);
        foreach ([['articles.read',['id'=>49],$identity,'tool_denied'], ['articles.read',['id'=>1],new ExecutionIdentity('stranger'),'tool_denied'], ['publishing_read',['id'=>1],$identity,'tool_denied'], ['articles.read',['id'=>1,'permissions'=>['admin']],$identity,'invalid_arguments']] as [$name,$args,$who,$reason]) {
            try { $registry->broker()->call($name, (object) $args, $who, [$name]); self::fail('Forbidden invocation reached the handler.'); }
            catch (SandboxException $error) { self::assertSame($reason, $error->reason); }
        }
        self::assertCount(2, $events, 'Denied calls must not reach the Symfony lifecycle.');
        $this->expectException(\InvalidArgumentException::class);
        new ProviderRegistry([new SymfonyAiProvider('articles', $approved, ['listArticles'=>'publishing_list'])]);
    }

    public function testMcpAdapterPreservesOriginalNamesPolicyAndUpstreamAccess(): void
    {
        $gateway = (new BackendFactory(dirname(__DIR__, 2)))->create('fixture');
        $registry = new ProviderRegistry([new McpProvider('cms', $gateway, ['articles'=>'cms_get_articles'])]);
        self::assertSame('cms_get_articles', $registry->origins()['cms.articles']['upstreamTool']);
        $worker = new BrokerWorker($registry->broker(), new ExecutionIdentity('editor', ['content.audit']), ['cms.articles'], new ExecutionLimits(toolTimeMs:3000));
        try {
            self::assertSame(11, $worker->call('cms.articles', (object) ['ids'=>[11]], BrokerWorker::now()+4)->items[0]->id);
            foreach ([['cms.articles',[49],'tool_failed'], ['cms_get_articles',[11],'tool_denied'], ['cms.delete',[11],'tool_denied']] as [$name,$ids,$reason]) {
                try { $worker->call($name, (object) ['ids'=>$ids], BrokerWorker::now()+4); self::fail('Forbidden MCP read succeeded.'); }
                catch (SandboxException $error) { self::assertSame($reason, $error->reason); }
            }
        } finally { $worker->close(); }
        $this->expectException(\InvalidArgumentException::class);
        new ProviderRegistry([new McpProvider('cms', $gateway, ['remove'=>'cms_delete_article'])]);
    }

    public function testExistingReadServicesCanUseANamespaceWithoutChangingTheirPolicy(): void
    {
        $registry = new ProviderRegistry([new \CodeMode\Symfony\Provider\ReadToolsProvider('legacy', ['read'=>new \App\CodeMode\ReadArticle()])]);
        $identity = new ExecutionIdentity('reader', ['articles.read_published']);
        self::assertSame(1, $registry->broker()->call('legacy.read', (object) ['id'=>1], $identity, ['legacy.read'])['id']);
        self::assertSame('read-service', $registry->origins()['legacy.read']['source']);
        $this->expectException(SandboxException::class);
        $registry->broker()->call('legacy.read', (object) ['id'=>49], $identity, ['legacy.read']);
    }

    public function testBundleAcceptsProviderOnlyConfigurationAndIsolatesSessions(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', dirname(__DIR__, 2).'/var/cache/test');
        $container->register('native.toolbox', Toolbox::class)->setArguments([[new PublishingTools()]]);
        $container->register('native.policy', PublishingAuthorization::class);
        $container->register('native.identity', DemoIdentityProvider::class);
        $container->register('approved', ApprovedToolbox::class)->setArguments([new Reference('native.toolbox'), ['publishing_list'], new Reference('native.policy')]);
        $container->register('provider', SymfonyAiProvider::class)->setArguments(['articles', new Reference('approved'), ['page'=>'publishing_list']]);
        (new CodeModeBundle())->getContainerExtension()->load([['identity_provider'=>'native.identity','providers'=>['provider'], 'mode'=>'php', 'image'=>'sha256:'.str_repeat('a',64),'docker_command'=>['docker']]], $container);
        $container->getDefinition(SessionFactory::class)->setPublic(true); $container->compile();
        $factory = $container->get(SessionFactory::class);
        $identity = new ExecutionIdentity('reader',['articles.read_published']);
        $session = $factory->create($identity);
        $description = $session->describe(['articles.page'])[0];
        self::assertSame('$tools->articles->page($arguments)', $description['phpInterface']['call']);
        self::assertArrayHasKey('inputSchema', $description);
        try { $factory->create($identity)->execute('return 1;'); self::fail('Discovery leaked between sessions.'); }
        catch (SandboxException $error) { self::assertSame('discovery_required', $error->reason); }
    }
}
