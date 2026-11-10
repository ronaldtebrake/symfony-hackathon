<?php

declare(strict_types=1);

namespace CodeMode\Symfony;

use CodeMode\DockerEngine;
use CodeMode\DiscoveryMode;
use CodeMode\ExecutionLimits;
use CodeMode\ExecutionProfile;
use CodeMode\Symfony\Command\DoctorCommand;
use CodeMode\Symfony\Command\ServeCommand;
use CodeMode\Symfony\Ai\ApprovedToolbox;
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
            ->scalarNode('identity_provider')->defaultValue(\CodeMode\Symfony\Http\SecurityIdentityProvider::class)->cannotBeEmpty()->end()
            ->arrayNode('http')->canBeEnabled()->children()
                ->arrayNode('permissions')->useAttributeAsKey('permission')->scalarPrototype()->cannotBeEmpty()->end()->end()
            ->end()->end()
            ->arrayNode('tools')->scalarPrototype()->cannotBeEmpty()->end()->end()
            ->arrayNode('providers')->scalarPrototype()->cannotBeEmpty()->end()->end()
            ->scalarNode('ai_toolbox')->defaultNull()->end()
            ->scalarNode('ai_authorization')->defaultNull()->end()
            ->arrayNode('ai_tools')->scalarPrototype()->cannotBeEmpty()->end()->end()
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
        if ($config['http']['enabled']) {
            if ('catalog' !== $config['mode']) { throw new \InvalidArgumentException('The HTTP integration exposes the JSON catalog only.'); }
            $services->set(\CodeMode\Symfony\Http\SecurityIdentityProvider::class)->args([
                new Reference('security.token_storage'), new Reference('security.authorization_checker'), $config['http']['permissions'],
            ]);
            // Separate from kernel.cache_dir: rebuilding the container must not reset budgets.
            $services->set('code_mode.http.cache', \Symfony\Component\Cache\Adapter\FilesystemAdapter::class)
                ->args(['code_mode', 0, '%kernel.project_dir%/var/code-mode/budgets']);
            $services->set('code_mode.http.lock_store', \Symfony\Component\Lock\Store\FlockStore::class)
                ->args(['%kernel.project_dir%/var/code-mode/locks']);
            $services->set('code_mode.http.lock_factory', \Symfony\Component\Lock\LockFactory::class)->args([new Reference('code_mode.http.lock_store')]);
            $services->set(\CodeMode\Symfony\Http\CatalogStateStore::class)->args([new Reference('code_mode.http.cache'), new Reference('code_mode.http.lock_factory')]);
            $services->set(\CodeMode\Symfony\Http\CatalogTools::class)->autoconfigure()->args([
                new Reference(SessionFactory::class), new Reference(\CodeMode\Symfony\Http\SecurityIdentityProvider::class), new Reference(\CodeMode\Symfony\Http\CatalogStateStore::class),
            ]);
            $services->set(\CodeMode\Symfony\Command\ResetBudgetCommand::class)->args([new Reference(\CodeMode\Symfony\Http\CatalogStateStore::class)])->tag('console.command');
        } elseif (\CodeMode\Symfony\Http\SecurityIdentityProvider::class === $config['identity_provider']) {
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
        if ([] === $config['tools'] && [] === $config['providers'] && !$hasAi) {
            throw new \InvalidArgumentException('Configure at least one approved read tool.');
        }
        $profile = ExecutionProfile::from($config['executor']);
        // Batch mode does not resolve runner configuration or contact Docker.
        $image = 'php' === $config['mode'] ? $config['image'] : '';
        $services->set('code_mode.docker', DockerEngine::class)->args(['php' === $config['mode'] ? $config['docker_command'] : ['docker']]);
        $services->set('code_mode.limits', ExecutionLimits::class)->args(['$wallTimeMs' => $config['wall_time_ms'], '$toolTimeMs' => $config['tool_time_ms'], '$maxToolCalls' => $config['max_tool_calls']]);
        $services->set(SessionFactory::class)->args([array_map(static fn (string $id) => new Reference($id), $config['tools']), $image, new Reference('code_mode.docker'), new Reference('code_mode.limits'), $hasAi ? new Reference('code_mode.approved_ai_toolbox') : null, $profile, DiscoveryMode::from($config['discovery']), array_map(static fn (string $id) => new Reference($id), $config['providers'])]);
        $services->set(ServeCommand::class)->args([new Reference(SessionFactory::class), new Reference($config['identity_provider']), $config['mode']])->tag('console.command');
        $services->set(DoctorCommand::class)->args([$image, new Reference('code_mode.docker'), new Reference(SessionFactory::class), new Reference('code_mode.limits'), $config['mode']])->tag('console.command');
    }
}
