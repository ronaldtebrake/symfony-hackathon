<?php

declare(strict_types=1);

namespace App\Demo\Performance;

use CodeMode\ExecutionLimits;

/** Offline verification. Recorded responses are data and never executed. */
final class BaselineVerifier
{
    public function verify(array $report, string $source): array
    {
        $code = json_decode($source, true) ?? [];
        $trace = $report['trace'] ?? []; $turns = $report['turns'] ?? []; $requests = $trace['modelRequests'] ?? 0;
        $calls = $trace['agentCalls'] ?? []; $ledger = $trace['brokerCalls'] ?? [];
        $checks = [
            'verifiedCodeModeSource' => (new ConversationVerifier())->verify($code)['passed'] && hash('sha256', $source) === ($report['sourceCaptureSha256'] ?? null),
            'liveDirectCapture' => 'performance-baseline' === ($report['kind'] ?? null) && 'live' === ($report['mode'] ?? null)
                && BaselineRunner::PROTOCOL === ($report['protocol'] ?? null) && BaselineRunner::prompt() === ($report['systemPrompt'] ?? null) && !isset($report['error']),
            'sameModelQuestionsDataPermissions' => true,
            'unchangedReaderLimits' => 'supervised-native-readers' === ($report['runtime'] ?? null)
                && get_object_vars(new ExecutionLimits()) + ['turns' => 2, 'modelRequests' => ConversationRunner::REQUEST_LIMIT] === ($report['limits'] ?? null),
            'sameToolContracts' => self::equal($code['approvedTools'] ?? null, $report['approvedTools'] ?? null),
            'directSecurityPreflight' => false,
            'twoTurnsSameSession' => 2 === count($turns) && ProofRunner::QUESTIONS === array_column($turns, 'question')
                && 0 === ($turns[0]['requestStart'] ?? null) && ($turns[0]['requestEnd'] ?? null) === ($turns[1]['requestStart'] ?? null)
                && 0 === ($turns[0]['agentCallStart'] ?? null) && ($turns[0]['agentCallEnd'] ?? null) === ($turns[1]['agentCallStart'] ?? null)
                && count($calls) === ($turns[1]['agentCallEnd'] ?? null) && $requests === ($turns[1]['requestEnd'] ?? null),
            'correctInvestigation' => false, 'correctFollowUp' => false,
            'actualReaderCalls' => count($calls) > 0 && count($calls) === count($ledger),
            'providerUsageObserved' => is_int($requests) && $requests >= 4 && $requests <= ConversationRunner::REQUEST_LIMIT
                && count($trace['providerResponses'] ?? []) === $requests && count($trace['requestEvidence'] ?? []) === $requests,
            'followUpRetainsConversation' => false,
        ];
        foreach (['model', 'provider', 'providerRouting', 'generationSettings', 'questions', 'identity', 'datasetSha256'] as $key) {
            $checks['sameModelQuestionsDataPermissions'] = $checks['sameModelQuestionsDataPermissions'] && isset($code[$key]) && $code[$key] === ($report[$key] ?? null);
        }
        $entry = array_map(static fn ($t) => ['type' => 'function', 'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['inputSchema']]], $code['approvedTools'] ?? []);
        $checks['sameToolContracts'] = $checks['sameToolContracts'] && self::equal($entry, $report['entryTools'] ?? null);
        $data = null;
        try { $data = new FrozenDataset($code['dataset'] ?? []); } catch (\Throwable) {}
        if (null !== $data) { $checks['directSecurityPreflight'] = self::security($report['securityProof'] ?? [], $data); }
        $bytes = 0;
        foreach ($calls as $i => $call) {
            $record = $ledger[$i] ?? []; $result = $call['result'] ?? '';
            $turnIndex = $record['turn'] ?? -1; $turn = $turns[$turnIndex] ?? [];
            $end = $turn['requestEnd'] ?? 0;
            $context = $trace['requestEvidence'][$end - 1]['toolMessages'] ?? [];
            $checks['actualReaderCalls'] = $checks['actualReaderCalls'] && null !== $data && $this->call($call, $record, $data)
                && in_array($turnIndex, [0, 1], true) && $i >= ($turn['agentCallStart'] ?? PHP_INT_MAX) && $i < ($turn['agentCallEnd'] ?? -1)
                && in_array(['bytes' => strlen($result), 'sha256' => hash('sha256', $result)], $context, true);
            $bytes += strlen($result);
        }
        $checks['actualReaderCalls'] = $checks['actualReaderCalls'] && $bytes === ($trace['toolPayloadBytes'] ?? null)
            && !str_contains(json_encode($report, JSON_THROW_ON_ERROR), RequestCollector::CANARY);
        foreach ([0 => 'correctInvestigation', 1 => 'correctFollowUp'] as $i => $key) {
            $turn = $turns[$i] ?? [];
            // The source verifier independently derives these exact values from the fixture.
            $expected = $code['trace']['executions'][$i]['value'] ?? null;
            $checks[$key] = null !== $expected && self::equal($expected, json_decode($turn['answer'] ?? '', true));
            $seen = array_slice($calls, 0, max(0, $turn['agentCallEnd'] ?? 0));
            $required = $i === 0 ? [['perf_requests', []], ['perf_profile', ['requestId' => $expected['profile']['requestId'] ?? '']], ['perf_logs', ['requestId' => $expected['profile']['requestId'] ?? '']]]
                : [['perf_profile', ['requestId' => 'profile-01']], ['perf_logs', ['requestId' => 'profile-01']], ['perf_profile', ['requestId' => 'profile-02']], ['perf_logs', ['requestId' => 'profile-02']]];
            $observed = array_map(static fn ($c) => [$c['name'], $c['arguments']], $seen);
            foreach ($required as $needed) { $checks[$key] = $checks[$key] && in_array($needed, $observed, true); }
            $count = ($turn['agentCallEnd'] ?? 0) - ($turn['agentCallStart'] ?? 0);
            $checks['unchangedReaderLimits'] = $checks['unchangedReaderLimits'] && $count >= 0 && $count <= (new ExecutionLimits())->maxToolCalls;
            $response = $trace['providerResponses'][($turn['requestEnd'] ?? 0) - 1] ?? [];
            $checks['providerUsageObserved'] = $checks['providerUsageObserved'] && isset($turn['answer']) && ($response['answerSha256'] ?? null) === hash('sha256', $turn['answer'] ?? '');
            $range = array_slice($ledger, $turn['agentCallStart'] ?? 0, max(0, $count));
            $checks['unchangedReaderLimits'] = $checks['unchangedReaderLimits'] && array_sum(array_column($range, 'durationMs')) < (new ExecutionLimits())->wallTimeMs;
        }
        $totals = ['prompt' => 0, 'completion' => 0, 'total' => 0];
        foreach ($trace['providerResponses'] ?? [] as $i => $response) {
            $checks['providerUsageObserved'] = $checks['providerUsageObserved'] && ($response['request'] ?? null) === $i + 1;
            foreach ($totals as $key => $_) {
                $n = $response['tokens'][$key] ?? null;
                $checks['providerUsageObserved'] = $checks['providerUsageObserved'] && is_int($n) && $n >= 0;
                $totals[$key] += is_int($n) ? $n : 0;
            }
            $checks['providerUsageObserved'] = $checks['providerUsageObserved'] && ($response['tokens']['prompt'] ?? -1) + ($response['tokens']['completion'] ?? -1) === ($response['tokens']['total'] ?? null);
        }
        $checks['providerUsageObserved'] = $checks['providerUsageObserved'] && $totals['total'] > 0 && $totals === ($trace['providerTokens'] ?? null);
        foreach ($trace['requestEvidence'] ?? [] as $i => $request) {
            $turn = $i < ($turns[0]['requestEnd'] ?? 0) ? 0 : 1;
            $checks['providerUsageObserved'] = $checks['providerUsageObserved'] && ($request['request'] ?? null) === $i + 1
                && ($request['toolsAvailable'] ?? false) && ($request['systemPromptSha256'] ?? null) === hash('sha256', BaselineRunner::prompt())
                && ($request['userMessageSha256'] ?? null) === array_map(static fn ($q) => hash('sha256', $q), array_slice(ProofRunner::QUESTIONS, 0, $turn + 1));
        }
        $followUp = $trace['requestEvidence'][$turns[1]['requestStart'] ?? -1] ?? [];
        $checks['followUpRetainsConversation'] = ($followUp['userMessageSha256'] ?? []) === array_map(static fn ($q) => hash('sha256', $q), ProofRunner::QUESTIONS)
            && in_array(hash('sha256', $turns[0]['answer'] ?? ''), $followUp['assistantMessageSha256'] ?? [], true);
        return ['passed' => !in_array(false, $checks, true), 'checks' => $checks];
    }

    public static function security(array $proof, ReadDataset $data): bool
    {
        $calls = $proof['calls'] ?? []; $ledger = $proof['ledger'] ?? [];
        if (11 !== count($calls) || 11 !== count($ledger)) { return false; }
        foreach ($calls as $i => $call) {
            $row = $ledger[$i];
            $reason = $i === 10 ? 'tool_call_limit' : (in_array($i, [1, 2, 3], true) ? 'tool_denied' : null);
            $name = [0 => 'perf_profile', 1 => 'perf_profile', 2 => 'perf_logs', 3 => 'unapproved_reader'][$i] ?? 'perf_requests';
            $args = $i < 3 ? ['requestId' => $i === 0 ? 'profile-01' : 'profile-04'] : [];
            $expected = null !== $reason ? ['error' => ['reason' => $reason]] : ($i === 0 ? $data->profile('profile-01') : $data->requests());
            if ($name !== ($call['name'] ?? null) || $args !== ($call['arguments'] ?? null) || !self::equal($expected, json_decode($call['result'] ?? '', true))
                || $name !== ($row['name'] ?? null) || $args !== ($row['arguments'] ?? null) || ($row['reason'] ?? null) !== $reason
                || ($row['status'] ?? null) !== (null === $reason ? 'succeeded' : ('tool_denied' === $reason ? 'denied' : 'incomplete')) || 0 !== ($row['turn'] ?? null)
                || ($row['workerClosed'] ?? null) !== ($i < 10) || ($row['responseSha256'] ?? null) !== hash('sha256', $call['result'] ?? '')
                || ($call['bytesToModel'] ?? null) !== strlen($call['result'] ?? '')) { return false; }
        }
        return true;
    }

    private function call(array $call, array $row, ReadDataset $data): bool
    {
        $name = $call['name'] ?? ''; $args = $call['arguments'] ?? []; $result = $call['result'] ?? '';
        if (!in_array($name, ReadTools::NAMES, true) || $name !== ($row['name'] ?? null) || $args !== ($row['arguments'] ?? null)
            || 'succeeded' !== ($row['status'] ?? null) || true !== ($row['workerClosed'] ?? null) || !is_numeric($row['durationMs'] ?? null) || $row['durationMs'] < 0
            || ($row['responseSha256'] ?? null) !== hash('sha256', $result) || ($row['resultBytes'] ?? null) !== strlen($result)
            || ($call['bytesToModel'] ?? null) !== strlen($result) || strlen($result) > (new ExecutionLimits())->maxToolResultBytes) { return false; }
        try {
            $value = match ($name) { 'perf_requests' => [] === $args ? $data->requests() : null,
                'perf_profile' => array_keys($args) === ['requestId'] ? $data->profile($args['requestId']) : null,
                'perf_logs' => array_keys($args) === ['requestId'] ? $data->logs($args['requestId']) : null };
            return null !== $value && self::equal($value, json_decode($result, true));
        } catch (\Throwable) { return false; }
    }

    private static function equal(mixed $a, mixed $b): bool
    {
        $sort = function (mixed $v) use (&$sort): mixed { if (!is_array($v)) { return $v; } if (!array_is_list($v)) { ksort($v); } return array_map($sort, $v); };
        return $sort($a) === $sort($b);
    }
}
