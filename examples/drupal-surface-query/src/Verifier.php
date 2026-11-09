<?php

declare(strict_types=1);

namespace SurfaceProof;

use CodeMode\{ExecutionLimits, ToolCatalog, ToolDefinition};
use CodeMode\Provider\PhpToolInterface;

final class Verifier
{
    public static function fixture(array $snapshot, bool $allowed = true): bool
    {
        $nodes = $snapshot['nodes'] ?? [];
        $permissions = $snapshot['account']['permissions'] ?? [];
        return true === ($snapshot['bridgeValid'] ?? null) && $allowed === ($snapshot['queryAllowed'] ?? null)
            && 'code_mode_surface_reader' === ($snapshot['account']['name'] ?? null) && ($snapshot['account']['id'] ?? 0) > 1
            && ($allowed ? ['access content','access webmcp surface demo'] : ['access content']) === $permissions
            && true === ($nodes['orchard']['entityAllowed'] ?? null) && true === ($nodes['orchard']['titleAllowed'] ?? null)
            && true === ($nodes['garden']['entityAllowed'] ?? null) && true === ($nodes['garden']['titleAllowed'] ?? null)
            && true === ($nodes['shared']['entityAllowed'] ?? null) && true === ($nodes['shared']['titleAllowed'] ?? null)
            && true === ($nodes['orchard']['idAllowed'] ?? null) && true === ($nodes['garden']['idAllowed'] ?? null) && true === ($nodes['shared']['idAllowed'] ?? null)
            && false === ($nodes['draft']['published'] ?? null) && false === ($nodes['draft']['entityAllowed'] ?? null)
            && true === ($nodes['field_denied']['published'] ?? null) && true === ($nodes['field_denied']['entityAllowed'] ?? null) && false === ($nodes['field_denied']['titleAllowed'] ?? null);
    }
    public static function expected(array $snapshot): array
    {
        $expected = $snapshot['expected'] ?? [];
        return ['orchard'=>['matches'=>[$expected['orchard'] ?? null,$expected['shared'] ?? null],'count'=>2],
            'garden'=>['matches'=>[$expected['garden'] ?? null,$expected['shared'] ?? null],'count'=>2],
            'empty'=>['matches'=>[],'count'=>0], 'draft'=>['matches'=>[],'count'=>0], 'field_denied'=>['matches'=>[],'count'=>0]];
    }
    public static function sorted(mixed $value): mixed
    {
        if (is_array($value) && isset($value['matches']) && is_array($value['matches'])) {
            usort($value['matches'], static fn ($a,$b) => $a['id'] <=> $b['id']);
        }
        return $value;
    }
    public function check(array $report): array
    {
        $before = $report['before'] ?? []; $after = $report['after'] ?? []; $runs = $report['executions'] ?? [];
        $expected = self::expected($before); $audit = $runs['composition'] ?? [];
        $matches = array_values($before['expected'] ?? []); usort($matches, static fn ($a,$b) => $a['id'] <=> $b['id']);
        $checks = [
            'noCaptureErrors'=>!isset($report['error']) && !isset($report['restorationFailed']) && !isset($report['snapshotFailed']),
            'configuredLimits'=>get_object_vars(new ExecutionLimits(wallTimeMs:15000,toolTimeMs:3000,maxToolCalls:2))+['executionAttempts'=>2] === ($report['limits'] ?? null),
            'providerProvenance'=>['drupal.search'=>['provider'=>'drupal','method'=>'search','source'=>'mcp','upstreamTool'=>Connection::WIRE_TOOL]] === ($report['origins'] ?? null),
            'queryCodeUnchanged'=>6 === count($before['sources'] ?? []) && ($before['sources'] ?? null) === ($after['sources'] ?? null)
                && ($before['sources'] ?? null) === ($report['revoked']['sources'] ?? null) && !empty($before['versions']) && ($before['versions'] ?? null) === ($after['versions'] ?? null),
            'fixtureAccess'=>self::fixture($before) && self::fixture($after),
            'contentAndPermissionsUnchanged'=>isset($before['contentHash']) && $before['contentHash'] === ($after['contentHash'] ?? null) && ($before['account'] ?? null) === ($after['account'] ?? null),
            'referenceOnly'=>1 === ($report['version'] ?? null) && false === ($report['modelCalled'] ?? null) && 'data-surface-query-proof' === ($report['kind'] ?? null),
            'isolatedRuntime'=>'gvisor' === ($report['runtime']['executor'] ?? null) && 1 === preg_match('/\Asha256:[a-f0-9]{64}\z/D', $report['image'] ?? ''),
            'compositionCorrect'=>['matches'=>$matches,'count'=>3,'returnedBySearch'=>['orchard'=>2,'garden'=>2]] === ($audit['value'] ?? null)
                && ['drupal.search','drupal.search'] === array_column($audit['toolCalls'] ?? [],'name') && ['succeeded','succeeded'] === array_column($audit['toolCalls'] ?? [],'status'),
            'emptyResult'=>['matches'=>[],'count'=>0] === ($runs['empty']['value'] ?? null) && ['succeeded'] === array_column($runs['empty']['toolCalls'] ?? [],'status'),
            'restrictedRecordsExcluded'=>['draft'=>['matches'=>[],'count'=>0],'field_denied'=>['matches'=>[],'count'=>0]] === ($runs['restricted-records']['value'] ?? null)
                && ['succeeded','succeeded'] === array_column($runs['restricted-records']['toolCalls'] ?? [],'status'),
            'schemaLimit'=>'invalid_arguments' === ($runs['excessive-limit']['reason'] ?? null) && null === ($runs['excessive-limit']['value'] ?? null),
            'unknownInputsRefused'=>'tool_failed' === ($runs['unknown-input']['reason'] ?? null) && null === ($runs['unknown-input']['value'] ?? null),
            'aliasCannotBypass'=>'tool_denied' === ($runs['raw-upstream-name']['reason'] ?? null) && null === ($runs['raw-upstream-name']['value'] ?? null),
            'revocationAfterDiscovery'=>self::fixture($report['revoked'] ?? [],false) && true === ($report['mcp']['revoked']['isError'] ?? null)
                && ($before['contentHash'] ?? null) === ($report['revoked']['contentHash'] ?? null)
                && ($runs['revoked']['php'] ?? null) === ($runs['restored']['php'] ?? null)
                && 'tool_failed' === ($runs['revoked']['reason'] ?? null) && null === ($runs['revoked']['value'] ?? null),
            'restoredRead'=>self::sorted($runs['restored']['value'] ?? null) === $expected['orchard'] && ['succeeded'] === array_column($runs['restored']['toolCalls'] ?? [],'status'),
            'attemptBudget'=>'execution_limit' === ($runs['attempt-budget']['reason'] ?? null) && null === ($runs['attempt-budget']['runId'] ?? null) && [] === ($runs['attempt-budget']['toolCalls'] ?? null),
        ];
        foreach ($expected as $label=>$value) {
            $checks['runnerMcpParity:'.$label] = self::sorted($report['direct'][$label] ?? null) === $value
                && false === ($report['mcp'][$label]['isError'] ?? null) && self::sorted($report['mcp'][$label]['value'] ?? null) === $value;
        }
        $interface = null;
        try {
            $definitions = array_map(static fn ($contract) => new ToolDefinition($contract['name'], $contract['description'], json_decode(json_encode($contract['inputSchema'], JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR), isset($contract['outputSchema']) ? json_decode(json_encode($contract['outputSchema'], JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR) : null), $report['contracts'] ?? []);
            $interface = new PhpToolInterface(['drupal'=>['search'=>'drupal.search']], new ToolCatalog($definitions));
        } catch (\Throwable) { /* Invalid schemas cannot verify a program. */ }
        $checks['schemaDerivedInterface'] = null !== $interface && $interface->stubs() === ($report['interfaces'] ?? null);
        $checks['hostCallEvidence'] = true;
        foreach ([
            'composition'=>['succeeded',null,'drupal.search','succeeded',2],
            'empty'=>['succeeded',null,'drupal.search','succeeded',1],
            'restricted-records'=>['succeeded',null,'drupal.search','succeeded',2],
            'excessive-limit'=>['failed','invalid_arguments','drupal.search','incomplete',1],
            'unknown-input'=>['failed','tool_failed','drupal.search','incomplete',1],
            'raw-upstream-name'=>['failed','tool_denied',Connection::WIRE_TOOL,'denied',1],
            'revoked'=>['failed','tool_failed','drupal.search','incomplete',1],
            'restored'=>['succeeded',null,'drupal.search','succeeded',1],
        ] as $label=>[$status,$reason,$name,$callStatus,$count]) {
            $run = $runs[$label] ?? [];
            $checks['hostCallEvidence'] = $checks['hostCallEvidence'] && $status === ($run['status'] ?? null)
                && $reason === ($run['reason'] ?? null) && array_fill(0,$count,$name) === array_column($run['toolCalls'] ?? [],'name')
                && array_fill(0,$count,$callStatus) === array_column($run['toolCalls'] ?? [],'status');
        }
        $checks['sourceAndCleanup'] = count($runs) === 9; $ids = [];
        foreach ($runs as $label=>$run) {
            $checks['sourceAndCleanup'] = $checks['sourceAndCleanup'] && is_string($run['php'] ?? null) && '' !== $run['php']
                && null !== $interface && $interface->wrap($run['php']) === ($run['executedPhp'] ?? null)
                && hash('sha256',$run['executedPhp']) === ($run['sourceSha256'] ?? null) && is_numeric($run['elapsedMs'] ?? null) && $run['elapsedMs'] >= 0;
            if ('attempt-budget' === $label) { continue; }
            $id = $run['runId'] ?? '';
            $checks['sourceAndCleanup'] = $checks['sourceAndCleanup'] && is_string($id) && 1 === preg_match('/\Acode-mode-[a-f0-9]{32}\z/D',$id)
                && !in_array($id,$ids,true) && true === ($run['containerRemoved'] ?? null) && 'gvisor' === ($run['runtime'] ?? null);
            $ids[] = $id;
        }
        $checks['noRestrictedPayload'] = true;
        foreach ([...array_values($runs),...array_values($report['mcp'] ?? [])] as $run) {
            $value = json_encode($run['value'] ?? null, JSON_THROW_ON_ERROR);
            $checks['noRestrictedPayload'] = $checks['noRestrictedPayload'] && !str_contains($value,'PRIVATE_');
        }
        return $checks;
    }
}
