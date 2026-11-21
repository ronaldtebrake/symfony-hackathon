<?php

declare(strict_types=1);

namespace Adoption;

use CodeMode\Symfony\Profiler\CatalogDataCollector;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Twig\Attribute\AsTwigFunction;

final class RecordingProfilePanel
{
    public function __construct(private readonly Profiler $profiler)
    {
    }

    #[AsEventListener]
    public function __invoke(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if ('_profiler' !== $request->attributes->get('_route') || $request->query->has('panel')) {
            return;
        }
        $token = $request->attributes->getString('token');
        if ('latest' === $token) {
            $type = $request->query->getString('type', 'request');
            $latest = $this->profiler->find(null, null, 1, null, null, null, filter: static fn (array $row): bool => $type === $row['virtual_type']);
            $token = $latest[0]['token'] ?? '';
        }

        // Private MCP recordings deliberately omit Symfony's request collector.
        $profile = $this->profiler->loadProfile($token);
        if ($profile?->hasCollector('code_mode') && !$profile->hasCollector('request')) {
            $request->attributes->set('token', $token);
            $request->query->set('panel', $profile->hasCollector('mcp') ? 'mcp' : 'code_mode');
        }
    }

    /** Index the recorded operations by their native HTTP profile token. */
    #[AsTwigFunction('recorded_mcp_operations')]
    public function operations(?string $url, array $tokens): array
    {
        if (!preg_match('~\Ahttp://127\.0\.0\.1:[0-9]{1,5}/mcp\z~D', $url ?? '') || \count($tokens) > 20) {
            return [];
        }
        $steps = [];
        foreach ($tokens as $row) {
            if ($row['url'] !== $url || 'POST' !== $row['method'] || 'request' !== $row['virtual_type']) {
                continue;
            }
            $collectors = $this->profiler->loadProfile($row['token'])?->getCollectors() ?? [];
            if (isset($collectors['request']) || !(($collectors['code_mode'] ?? null) instanceof CatalogDataCollector)) {
                continue;
            }
            foreach ($collectors['code_mode']->getOperations() as $operation) {
                $name = $operation['operation'];
                $discovery = \in_array($name, ['tools/list', 'groups/list', 'groups/get'], true);
                $groups = $operation['groupDiscovery'] ?? [];
                $targets = match ($name) {
                    'search_tools' => isset($groups['selectedGroup']) ? [$groups['selectedGroup']] : array_column($groups['groupSummaries'] ?? [], 'name'),
                    'execute_tools' => array_column(array_filter($operation['calls'], static fn (array $call): bool => \in_array($call['status'], ['succeeded', 'denied'], true)), 'name'),
                    default => [],
                };
                $steps[$row['token']][] = ['panel' => isset($collectors['mcp']) ? 'mcp' : 'code_mode', 'method' => $discovery ? $name : 'tools/call',
                    'name' => $discovery ? ($operation['page']['selectedGroup'] ?? null) : $name, 'targets' => $targets,
                    'openedGroup' => isset($groups['selectedGroup']), 'status' => $operation['status']];
            }
        }

        return $steps;
    }
}
