<?php

declare(strict_types=1);

namespace App\Demo\Performance;

use CodeMode\ExecutionLimits;
use CodeMode\ExecutionProfile;

/** Verifies recorded evidence only. Never runs generated code or calls a model. */
final class ConversationVerifier
{
    public function verify(array $report): array
    {
        $trace = $report['trace'] ?? [];
        $turns = $report['turns'] ?? [];
        $prompt = match ($report['protocol'] ?? '') {
            'performance-conversation-v1' => ConversationRunner::PROMPT_V1, ConversationRunner::PROTOCOL => ConversationRunner::PROMPT, default => null,
        };
        $runs = $trace['executions'] ?? [];
        $data = $report['dataset'] ?? [];
        $profiles = $data['profiles'] ?? [];
        $logs = $data['logs'] ?? [];
        $requests = $trace['modelRequests'] ?? 0;
        $expectedRows = [];
        for ($id = 1; $id <= 12; ++$id) {
            $expectedRows[] = ['id' => $id, 'author' => 'Author '.(1 + ($id - 1) % 4)];
        }
        $hash = hash('sha256', json_encode($expectedRows, \JSON_THROW_ON_ERROR));
        $before = $profiles['profile-01'] ?? [];
        $after = $profiles['profile-02'] ?? [];
        $checks = [
            'liveProvenance' => 'performance-conversation' === ($report['kind'] ?? null) && 'live' === ($report['mode'] ?? null)
                && null !== $prompt && !isset($report['error'])
                && $prompt === ($report['systemPrompt'] ?? null) && ProofRunner::QUESTIONS === ($report['questions'] ?? null)
                && ConversationRunner::SETTINGS === ($report['generationSettings'] ?? null),
            'unchangedBoundaries' => 'runsc' === ($report['runtime'] ?? null)
                && 1 === preg_match('/\Asha256:[a-f0-9]{64}\z/D', $report['image'] ?? '')
                && ['subject' => 'local-performance-reader', 'capabilities' => ['performance.read_public']] === ($report['identity'] ?? null)
                && get_object_vars(new ExecutionLimits()) + ['executionAttempts' => 2, 'modelRequests' => ConversationRunner::REQUEST_LIMIT] === ($report['limits'] ?? null),
            'realMcpSecurityProof' => (new ProofVerifier())->verify($report['securityProof'] ?? [])['passed']
                && ($report['image'] ?? null) === ($report['securityProof']['image'] ?? null),
            'publicDatasetOnly' => ['profile-01', 'profile-02', 'profile-03'] === array_keys($profiles)
                && ['profile-01', 'profile-02', 'profile-03'] === array_keys($logs)
                && hash('sha256', json_encode($data, \JSON_THROW_ON_ERROR)) === ($report['datasetSha256'] ?? null)
                && !str_contains(json_encode($report, \JSON_THROW_ON_ERROR), RequestCollector::CANARY),
            'fixtureMeasurements' => 13 === ($before['queryCount'] ?? null) && 1 === ($after['queryCount'] ?? null)
                && $hash === ($before['outputSha256'] ?? null) && $hash === ($after['outputSha256'] ?? null)
                && ['catalog_list', 'author_by_id'] === array_column($before['queries'] ?? [], 'fingerprint')
                && [1, 12] === array_column($before['queries'] ?? [], 'count')
                && ['catalog_join'] === array_column($after['queries'] ?? [], 'fingerprint')
                && [1] === array_column($after['queries'] ?? [], 'count'),
            'twoTurnsSameSession' => 2 === \count($turns) && 2 === \count($runs)
                && ProofRunner::QUESTIONS === array_column($turns, 'question')
                && [0, 1] === array_column($turns, 'executionStart') && [1, 2] === array_column($turns, 'executionEnd')
                && 0 === ($turns[0]['requestStart'] ?? null) && ($turns[0]['requestEnd'] ?? null) === ($turns[1]['requestStart'] ?? null)
                && 0 === ($turns[0]['agentCallStart'] ?? null) && ($turns[0]['agentCallEnd'] ?? null) === ($turns[1]['agentCallStart'] ?? null)
                && \count($trace['agentCalls'] ?? []) === ($turns[1]['agentCallEnd'] ?? null)
                && $requests === ($turns[1]['requestEnd'] ?? null) && 'execution_limit' === ($report['liveAttemptLimit'] ?? null),
            'generatedPhpLinkedToExecution' => $this->linked($trace),
            'correctInvestigationAndFollowUp' => false,
            'providerUsageObserved' => $requests >= 4 && $requests <= ConversationRunner::REQUEST_LIMIT
                && \count($trace['requestEvidence'] ?? []) === $requests && \count($trace['providerResponses'] ?? []) === $requests,
            'fullContractsDisclosed' => $this->disclosed($report),
            'followUpRetainsConversation' => false,
        ];
        foreach ($profiles as $id => $profile) {
            $checks['fixtureMeasurements'] = $checks['fixtureMeasurements'] && ($profile['requestId'] ?? null) === $id
                && is_numeric($profile['durationMs'] ?? null) && is_numeric($profile['queryTimeMs'] ?? null)
                && $profile['queryTimeMs'] >= 0 && $profile['durationMs'] >= $profile['queryTimeMs']
                && abs($profile['queryTimeMs'] - array_sum(array_column($profile['queries'] ?? [], 'durationMs'))) < 0.0001;
        }
        if (2 === \count($runs) && 2 === \count($turns)) {
            $baseline = array_values(array_filter($data['requests']['items'] ?? [], static fn ($r) => 'after' !== $r['variant']));
            usort($baseline, static fn ($a, $b) => $b['durationMs'] <=> $a['durationMs']);
            $slow = $profiles[$baseline[0]['requestId'] ?? ''] ?? [];
            $expected = [
                ['profile' => $slow, 'repeatedQueries' => array_values(array_filter($slow['queries'] ?? [], static fn ($q) => $q['count'] > 1)), 'events' => $logs[$slow['requestId'] ?? '']['events'] ?? []],
                ['before' => ['profile' => $before, 'events' => $logs['profile-01']['events'] ?? []],
                    'after' => ['profile' => $after, 'events' => $logs['profile-02']['events'] ?? []], 'sameOutput' => true, 'queriesSaved' => 12],
            ];
            $checks['correctInvestigationAndFollowUp'] = 'catalog_naive' === ($slow['route'] ?? null);
            foreach ($expected as $i => $value) {
                $phpValue = $value;
                $checks['correctInvestigationAndFollowUp'] = $checks['correctInvestigationAndFollowUp']
                    && $this->equal($phpValue, $runs[$i]['value'] ?? null) && $this->equal($value, json_decode($turns[$i]['answer'] ?? '', true));
                $names = array_column($runs[$i]['toolCalls'] ?? [], 'name');
                $reads = $i + 1;
                $checks['correctInvestigationAndFollowUp'] = $checks['correctInvestigationAndFollowUp']
                    && \count(array_filter($names, static fn ($n) => 'perf_profile' === $n)) >= $reads
                    && \count(array_filter($names, static fn ($n) => 'perf_logs' === $n)) >= $reads
                    && ($i > 0 || \in_array('perf_requests', $names, true));
            }
        }
        $totals = ['prompt' => 0, 'completion' => 0, 'total' => 0];
        foreach ($trace['providerResponses'] ?? [] as $i => $response) {
            $checks['providerUsageObserved'] = $checks['providerUsageObserved'] && ($response['request'] ?? null) === $i + 1;
            foreach ($totals as $key => $_) {
                $n = $response['tokens'][$key] ?? null;
                $checks['providerUsageObserved'] = $checks['providerUsageObserved'] && \is_int($n) && $n >= 0;
                $totals[$key] += \is_int($n) ? $n : 0;
            }
        }
        $checks['providerUsageObserved'] = $checks['providerUsageObserved'] && $totals['total'] > 0
            && $totals['prompt'] + $totals['completion'] === $totals['total'] && $totals === ($trace['providerTokens'] ?? null);
        foreach ($trace['requestEvidence'] ?? [] as $i => $request) {
            $checks['providerUsageObserved'] = $checks['providerUsageObserved'] && $request['request'] === $i + 1
                && $request['systemPromptSha256'] === hash('sha256', $prompt ?? '')
                && \in_array($request['userMessageSha256'], [array_map(static fn ($q) => hash('sha256', $q), \array_slice(ProofRunner::QUESTIONS, 0, 1)), array_map(static fn ($q) => hash('sha256', $q), ProofRunner::QUESTIONS)], true);
        }
        foreach ($turns as $turn) {
            $response = $trace['providerResponses'][($turn['requestEnd'] ?? 0) - 1] ?? [];
            $checks['providerUsageObserved'] = $checks['providerUsageObserved'] && ($response['answerSha256'] ?? null) === hash('sha256', $turn['answer'] ?? '');
        }
        $followUp = $trace['requestEvidence'][$turns[1]['requestStart'] ?? -1] ?? [];
        $checks['followUpRetainsConversation'] = ($followUp['userMessageSha256'] ?? []) === array_map(static fn ($q) => hash('sha256', $q), ProofRunner::QUESTIONS)
            && \in_array(hash('sha256', $turns[0]['answer'] ?? ''), $followUp['assistantMessageSha256'] ?? [], true);

        return ['passed' => !\in_array(false, $checks, true), 'checks' => $checks];
    }

