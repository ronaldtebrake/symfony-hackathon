<?php

declare(strict_types=1);

namespace CodeMode\Symfony;

use CodeMode\DockerEngine;
use CodeMode\ExecutionLimits;
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
            ->scalarNode('image')->defaultValue('%env(CODE_MODE_IMAGE)%')->end()
            ->variableNode('docker_command')->defaultValue('%env(json:CODE_MODE_DOCKER_COMMAND)%')->end()
            ->scalarNode('identity_provider')->isRequired()->cannotBeEmpty()->end()
            ->arrayNode('tools')->requiresAtLeastOneElement()->isRequired()->scalarPrototype()->cannotBeEmpty()->end()->end()
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
        $services->set('code_mode.docker', DockerEngine::class)->args([$config['docker_command']]);
        $services->set('code_mode.limits', ExecutionLimits::class)->args(['$wallTimeMs' => $config['wall_time_ms'], '$toolTimeMs' => $config['tool_time_ms'], '$maxToolCalls' => $config['max_tool_calls']]);
        $services->set(SessionFactory::class)->args([array_map(static fn (string $id) => new Reference($id), $config['tools']), $config['image'], new Reference('code_mode.docker'), new Reference('code_mode.limits')]);
        $services->set(ServeCommand::class)->args([new Reference(SessionFactory::class), new Reference($config['identity_provider'])])->tag('console.command');
        $services->set(DoctorCommand::class)->args([$config['image'], new Reference('code_mode.docker'), new Reference(SessionFactory::class), new Reference('code_mode.limits')])->tag('console.command');
    }
}
