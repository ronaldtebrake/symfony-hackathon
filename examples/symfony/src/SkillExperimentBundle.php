<?php

declare(strict_types=1);

namespace Adoption;

use CodeMode\Symfony\Http\SecurityIdentityProvider;
use CodeMode\Symfony\Security\VoterToolPolicy;
use CodeMode\Symfony\SessionFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\KernelEvents;

/** Registered only by the disposable consumer of the pinned PHP SDK draft. */
final class SkillExperimentBundle extends Bundle implements CompilerPassInterface
{
    public function build(ContainerBuilder $container): void
    {
        $container->register(SkillPresentation::class)->setArguments([
            new Reference(SessionFactory::class),
            new Reference(SecurityIdentityProvider::class),
            new Reference(VoterToolPolicy::class),
            '%kernel.project_dir%',
        ])->addTag('kernel.event_listener', ['event' => KernelEvents::RESPONSE, 'method' => 'secureResponse', 'priority' => -128]);
        $container->addCompilerPass($this);
    }

    public function process(ContainerBuilder $container): void
    {
        // Rebuild the configured SDK server from the current caller's view on each request.
        // The draft builder and its registry both retain registered files once built.
        foreach (['mcp.server.catalog.controller', 'mcp.server.catalog', 'mcp.server.catalog.builder', 'mcp.server.catalog.registry'] as $id) {
            $container->getDefinition($id)->setShared(false);
        }
        $container->getDefinition('mcp.server.catalog.builder')->setConfigurator([new Reference(SkillPresentation::class), 'configure']);
    }
}
