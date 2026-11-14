<?php

declare(strict_types=1);

namespace CodeMode\Symfony;

use CodeMode\DiscoveryMode;
use CodeMode\DockerEngine;
use CodeMode\ExecutionLimits;
use CodeMode\ExecutionProfile;
use CodeMode\Symfony\Ai\ApprovedToolbox;
use CodeMode\Symfony\Command\DoctorCommand;
use CodeMode\Symfony\Command\ServeCommand;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class CodeModeBundle extends AbstractBundle
{
    protected string $extensionAlias = 'code_mode';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->enumNode('executor')->values(['gvisor'])->defaultValue('gvisor')->end()
            ->enumNode('mode')->values(['php', 'batch', 'catalog'])->defaultValue('catalog')->end()
            ->enumNode('discovery')->values(['full'])->defaultValue('full')->end()
            ->scalarNode('image')->defaultValue('%env(CODE_MODE_IMAGE)%')->end()
            ->variableNode('docker_command')->defaultValue('%env(json:CODE_MODE_DOCKER_COMMAND)%')->end()
            ->scalarNode('identity_provider')->defaultValue(Http\SecurityIdentityProvider::class)->cannotBeEmpty()->end()
            ->scalarNode('visibility_policy')->defaultNull()->end()
            ->arrayNode('http')->canBeEnabled()->children()
                ->arrayNode('permissions')->useAttributeAsKey('permission')->scalarPrototype()->cannotBeEmpty()->end()->end()
            ->end()->end()
            ->arrayNode('tools')->scalarPrototype()->cannotBeEmpty()->end()->end()
            ->arrayNode('providers')->scalarPrototype()->cannotBeEmpty()->end()->end()
            ->scalarNode('ai_toolbox')->defaultNull()->end()
            ->scalarNode('ai_authorization')->defaultNull()->end()
            ->arrayNode('ai_tool_services')->scalarPrototype()->cannotBeEmpty()->end()->end()
            ->arrayNode('ai_tools')->scalarPrototype()->cannotBeEmpty()->end()->end()
            ->arrayNode('voter_rules')->useAttributeAsKey('tool')->arrayPrototype()->children()
                ->scalarNode('discover')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('execute')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('subject')->defaultNull()->end()
            ->end()->end()->end()
            ->booleanNode('profiling')->defaultFalse()->end()
            ->integerNode('wall_time_ms')->min(100)->max(30000)->defaultValue(5000)->end()
            ->integerNode('tool_time_ms')->min(50)->max(30000)->defaultValue(1000)->end()
            ->integerNode('max_tool_calls')->min(0)->max(100)->defaultValue(10)->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Defaults let the application boot without a configured runner. Doctor reports what's missing.
        $builder->setParameter('env(CODE_MODE_IMAGE)', '');
        $builder->setParameter('env(CODE_MODE_DOCKER_COMMAND)', '["docker"]');
        $services = $container->services();
        if (class_exists(\Symfony\Bundle\MakerBundle\Maker\AbstractMaker::class)) {
            $registrationError = match (true) {
                !$config['http']['enabled'] || 'catalog' !== $config['mode'] => 'Enable the HTTP JSON catalog before generating native voter-based tools.',
                null !== $config['ai_toolbox'] => 'A custom ai_toolbox requires manual registration; migrate to ai_tool_services before using this maker.',
                null === $config['visibility_policy'] && ([] !== $config['tools'] || [] !== $config['providers']
                    || [] !== array_diff($config['ai_tools'], array_keys($config['voter_rules']))) => 'Configure visibility_policy or voter_rules for existing tools first, so generation cannot change their discovery policy.',
                default => null,
            };
            $services->set(Maker\MakeCodeModeTool::class)->args([$registrationError])->tag('maker.command');
        }
        if ($config['profiling']) {
            $services->set(Profiler\CatalogTrace::class)->tag('kernel.reset', ['method' => 'reset']);
            $services->set(Profiler\CatalogDataCollector::class)->args([new Reference(Profiler\CatalogTrace::class)])
                ->tag('data_collector', ['id' => 'code_mode', 'template' => '@CodeMode/Collector/catalog.html.twig']);
            if (class_exists(\Symfony\AI\Agent\Toolbox\Event\ToolCallRequested::class)) {
                $services->set(Profiler\ToolEventSubscriber::class)->args([new Reference(Profiler\CatalogTrace::class)])->tag('kernel.event_subscriber');
            }
        }
        if ([] !== $config['voter_rules']) {
            $services->set(Security\VoterToolPolicy::class)->args([
                new Reference('security.token_storage'), new Reference('security.authorization_checker'), $config['voter_rules'],
            ]);
            $config['ai_authorization'] ??= Security\VoterToolPolicy::class;
            $config['visibility_policy'] ??= Security\VoterToolPolicy::class;
        }
        if ([] !== $config['ai_tool_services']) {
            if (null !== $config['ai_toolbox']) {
                throw new \InvalidArgumentException('Choose ai_toolbox or ai_tool_services, not both.');
            }
            if (!class_exists(\Symfony\AI\Agent\Toolbox\Toolbox::class)) {
                throw new \LogicException('Install symfony/ai-agent to register native tools.');
            }
            $services->set('code_mode.native_toolbox', \Symfony\AI\Agent\Toolbox\Toolbox::class)->args([
                '$tools' => array_map(static fn (string $id) => new Reference($id), $config['ai_tool_services']),
                '$eventDispatcher' => new Reference('event_dispatcher'),
            ])->tag('ai.toolbox');
            $config['ai_toolbox'] = 'code_mode.native_toolbox';
        }
        if ($config['http']['enabled']) {
            if ('catalog' !== $config['mode']) {
                throw new \InvalidArgumentException('The HTTP integration exposes the JSON catalog only.');
            }
            $services->set(Http\SecurityIdentityProvider::class)->args([
                new Reference('security.token_storage'), new Reference('security.authorization_checker'), $config['http']['permissions'],
            ]);
            // Separate from kernel.cache_dir: rebuilding the container must not reset budgets.
            $services->set('code_mode.http.cache', \Symfony\Component\Cache\Adapter\FilesystemAdapter::class)
                ->args(['code_mode', 0, '%kernel.project_dir%/var/code-mode/budgets']);
            $services->set('code_mode.http.lock_store', \Symfony\Component\Lock\Store\FlockStore::class)
                ->args(['%kernel.project_dir%/var/code-mode/locks']);
            $services->set('code_mode.http.lock_factory', \Symfony\Component\Lock\LockFactory::class)->args([new Reference('code_mode.http.lock_store')]);
            $services->set(Http\CatalogStateStore::class)->args([new Reference('code_mode.http.cache'), new Reference('code_mode.http.lock_factory')]);
            $services->set(Http\CatalogTools::class)->autoconfigure()->args([
                new Reference(SessionFactory::class), new Reference(Http\SecurityIdentityProvider::class), new Reference(Http\CatalogStateStore::class),
                $config['profiling'] ? new Reference(Profiler\CatalogTrace::class) : null,
            ]);
            $services->set(Command\ResetBudgetCommand::class)->args([new Reference(Http\CatalogStateStore::class)])->tag('console.command');
        } elseif (Http\SecurityIdentityProvider::class === $config['identity_provider']) {
            throw new \InvalidArgumentException('Enable HTTP with Symfony Security, or configure an explicit identity_provider for STDIO.');
        }
        $hasAi = null !== $config['ai_toolbox'] || null !== $config['ai_authorization'] || [] !== $config['ai_tools'];
        if ($hasAi) {
            if (null === $config['ai_toolbox'] || null === $config['ai_authorization'] || [] === $config['ai_tools']) {
                throw new \InvalidArgumentException('Configure ai_toolbox, ai_authorization and an explicit ai_tools list together.');
            }
            if (!interface_exists(\Symfony\AI\Agent\Toolbox\ToolboxInterface::class)) {
                throw new \LogicException('Install symfony/ai-agent to adapt Symfony AI tools.');
            }
            $services->set('code_mode.approved_ai_toolbox', ApprovedToolbox::class)->args([new Reference($config['ai_toolbox']), $config['ai_tools'], new Reference($config['ai_authorization'])]);
        }
        // An empty installation can boot to run the maker. SessionFactory and doctor
        // still reject execution until at least one explicitly approved tool exists.
        $profile = ExecutionProfile::from($config['executor']);
        // Batch mode does not resolve runner configuration or contact Docker.
        $image = 'php' === $config['mode'] ? $config['image'] : '';
        $services->set('code_mode.docker', DockerEngine::class)->args(['php' === $config['mode'] ? $config['docker_command'] : ['docker']]);
        $services->set('code_mode.limits', ExecutionLimits::class)->args(['$wallTimeMs' => $config['wall_time_ms'], '$toolTimeMs' => $config['tool_time_ms'], '$maxToolCalls' => $config['max_tool_calls']]);
        $services->set(SessionFactory::class)->args([array_map(static fn (string $id) => new Reference($id), $config['tools']), $image, new Reference('code_mode.docker'), new Reference('code_mode.limits'), $hasAi ? new Reference('code_mode.approved_ai_toolbox') : null, $profile, DiscoveryMode::from($config['discovery']), array_map(static fn (string $id) => new Reference($id), $config['providers']), null === $config['visibility_policy'] ? null : new Reference($config['visibility_policy'])]);
        $services->set(ServeCommand::class)->args([new Reference(SessionFactory::class), new Reference($config['identity_provider']), $config['mode']])->tag('console.command');
        $services->set(DoctorCommand::class)->args([$image, new Reference('code_mode.docker'), new Reference(SessionFactory::class), new Reference('code_mode.limits'), $config['mode']])->tag('console.command');
    }
}
