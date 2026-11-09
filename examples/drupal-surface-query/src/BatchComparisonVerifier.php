<?php

declare(strict_types=1);

namespace SurfaceProof;

use CodeMode\{ExecutionLimits, ToolCatalog, ToolDefinition};
use CodeMode\Provider\PhpToolInterface;

final class BatchComparisonVerifier
{
    public static function check(array $report): array
    {
        $before = $report['before'] ?? []; $after = $report['after'] ?? []; $revoked = $report['revoked'] ?? [];
        $expected = Verifier::expected($before);
        $matches = array_values($before['expected'] ?? []);
        usort($matches, static fn ($a, $b) => $a['id'] <=> $b['id']);
        $checks = [
            'referenceScope' => 'data-surface-batch-comparison' === ($report['kind'] ?? null) && false === ($report['modelCalled'] ?? null),
            'noCaptureErrors' => !array_intersect(['error', 'restorationFailed', 'snapshotFailed'], array_keys($report)),
            'limitsUnchanged' => get_object_vars(new ExecutionLimits(wallTimeMs: 15000, toolTimeMs: 3000, maxToolCalls: 2)) + ['executionAttempts' => 2] === ($report['limits'] ?? null),
            'accessChecked' => Verifier::fixture($before) && Verifier::fixture($after) && Verifier::fixture($revoked, false),
            'fixtureUnchanged' => isset($before['contentHash']) && $before['contentHash'] === ($after['contentHash'] ?? null) && $before['contentHash'] === ($revoked['contentHash'] ?? null)
                && ($before['account'] ?? null) === ($after['account'] ?? null) && count($before['sources'] ?? []) === 6
                && ($before['sources'] ?? null) === ($after['sources'] ?? null) && ($before['sources'] ?? null) === ($revoked['sources'] ?? null),
            'approvedProvider' => ['drupal.search' => ['provider' => 'drupal', 'method' => 'search', 'source' => 'mcp', 'upstreamTool' => Connection::WIRE_TOOL]] === ($report['origins'] ?? null),
        ];
        $modes = 'paired' === ($report['mode'] ?? null) ? ['batch', 'php'] : ['batch'];
        $checks['requestedPathsPresent'] = in_array($report['mode'] ?? null, ['paired', 'batch-only'], true) && $modes === array_keys($report['runs'] ?? []);
        $interface = null;
        try {
            $definitions = array_map(static fn ($d) => new ToolDefinition($d['name'], $d['description'], json_decode(json_encode($d['inputSchema'], JSON_THROW_ON_ERROR)), isset($d['outputSchema']) ? json_decode(json_encode($d['outputSchema'], JSON_THROW_ON_ERROR)) : null), $report['contracts'] ?? []);
            $interface = new PhpToolInterface(['drupal' => ['search' => 'drupal.search']], new ToolCatalog($definitions));
        } catch (\Throwable) {}
        $checks['contractsPresent'] = null !== $interface && ['drupal.search'] === array_column($report['contracts'] ?? [], 'name');
        foreach ($modes as $mode) {
            $runs = $report['runs'][$mode] ?? [];
            $response = $runs['composition']['response'] ?? [];
            $batch = 'batch' === $mode;
            $batchValues = array_column($response['results'] ?? [], 'value');
            $checks[$mode.':composition'] = $batch
                ? count($batchValues) === 2 && Verifier::sorted($batchValues[0]) === $expected['orchard'] && Verifier::sorted($batchValues[1]) === $expected['garden']
                : ($response['value'] ?? null) === ['matches' => $matches, 'count' => 3, 'returnedBySearch' => ['orchard' => 2, 'garden' => 2]];
            $checks[$mode.':empty'] = ($batch ? ($runs['empty']['response']['results'][0]['value'] ?? null) : ($runs['empty']['response']['value'] ?? null)) === ['matches' => [], 'count' => 0];
            $restricted = $runs['restricted']['response'] ?? [];
            $checks[$mode.':restrictedExcluded'] = ($batch ? array_column($restricted['results'] ?? [], 'value') : array_values($restricted['value'] ?? [])) === [['matches' => [], 'count' => 0], ['matches' => [], 'count' => 0]];
            $checks[$mode.':restored'] = Verifier::sorted($batch ? ($runs['restored']['response']['results'][0]['value'] ?? null) : ($runs['restored']['response']['value'] ?? null)) === $expected['orchard'];
            foreach (['invalid-limit' => 'invalid_arguments', 'unknown-input' => 'tool_failed', 'raw-name' => 'tool_denied', 'revoked' => 'tool_failed', 'attempt-budget' => 'execution_limit'] as $label => $reason) {
                $checks[$mode.':'.$label] = $reason === ($runs[$label]['response']['error']['reason'] ?? null) && !array_key_exists('value', $runs[$label]['response'] ?? []) && !array_key_exists('results', $runs[$label]['response'] ?? []);
            }
            $checks[$mode.':sameRecoveryRequest'] = isset($runs['revoked']['request']) && $runs['revoked']['request'] === ($runs['restored']['request'] ?? null);
            $checks[$mode.':attemptNotAllocated'] = !isset($runs['attempt-budget']['runId']) && [] === ($runs['attempt-budget']['toolCalls'] ?? null);
            $checks[$mode.':evidence'] = count($runs) === 9;
            $ids = [];
            foreach (['composition' => ['succeeded', 2, 2], 'empty' => ['succeeded', 1, 1], 'restricted' => ['succeeded', 2, 2],
                'invalid-limit' => ['incomplete', 1, 0], 'unknown-input' => ['incomplete', 1, 1], 'raw-name' => ['denied', 1, 0],
                'revoked' => ['incomplete', 1, 1], 'restored' => ['succeeded', 1, 1]] as $label => [$status, $count, $upstreamCount]) {
                $run = $runs[$label] ?? [];
                $name = 'raw-name' === $label ? Connection::WIRE_TOOL : 'drupal.search';
                $ok = array_fill(0, $count, $status) === array_column($run['toolCalls'] ?? [], 'status')
                    && array_fill(0, $count, $name) === array_column($run['toolCalls'] ?? [], 'name') && count($run['upstream'] ?? []) === $upstreamCount
                    && isset($run['elapsedMs'], $run['runId']) && $run['elapsedMs'] >= 0 && !in_array($run['runId'], $ids, true)
                    && ($batch ? 'json-batch' : 'gvisor') === ($run['runtime']['executor'] ?? null);
                $ids[] = $run['runId'] ?? null;
                if ($batch) {
                    $ok = $ok && false === ($run['runtime']['generatedCode'] ?? null) && !array_key_exists('executedPhp', $run) && !array_key_exists('containerRemoved', $run);
                } else {
                    $ok = $ok && true === ($run['containerRemoved'] ?? null) && null !== $interface
                        && $interface->wrap($run['request']['code'] ?? '') === ($run['executedPhp'] ?? null)
                        && hash('sha256', $run['executedPhp'] ?? '') === ($run['sourceSha256'] ?? null);
                }
                foreach (['request', 'response'] as $key) {
                    $text = json_encode($run[$key] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $ok = $ok && strlen($text) === ($run[$key.'Bytes'] ?? null) && hash('sha256', $text) === ($run[$key.'Sha256'] ?? null);
                }
                foreach ($run['upstream'] ?? [] as $event) {
                    $ok = $ok && Connection::WIRE_TOOL === ($event['upstreamTool'] ?? null) && 'surface-proof-reader' === ($event['subject'] ?? null)
                        && !str_contains(json_encode($event['value'] ?? null), 'PRIVATE_');
                }
                $checks[$mode.':evidence'] = $checks[$mode.':evidence'] && $ok;
            }
        }
        if (count($modes) === 2) {
            $batchEvents = $report['runs']['batch']['composition']['upstream'] ?? [];
            $phpEvents = $report['runs']['php']['composition']['upstream'] ?? [];
            $strip = static fn ($events) => array_map(static fn ($e) => array_intersect_key($e, array_flip(['name', 'arguments', 'subject', 'value', 'status', 'upstreamTool'])), $events);
            $checks['sameActualReads'] = count($batchEvents) === 2 && $strip($batchEvents) === $strip($phpEvents);
            $checks['sameSchemas'] = array_column($report['discovery']['batch']['describe'] ?? [], 'inputSchema') === array_column($report['discovery']['php']['describe'] ?? [], 'inputSchema');
        }
        return $checks;
    }
}
