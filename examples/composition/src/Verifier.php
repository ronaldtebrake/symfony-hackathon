<?php

declare(strict_types=1);

namespace Composition;

use CodeMode\ExecutionLimits;
use CodeMode\Provider\PhpToolInterface;
use CodeMode\ToolCatalog;
use CodeMode\ToolDefinition;

/** Check host traces and fixture results; never trust a guest assertion that a tool ran. */
final class Verifier
{
    public static function fixtureHash(): string
    {
        $data = new AuditDataset();

        return hash('sha256', json_encode([$data->articles(), $data->media()], \JSON_THROW_ON_ERROR));
    }

    public function check(array $report): array
    {
        $runs = $report['executions'] ?? [];
        $audit = $runs['audit'] ?? [];
        $calls = $audit['toolCalls'] ?? [];
        $expectedCalls = ['catalog.listArticles', 'catalog.getArticles', 'media.getMedia', 'catalog.listArticles', 'catalog.getArticles', 'media.getMedia', 'catalog.listArticles', 'catalog.getArticles', 'catalog.listArticles', 'catalog.getArticles'];
        $checks = [
            'referenceNotModelClaim' => 1 === ($report['version'] ?? null) && 'provider-composition' === ($report['kind'] ?? null) && false === ($report['modelCalled'] ?? null),
            'fixtureUnchanged' => self::fixtureHash() === ($report['fixtureSha256'] ?? null),
            'pinnedImageAndLimits' => 1 === preg_match('/\Asha256:[a-f0-9]{64}\z/D', $report['image'] ?? '') && get_object_vars(new ExecutionLimits(wallTimeMs: 15000, toolTimeMs: 3000, maxToolCalls: 30)) === ($report['limits'] ?? null),
            'auditCorrect' => (new AuditDataset())->expected() === ($audit['value'] ?? null) && 'succeeded' === ($audit['status'] ?? null),
            'bothProvidersExecuted' => $expectedCalls === array_column($calls, 'name') && array_fill(0, 10, 'succeeded') === array_column($calls, 'status'),
            'providerProvenance' => ['catalog.listArticles' => ['provider' => 'catalog', 'method' => 'listArticles', 'source' => 'symfony-ai', 'upstreamTool' => 'cms_list_articles'], 'catalog.getArticles' => ['provider' => 'catalog', 'method' => 'getArticles', 'source' => 'symfony-ai', 'upstreamTool' => 'cms_get_articles'], 'media.getMedia' => ['provider' => 'media', 'method' => 'getMedia', 'source' => 'mcp', 'upstreamTool' => 'cms_get_media']] === ($report['origins'] ?? null),
        ];
        foreach (['restricted-article' => 'catalog.getArticles', 'restricted-media' => 'media.getMedia', 'raw-upstream-name' => 'cms_get_media', 'undiscovered-tool' => 'media.getMedia'] as $label => $tool) {
            $run = $runs[$label] ?? [];
            $checks[$label] = 'failed' === ($run['status'] ?? null) && 'tool_denied' === ($run['reason'] ?? null) && null === ($run['value'] ?? null)
                && [$tool] === array_column($run['toolCalls'] ?? [], 'name') && ['denied'] === array_column($run['toolCalls'] ?? [], 'status');
        }
        foreach (['attempt-budget' => 'execution_limit', 'infinite-loop' => 'wall_timeout'] as $label => $reason) {
            $run = $runs[$label] ?? [];
            $checks[$label] = 'failed' === ($run['status'] ?? null) && $reason === ($run['reason'] ?? null) && null === ($run['value'] ?? null) && [] === ($run['toolCalls'] ?? null);
        }
        $checks['gvisorAndCleanup'] = 'gvisor' === ($report['runtime']['executor'] ?? null) && 7 === \count($runs);
        $interface = null;
        try {
            $definitions = array_map(static fn ($contract) => new ToolDefinition($contract['name'], $contract['description'], json_decode(json_encode($contract['inputSchema'], \JSON_THROW_ON_ERROR), false, 32, \JSON_THROW_ON_ERROR), isset($contract['outputSchema']) ? json_decode(json_encode($contract['outputSchema'], \JSON_THROW_ON_ERROR), false, 32, \JSON_THROW_ON_ERROR) : null), $report['contracts'] ?? []);
            $interface = new PhpToolInterface(['catalog' => ['listArticles' => 'catalog.listArticles', 'getArticles' => 'catalog.getArticles'], 'media' => ['getMedia' => 'media.getMedia']], new ToolCatalog($definitions));
        } catch (\Throwable) { /* Missing or invalid contracts cannot verify the emitted program. */
        }
        $checks['interfacesMatchSchemas'] = null !== $interface && $interface->stubs() === ($report['interfaces'] ?? null);
        $checks['sourceAndMetrics'] = true;
        $ids = [];
        foreach ($runs as $label => $run) {
            if ('attempt-budget' === $label) {
                $checks['gvisorAndCleanup'] = $checks['gvisorAndCleanup'] && null === ($run['runId'] ?? null);
            } else {
                $id = $run['runId'] ?? '';
                $checks['gvisorAndCleanup'] = $checks['gvisorAndCleanup'] && 'gvisor' === ($run['runtime'] ?? null)
                    && \is_string($id) && 1 === preg_match('/\Acode-mode-[a-f0-9]{32}\z/D', $id) && !\in_array($id, $ids, true) && true === ($run['containerRemoved'] ?? null);
                $ids[] = $id;
            }
            $checks['sourceAndMetrics'] = $checks['sourceAndMetrics'] && \is_string($run['php'] ?? null) && '' !== $run['php']
                && null !== $interface && $interface->wrap($run['php']) === ($run['executedPhp'] ?? null)
                && hash('sha256', $run['executedPhp'] ?? '') === ($run['sourceSha256'] ?? null) && is_numeric($run['elapsedMs'] ?? null) && $run['elapsedMs'] >= 0;
        }

        return $checks;
    }
}
