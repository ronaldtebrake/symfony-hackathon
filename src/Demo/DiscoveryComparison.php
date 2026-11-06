<?php

declare(strict_types=1);

namespace App\Demo;

use App\CodeMode\PublishingTools;

/** Recompute measurements from retained calls; never accept a model's success claim. */
final class DiscoveryComparison
{
    public function summarize(array $report): array
    {
        $rows = []; $contracts = []; $correct = true;
        foreach ($report['attempts'] ?? [] as $attempt) {
            $proof = $attempt['proof'] ?? [];
            $live = 'live' === ($proof['mode'] ?? null);
            $trace = $proof[$live ? 'liveTrace' : 'probe'] ?? [];
            $passed = 'complete' === ($attempt['status'] ?? null) && 'complete' === ($proof['status'] ?? null)
                && !isset($proof['error']) && ($report['mode'] ?? null) === ($proof['mode'] ?? null)
                && (new AdoptionProofVerifier())->verify($proof)['passed'];
            if ($live) {
                $answer = json_decode($proof['turns'][0]['answer'] ?? '', true);
                foreach ((new PublishingTools())->read(1) as $key => $value) {
                    $passed = $passed && $value === ($answer[$key] ?? null);
                }
                $passed = $passed && AdoptionProofRunner::QUESTIONS === array_column($proof['turns'] ?? [], 'question');
            }
            $passed = $passed && ($attempt['discovery'] ?? null) === ($proof['discoveryMode'] ?? null);
            $discovery = array_values(array_filter($trace['agentCalls'] ?? [], static fn ($call) => in_array($call['name'], ['tools_search', 'tools_describe'], true)));
            $rows[] = ['discovery' => $attempt['discovery'], 'repetition' => $attempt['repetition'], 'status' => $attempt['status'],
                'correct' => $passed, 'modelRequests' => $trace['modelRequests'] ?? 0, 'providerTokens' => $trace['providerTokens'] ?? null,
                'discoveryRequests' => count($discovery), 'discoveryBytes' => array_sum(array_column($discovery, 'bytesToModel')),
                'toolPayloadBytes' => $trace['toolPayloadBytes'] ?? 0, 'brokerCalls' => count($trace['brokerCalls'] ?? [])];
            $correct = $correct && $passed;
            // Only the discovery presentation is allowed to differ between arms.
            $contract = [];
            foreach (['mode', 'model', 'provider', 'providerRouting', 'systemPrompt', 'generationSettings', 'image', 'limits', 'identity', 'fixtureSha256', 'approvedTools'] as $key) { $contract[$key] = $proof[$key] ?? null; }
            $contracts[] = hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR));
        }
        $schedule = [];
        foreach ($rows as $row) { $schedule[$row['repetition']][] = $row['discovery']; }
        $paired = [] !== $schedule;
        foreach ($schedule as $modes) { sort($modes); $paired = $paired && ['compact', 'full'] === $modes; }
        $matched = $paired && [] !== $contracts && 1 === count(array_unique($contracts));
        $complete = [] !== $rows && !array_filter($rows, static fn ($row) => !in_array($row['status'], ['complete', 'failed'], true));
        $totals = [];
        foreach (['full', 'compact'] as $mode) {
            $arm = array_values(array_filter($rows, static fn ($row) => $mode === $row['discovery']));
            $tokens = array_column($arm, 'providerTokens');
            $totals[$mode] = ['planned' => count($arm), 'correct' => count(array_filter($arm, static fn ($row) => $row['correct'])),
                'modelRequests' => array_sum(array_column($arm, 'modelRequests')), 'discoveryRequests' => array_sum(array_column($arm, 'discoveryRequests')),
                'discoveryBytes' => array_sum(array_column($arm, 'discoveryBytes')), 'toolPayloadBytes' => array_sum(array_column($arm, 'toolPayloadBytes')),
                'providerTokens' => [] === $tokens || in_array(null, $tokens, true) ? null : array_sum(array_column($tokens, 'total'))];
        }
        return ['matchedConditions' => $matched, 'allCorrect' => $complete && $matched && $correct, 'complete' => $complete,
            'contractSha256' => $matched ? $contracts[0] : null, 'rows' => $rows, 'totals' => $totals,
            'scope' => 'Two-turn native Symfony AI publishing task, with the same prompt, model, settings, data, schemas, identity, gVisor image and limits. Both arms use lean execution responses. Provider tokens cover every observed request, including failures. Discovery bytes count tool-response bodies, not tokens. Small sample; provider routing and generated PHP can vary. Fixed denial/recovery/budget probes are separate from model requests.'];
    }
}
