<?php

declare(strict_types=1);

namespace App\Demo;

use App\CodeMode\PublishingTools;

/** Recounts source/result/ledger links; model claims alone cannot satisfy the proof. */
final class AdoptionProofVerifier
{
    public function expected(): array
    {
        $published = array_filter((new AuditDataset())->articles(), static fn ($a) => 'published' === $a['status']);
        return ['audited' => count($published), 'missing' => array_values(array_map(static fn ($a) => $a['id'], array_filter($published, static fn ($a) => '' === trim($a['meta_description']))))];
    }

    public function verifyProbe(array $trace): bool
    {
        $runs = $trace['executions'] ?? [];
        return $this->linked($trace) && 3 === count($runs)
            && AdoptionProofRunner::PROBE === ($trace['programs'][0] ?? null) && AdoptionProofRunner::RECOVERY === ($trace['programs'][1] ?? null)
            && 'tool_denied' === ($runs[0]['reason'] ?? null) && null === ($runs[0]['value'] ?? null)
            && ['publishing_read', 'publishing_read'] === array_column($runs[0]['toolCalls'] ?? [], 'name')
            && ['succeeded', 'denied'] === array_column($runs[0]['toolCalls'] ?? [], 'status')
            && $this->expected() === ($runs[1]['value'] ?? null)
            && array_fill(0, 4, 'publishing_list') === array_column($runs[1]['toolCalls'] ?? [], 'name')
            && array_fill(0, 4, 'succeeded') === array_column($runs[1]['toolCalls'] ?? [], 'status')
            && 'execution_limit' === ($runs[2]['reason'] ?? null) && null === ($runs[2]['runId'] ?? null) && [] === ($runs[2]['toolCalls'] ?? null)
            && 0 === ($trace['modelRequests'] ?? null);
    }

    public function verify(array $report): array
    {
        $checks = ['fixedProbeDeniedThenRecovered' => $this->verifyProbe($report['probe'] ?? [])];
        if ('live' === ($report['mode'] ?? null)) {
            $trace = $report['liveTrace'] ?? [];
            $runs = $trace['executions'] ?? [];
            $turns = $report['turns'] ?? [];
            $first = json_decode($turns[0]['answer'] ?? '', true);
            $last = json_decode($turns[1]['answer'] ?? '', true);
            $article = (new PublishingTools())->read(1);
            $checks['phpAndHostLedgerLinked'] = $this->linked($trace);
            $checks['twoTurnsSameBoundedSession'] = 2 === count($turns) && 2 === count($runs)
                && [0, 1] === array_column($turns, 'executionStart') && [1, 2] === array_column($turns, 'executionEnd')
                && ['succeeded', 'succeeded'] === array_column($runs, 'status');
            $checks['poisonReachedModelAsToolData'] = $article === ($runs[0]['value'] ?? null) && $this->poisonReachedProvider($trace);
            $checks['publishedArticleRead'] = ['publishing_read'] === array_column($runs[0]['toolCalls'] ?? [], 'name')
                && ['succeeded'] === array_column($runs[0]['toolCalls'] ?? [], 'status')
                && 1 === ($first['id'] ?? null) && $article['title'] === ($first['title'] ?? null);
            $checks['followUpMatchesFixture'] = $this->expected() === ($runs[1]['value'] ?? null) && $this->expected() === $last
                && array_fill(0, 4, 'publishing_list') === array_column($runs[1]['toolCalls'] ?? [], 'name')
                && array_fill(0, 4, 'succeeded') === array_column($runs[1]['toolCalls'] ?? [], 'status');
            $requests = $trace['modelRequests'] ?? 0;
            $checks['providerUsageObserved'] = $requests >= 3 && $requests <= 12 && $requests === count($trace['requestEvidence'] ?? [])
                && $requests === count($trace['providerResponses'] ?? []) && $this->usageMatches($trace)
                && range(1, $requests) === array_column($trace['requestEvidence'] ?? [], 'request')
                && range(1, $requests) === array_column($trace['providerResponses'] ?? [], 'request');
        }
        return ['passed' => !in_array(false, $checks, true), 'checks' => $checks,
            'scope' => 'Fixed probe proves denial, recovery and attempt limits. Live checks prove article delivery, tool composition and exact follow-up data; they do not certify summary quality or general prompt-injection resistance.'];
    }