    private function equal(mixed $a, mixed $b): bool
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (!\is_array($v)) {
                return $v;
            }
            if (!array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        return $sort($a) === $sort($b);
    }

    private function linked(array $trace): bool
    {
        $calls = $trace['agentCalls'] ?? [];
        $runs = $trace['executions'] ?? [];
        $php = array_values(array_filter($calls, static fn ($c) => 'php_execute' === $c['name']));
        if (2 !== \count($php) || 2 !== \count($runs) || 2 !== \count($trace['programs'] ?? [])) {
            return false;
        }
        $bytes = 0;
        $ledger = [];
        $ids = [];
        foreach ($calls as $call) {
            if (\strlen($call['result']) !== $call['bytesToModel']) {
                return false;
            }
            $bytes += $call['bytesToModel'];
        }
        foreach ($runs as $i => $run) {
            if (($run['programIndex'] ?? null) !== $i || ($trace['programs'][$i] ?? null) !== ($php[$i]['arguments']['code'] ?? null)
                || !$this->equal(['value' => $run['value']], json_decode($php[$i]['result'], true))
                || 'succeeded' !== $run['status'] || ExecutionProfile::Gvisor->metadata() !== $run['runtime']
                || true !== ($run['containerRemoved'] ?? null) || !preg_match('/\Acode-mode-[a-f0-9]{32}\z/D', $run['runId'] ?? '')
                || array_fill(0, \count($run['toolCalls']), 'succeeded') !== array_column($run['toolCalls'], 'status')) {
                return false;
            }
            $ids[] = $run['runId'];
            array_push($ledger, ...$run['toolCalls']);
            $seen = false;
            foreach ($trace['requestEvidence'] ?? [] as $request) {
                if (\in_array(['bytes' => \strlen($php[$i]['result']), 'sha256' => hash('sha256', $php[$i]['result'])], $request['toolMessages'] ?? [], true)) {
                    $seen = true;
                }
            }
            if (!$seen) {
                return false;
            }
        }

        return 2 === \count(array_unique($ids)) && $ledger === ($trace['brokerCalls'] ?? null) && $bytes === ($trace['toolPayloadBytes'] ?? null);
    }

