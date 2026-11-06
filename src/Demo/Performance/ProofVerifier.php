<?php

declare(strict_types=1);

namespace App\Demo\Performance;

use CodeMode\ExecutionProfile;

/** Pure replay checks: viewing evidence never launches PHP, traffic, models or tools. */
final class ProofVerifier
{
    public function verify(array $report): array
    {
        $turns = $report['turns'] ?? [];
        $investigation = $turns[0]['trace']['value'] ?? [];
        $comparison = $turns[1]['trace']['value'] ?? [];
        $before = $comparison['before']['profile'] ?? [];
        $after = $comparison['after']['profile'] ?? [];
        $expected = [];
        for ($id = 1; $id <= 12; ++$id) { $expected[] = ['id' => $id, 'author' => 'Author '.(1 + ($id - 1) % 4)]; }
        $hash = hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR));
        $exhausted = $report['exhausted'] ?? [];
        $expectedExhausted = ['error' => ['reason' => 'execution_limit'], 'toolCalls' => [], 'runId' => null];
        // Retain historical evidence while requiring gVisor for newly labelled traces.
        if (array_key_exists('runtime', $exhausted)) { $expectedExhausted['runtime'] = ExecutionProfile::Gvisor->metadata(); }
        $checks = [
            'referenceProvenance' => 'performance-proof' === ($report['kind'] ?? null) && 1 === ($report['version'] ?? null)
                && 'reference' === ($report['mode'] ?? null) && 0 === ($report['modelRequests'] ?? null) && 2 === ($report['simulatedLatencyPerQueryMs'] ?? null),
            'sandboxConfiguration' => 'runsc' === ($report['runtime'] ?? null) && 1 === preg_match('/\Asha256:[a-f0-9]{64}\z/D', $report['image'] ?? '')
                && ['wallTimeMs' => 5000, 'memoryMiB' => 128, 'maxToolCalls' => 10, 'attemptsPerSession' => 2] === ($report['limits'] ?? null),
            'approvedDiscovery' => ReadTools::NAMES === array_column($report['discovery']['describe'] ?? [], 'name'),
            'exactPrograms' => [ProofRunner::INVESTIGATE, ProofRunner::COMPARE] === array_column($turns, 'php'),
            'slowRouteIdentified' => 'catalog_naive' === ($investigation['profile']['route'] ?? null) && 'profile-01' === ($investigation['profile']['requestId'] ?? null),
            'repeatedQueryCorrelated' => ['author_by_id'] === array_column($investigation['repeatedQueries'] ?? [], 'fingerprint')
                && [12] === array_column($investigation['repeatedQueries'] ?? [], 'count')
                && ['authors.loaded_individually', 'catalog.rendered'] === array_column($investigation['events'] ?? [], 'event')
                && [12, 12] === array_column($investigation['events'] ?? [], 'count'),
            'comparisonMatchesInvestigation' => $before === ($investigation['profile'] ?? null),
            'sameActualOutput' => $hash === ($before['outputSha256'] ?? null) && $hash === ($after['outputSha256'] ?? null) && true === ($comparison['sameOutput'] ?? null),
            'thirteenQueriesBecomeOne' => 13 === ($before['queryCount'] ?? null) && 1 === ($after['queryCount'] ?? null) && 12 === ($comparison['queriesSaved'] ?? null)
                && ['catalog_join'] === array_column($after['queries'] ?? [], 'fingerprint') && [1] === array_column($after['queries'] ?? [], 'count')
                && ['catalog_list', 'author_by_id'] === array_column($before['queries'] ?? [], 'fingerprint') && [1, 12] === array_column($before['queries'] ?? [], 'count'),
            'followUpCorrelated' => 'profile-02' === ($after['requestId'] ?? null) && 'catalog_joined' === ($after['route'] ?? null)
                && ['catalog.rendered'] === array_column($comparison['after']['events'] ?? [], 'event') && [12] === array_column($comparison['after']['events'] ?? [], 'count'),
            'measuredTimesConsistent' => $this->validTiming($before) && $this->validTiming($after),
            'hostCallsMatch' => ['perf_requests', 'perf_profile', 'perf_logs'] === array_column($turns[0]['trace']['toolCalls'] ?? [], 'name')
                && ['perf_requests', 'perf_profile', 'perf_logs', 'perf_profile', 'perf_logs'] === array_column($turns[1]['trace']['toolCalls'] ?? [], 'name'),
            'successfulExecutions' => count($turns) === 2,
            'restrictedProfileAndLogsDenied' => count($report['denials'] ?? []) === 2,
            'cleanup' => true,
            'distinctRealExecutions' => true,
            'attemptBudgetEnforced' => $expectedExhausted === $exhausted,
            'noPrivateCanary' => !str_contains(json_encode($report, JSON_THROW_ON_ERROR), RequestCollector::CANARY),
        ];
        foreach ($turns as $turn) {
            $checks['successfulExecutions'] = $checks['successfulExecutions'] && false === ($turn['isError'] ?? null)
                && !isset($turn['trace']['error']) && array_fill(0, count($turn['trace']['toolCalls'] ?? []), 'succeeded') === array_column($turn['trace']['toolCalls'] ?? [], 'status');
        }
        foreach ($report['denials'] ?? [] as $index => $denial) {
            $trace = $denial['trace'] ?? [];
            $name = ['perf_profile', 'perf_logs'][$index] ?? '';
            $checks['restrictedProfileAndLogsDenied'] = $checks['restrictedProfileAndLogsDenied'] && true === ($denial['isError'] ?? null)
                && 'tool_denied' === ($trace['error']['reason'] ?? null) && !array_key_exists('value', $trace)
                && [$name] === array_column($trace['toolCalls'] ?? [], 'name') && ['denied'] === array_column($trace['toolCalls'] ?? [], 'status')
                && ['tool_denied'] === array_column($trace['toolCalls'] ?? [], 'reason') && !isset($trace['toolCalls'][0]['resultBytes'])
                && 'return $tools->call("'.$name.'", ["requestId" => "profile-04"]);' === ($denial['php'] ?? null);
        }
        $runIds = [];
        foreach ([...$turns, ...($report['denials'] ?? [])] as $execution) {
            if (array_key_exists('agentResponse', $execution)) {
                $trace = $execution['trace'];
                $expectedResponse = isset($trace['error'])
                    ? \CodeMode\AgentResponse::failure(new \CodeMode\SandboxException($trace['error']['reason'], '', $trace['toolCalls']), ExecutionProfile::Gvisor)
                    : ['value' => $trace['value']];
                $checks['leanResponsesMatchHostEvidence'] = ($checks['leanResponsesMatchHostEvidence'] ?? true) && $expectedResponse === $execution['agentResponse'];
            }
            if (array_key_exists('runtime', $execution['trace'] ?? [])) {
                $checks['sandboxConfiguration'] = $checks['sandboxConfiguration'] && ExecutionProfile::Gvisor->metadata() === $execution['trace']['runtime'];
            }
            $checks['cleanup'] = $checks['cleanup'] && true === ($execution['containerRemoved'] ?? null);
            $runId = $execution['trace']['runId'] ?? '';
            $checks['distinctRealExecutions'] = $checks['distinctRealExecutions'] && is_string($runId) && 1 === preg_match('/\Acode-mode-[a-f0-9]{32}\z/D', $runId);
            $runIds[] = $runId;
        }
        $checks['distinctRealExecutions'] = $checks['distinctRealExecutions'] && count(array_unique($runIds)) === 4;
        return ['passed' => !in_array(false, $checks, true), 'checks' => $checks];
    }

    private function validTiming(array $profile): bool
    {
        foreach (['durationMs', 'queryTimeMs'] as $key) {
            $value = $profile[$key] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) { return false; }
        }
        foreach ($profile['queries'] ?? [] as $query) {
            if (!is_numeric($query['durationMs'] ?? null) || $query['durationMs'] < 0) { return false; }
        }
        return $profile['durationMs'] >= $profile['queryTimeMs']
            && abs($profile['queryTimeMs'] - array_sum(array_column($profile['queries'] ?? [], 'durationMs'))) < 0.0001;
    }
}
