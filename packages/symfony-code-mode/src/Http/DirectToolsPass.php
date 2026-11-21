<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Http;

use CodeMode\Symfony\SessionFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Attach the direct entries exclusively to the named MCP servers that expose this catalog. */
final class DirectToolsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('code_mode.http.direct_tools') || [] === $names = $container->getParameter('code_mode.http.direct_tools')) {
            return;
        }
        $attached = false;
        foreach ($container->findTaggedServiceIds('mcp.server.builder') as $id => $tags) {
            $server = $tags[0]['server'] ?? null;
            if (!\is_string($server) || !$container->hasDefinition('mcp.server.'.$server.'.controller')) {
                continue;
            }
            $builder = $container->getDefinition($id);
            $registry = $pageSize = null;
            $servesCatalog = false;
            $registered = [];
            foreach ($builder->getMethodCalls() as [$method, $args]) {
                if ('setRegistry' === $method) {
                    $registry = $args[0];
                } elseif ('setPaginationLimit' === $method) {
                    $pageSize = $args[0];
                } elseif ('addTool' === $method) {
                    $registered[] = $args[1];
                    $servesCatalog = $servesCatalog || [CatalogTools::class, 'search'] === $args[0];
                }
            }
            if (!$servesCatalog) {
                continue;
            }
            if (null === $registry || null === $pageSize) {
                throw new \LogicException('The MCP catalog builder must configure its registry and pagination limit.');
            }
            if (array_intersect($names, $registered)) {
                throw new \LogicException('A direct catalog tool is also registered as a separate MCP handler on this server.');
            }
            $handlerId = $id.'.code_mode_direct_tools';
            $container->register($handlerId, DirectTools::class)->setArguments([
                $names, new Reference(SessionFactory::class), new Reference(SecurityIdentityProvider::class), new Reference(CatalogTools::class), $registry, $pageSize, new Reference('request_stack'),
            ]);
            $builder->addMethodCall('addLoader', [new Reference($handlerId)]);
            $builder->addMethodCall('addRequestHandler', [new Reference($handlerId)]);
            $attached = true;
        }
        if (!$attached) {
            throw new \LogicException('HTTP direct_tools requires a configured MCP server exposing '.CatalogTools::class.'.');
        }
    }
}