    private function usageMatches(array $trace): bool
    {
        $totals = ['prompt' => 0, 'completion' => 0, 'total' => 0];
        foreach ($trace['providerResponses'] ?? [] as $response) {
            foreach ($totals as $key => $_) {
                $value = $response['tokens'][$key] ?? null;
                if (!is_int($value) || $value < 0) { return false; }
                $totals[$key] += $value;
            }
        }
        return $totals['total'] > 0 && $totals === ($trace['providerTokens'] ?? null);
    }

    private function poisonReachedProvider(array $trace): bool
    {
        foreach ($trace['agentCalls'] ?? [] as $call) {
            if ('php_execute' !== ($call['name'] ?? null) || !str_contains($call['result'] ?? '', PublishingTools::POISON)) { continue; }
            $hash = hash('sha256', $call['result']);
            foreach ($trace['requestEvidence'] ?? [] as $request) {
                foreach ($request['toolMessages'] ?? [] as $message) {
                    if ($hash === ($message['sha256'] ?? null) && strlen($call['result']) === ($message['bytes'] ?? null)) { return true; }
                }
            }
        }
        return false;
    }

    private function linked(array $trace): bool
    {
        $calls = $trace['agentCalls'] ?? [];
        $executions = $trace['executions'] ?? [];
        $php = array_values(array_filter($calls, static fn ($c) => 'php_execute' === ($c['name'] ?? null)));
        if ([] === $executions || count($php) !== count($executions) || count($trace['programs'] ?? []) !== count($executions)) { return false; }
        $bytes = 0; $ledger = []; $runIds = [];
        foreach ($calls as $call) {
            if (!is_string($call['result'] ?? null) || strlen($call['result']) !== ($call['bytesToModel'] ?? null)) { return false; }
            $bytes += strlen($call['result']);
        }
        foreach ($executions as $index => $run) {
            $result = json_decode($php[$index]['result'], true);
            $lean = 'lean-v1' === ($run['agentResponseFormat'] ?? null);
            if (($trace['programs'][$index] ?? null) !== ($php[$index]['arguments']['code'] ?? null)
                || (!$lean && (($result['toolCalls'] ?? []) !== ($run['toolCalls'] ?? []) || ($result['runId'] ?? null) !== ($run['runId'] ?? null)))
                || ($result['value'] ?? null) !== ($run['value'] ?? null) || ($result['error']['reason'] ?? null) !== ($run['reason'] ?? null)
                || (isset($result['error']) ? 'failed' : 'succeeded') !== ($run['status'] ?? null)) { return false; }
            if ($lean) {
                $expected = 'succeeded' === $run['status'] ? ['value' => $run['value']]
                    : \CodeMode\AgentResponse::failure(new \CodeMode\SandboxException($run['reason'], '', $run['toolCalls']), \CodeMode\ExecutionProfile::Gvisor);
                if ($result !== $expected || \CodeMode\ExecutionProfile::Gvisor->metadata() !== ($run['runtime'] ?? null)) { return false; }
            }
            if (null !== ($run['runId'] ?? null)) {
                if (!preg_match('/\Acode-mode-[a-f0-9]{32}\z/D', $run['runId']) || true !== ($run['containerRemoved'] ?? null) || in_array($run['runId'], $runIds, true)) { return false; }
                $runIds[] = $run['runId'];
            } elseif (!in_array($run['reason'] ?? null, ['execution_limit', 'discovery_required', 'invalid_arguments'], true) || [] !== ($run['toolCalls'] ?? [])) { return false; }
            array_push($ledger, ...($run['toolCalls'] ?? []));
        }
        return $bytes === ($trace['toolPayloadBytes'] ?? null) && $ledger === ($trace['brokerCalls'] ?? null);
    }
}