    private function disclosed(array $report): bool
    {
        $approved = array_column($report['approvedTools'] ?? [], null, 'name');
        $ready = [];
        if (ReadTools::NAMES !== array_keys($approved)) {
            return false;
        }
        $data = new Dataset([]);
        $tools = (new \CodeMode\Symfony\Ai\ApprovedToolbox(new \Symfony\AI\Agent\Toolbox\Toolbox([new ReadTools($data)]), ReadTools::NAMES, new ReadPolicy($data)))->tools();
        $expected = json_decode(json_encode(array_map(static fn ($tool) => $tool->definition(), $tools), \JSON_THROW_ON_ERROR), true);
        if (!$this->equal($expected, array_values($approved))) {
            return false;
        }
        $entry = array_map(static fn ($tool) => ['type' => 'function', 'function' => ['name' => $tool->name, 'description' => $tool->description, 'parameters' => $tool->inputSchema]], \CodeMode\CodeModeSession::definitions());
        if (!$this->equal(json_decode(json_encode($entry, \JSON_THROW_ON_ERROR), true), $report['entryTools'] ?? null)) {
            return false;
        }
        foreach ($report['trace']['agentCalls'] ?? [] as $call) {
            $result = json_decode($call['result'], true);
            if ('tools_describe' === $call['name'] && !isset($result['error'])) {
                foreach ($result as $tool) {
                    if (!isset($approved[$tool['name']]) || !$this->equal($approved[$tool['name']]['inputSchema'], $tool['inputSchema'] ?? null)
                        || !$this->equal($approved[$tool['name']]['outputSchema'], $tool['outputSchema'] ?? null)) {
                        return false;
                    }
                    $ready[$tool['name']] = true;
                }
            }
            if ('php_execute' === $call['name'] && 3 !== \count($ready)) {
                return false;
            }
        }

        return 3 === \count($ready);
    }
}
