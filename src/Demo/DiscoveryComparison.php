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
        $large = DiscoveryCatalog::PROFILE === ($report['catalogProfile'] ?? null);
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
            $passed = $passed && ($report['catalogProfile'] ?? 'publishing-2-v1') === ($proof['catalogProfile'] ?? 'publishing-2-v1');
            $passed = $passed && ($report['searchProtocol'] ?? 'ranked-any-v1') === ($proof['searchProtocol'] ?? 'ranked-any-v1');
            $catalogVerified = !$large || ($this->largeCatalogVerified($proof, $trace) && $this->largeCatalogVerified($proof, $proof['probe'] ?? []));
            $passed = $passed && $catalogVerified;
            $discovery = array_values(array_filter($trace['agentCalls'] ?? [], static fn ($call) => in_array($call['name'], ['tools_search', 'tools_describe'], true)));
            $search = array_values(array_filter($discovery, static fn ($call) => 'tools_search' === $call['name']));
            $describe = array_values(array_filter($discovery, static fn ($call) => 'tools_describe' === $call['name']));
            $fallback = []; $found = []; $searches = [];
            foreach ($search as $call) {
                $payload = json_decode($call['result'], true, flags: JSON_THROW_ON_ERROR);
                $items = $payload['tools'] ?? $payload;
                $searches[] = ['query' => $call['arguments']['query'], 'matched' => $payload['matched'] ?? null,
                    'returned' => count($items), 'truncated' => $payload['truncated'] ?? null, 'hint' => $payload['hint'] ?? null, 'names' => array_column($items, 'name')];
                foreach ($payload['tools'] ?? $payload as $tool) {
                    if (isset($tool['name'])) { $found[$tool['name']] = true; }
                    if (true === ($tool['requiresDescribe'] ?? null)) { $fallback[$tool['name']] = true; }
                }
            }
            $answers = [];
            if ('live' === ($report['mode'] ?? null)) {
                foreach ([(new PublishingTools())->read(1), (new AdoptionProofVerifier())->expected()] as $i => $expected) {
                    $answer = json_decode($proof['turns'][$i]['answer'] ?? '', true);
                    $answerCorrect = is_array($answer) && count($answer) === count($expected);
                    foreach ($expected as $key => $value) { $answerCorrect = $answerCorrect && $value === ($answer[$key] ?? null); }
                    $answers[] = ['turn' => $i + 1, 'answered' => isset($proof['turns'][$i]), 'correct' => $answerCorrect];
                }
            }
            $observedTokens = ['prompt' => 0, 'completion' => 0, 'total' => 0]; $metered = 0;
            foreach ($trace['providerResponses'] ?? [] as $response) {
                $usage = $response['tokens'] ?? null;
                if (!is_array($usage) || !isset($usage['prompt'], $usage['completion'], $usage['total']) || count(array_filter($usage, static fn ($value) => is_int($value) && $value >= 0)) !== 3) { continue; }
                foreach ($observedTokens as $key => $_) { $observedTokens[$key] += $usage[$key]; }
                ++$metered;
            }
            $rows[] = ['discovery' => $attempt['discovery'], 'repetition' => $attempt['repetition'], 'status' => $attempt['status'],
                'correct' => $passed, 'modelRequests' => $trace['modelRequests'] ?? 0, 'providerTokens' => $trace['providerTokens'] ?? null,
                'observedTokens' => $observedTokens, 'meteredResponses' => $metered, 'answers' => $answers, 'catalogVerified' => $catalogVerified,
                'searches' => $searches,
                'bodyReaderFound' => isset($found['publishing_read']), 'auditListFound' => isset($found['publishing_list']),
                'discoveryRequests' => count($discovery), 'discoveryBytes' => array_sum(array_column($discovery, 'bytesToModel')),
                'searchRequests' => count($search), 'describeRequests' => count($describe), 'fallbackTools' => array_keys($fallback),
                'toolPayloadBytes' => $trace['toolPayloadBytes'] ?? 0, 'brokerCalls' => count($trace['brokerCalls'] ?? [])];
            $correct = $correct && $passed;
            // Only the discovery presentation is allowed to differ between arms.
            $contract = [];
            foreach (['mode', 'model', 'provider', 'providerRouting', 'systemPrompt', 'generationSettings', 'image', 'limits', 'identity', 'fixtureSha256', 'approvedTools'] as $key) { $contract[$key] = $proof[$key] ?? null; }
            $contract['searchProtocol'] = $proof['searchProtocol'] ?? 'ranked-any-v1';
            $contract['catalogProfile'] = $proof['catalogProfile'] ?? 'publishing-2-v1';
            $contract['questions'] = $proof['questions'] ?? array_column($proof['turns'] ?? [], 'question');
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
                'questions' => array_sum(array_map(static fn ($row) => count($row['answers']), $arm)),
                'correctAnswers' => array_sum(array_map(static fn ($row) => count(array_filter($row['answers'], static fn ($answer) => $answer['correct'])), $arm)),
                'observedTokens' => array_sum(array_column(array_column($arm, 'observedTokens'), 'total')), 'meteredResponses' => array_sum(array_column($arm, 'meteredResponses')),
                'modelRequests' => array_sum(array_column($arm, 'modelRequests')), 'discoveryRequests' => array_sum(array_column($arm, 'discoveryRequests')),
                'searchRequests' => array_sum(array_column($arm, 'searchRequests')), 'describeRequests' => array_sum(array_column($arm, 'describeRequests')),
                'discoveryBytes' => array_sum(array_column($arm, 'discoveryBytes')), 'toolPayloadBytes' => array_sum(array_column($arm, 'toolPayloadBytes')),
                'providerTokens' => [] === $tokens || in_array(null, $tokens, true) ? null : array_sum(array_column($tokens, 'total')),
                'promptTokens' => [] === $tokens || in_array(null, $tokens, true) ? null : array_sum(array_column($tokens, 'prompt')),
                'completionTokens' => [] === $tokens || in_array(null, $tokens, true) ? null : array_sum(array_column($tokens, 'completion'))];
        }
        return ['matchedConditions' => $matched, 'allCorrect' => $complete && $matched && $correct, 'complete' => $complete,
            'searchProtocol' => $report['searchProtocol'] ?? 'ranked-any-v1',
            'catalogProfile' => $report['catalogProfile'] ?? 'publishing-2-v1',
            'catalog' => $report['attempts'][0]['proof']['catalogSummary'] ?? ['tools' => 2, 'compactReady' => ['publishing_read', 'publishing_list'], 'requiresDescribe' => [], 'searchResultLimit' => 8],
            'contractSha256' => $matched ? $contracts[0] : null, 'rows' => $rows, 'totals' => $totals,
            'scope' => ($large ? 'Sixteen native Symfony AI tools: overlapping publishing readers, nested image data and map-shaped contracts. The audit list requires full description in compact mode. ' : '').'Two-turn publishing task, with the same prompt, questions, model, settings, data, schemas, identity, gVisor image and limits in both arms. Both use lean execution responses. Provider tokens cover every observed response, including failed attempts; missing usage remains unmeasured. Discovery bytes count tool-response bodies, not tokens. Small sample; provider routing and generated PHP can vary. Fixed denial/recovery/budget probes are separate from model requests.'];
    }

    private function largeCatalogVerified(array $proof, array $trace): bool
    {
        try {
            $catalog = DiscoveryCatalog::sessions('', new \CodeMode\DockerEngine(), new \CodeMode\ExecutionLimits())->catalog();
            $normalize = static fn ($value) => json_decode(json_encode($value, JSON_THROW_ON_ERROR), true);
            if (DiscoveryCatalog::fixtureHash() !== ($proof['fixtureSha256'] ?? null)
                || $normalize($catalog->all()) !== $normalize($proof['approvedTools'] ?? null)
                || DiscoveryCatalog::summary($catalog->all()) !== ($proof['catalogSummary'] ?? null)
                || AdoptionProofRunner::QUESTIONS !== ($proof['questions'] ?? null)) { return false; }
            if (($trace['modelRequests'] ?? 0) > 0) {
                if (count($trace['requestEvidence'] ?? []) !== $trace['modelRequests']) { return false; }
                $questions = array_map(static fn ($question) => hash('sha256', $question), AdoptionProofRunner::QUESTIONS);
                foreach ($trace['requestEvidence'] as $request) {
                    if (hash('sha256', $proof['systemPrompt'] ?? '') !== ($request['systemPromptSha256'] ?? null)
                        || !in_array($request['userMessageSha256'] ?? [], [[$questions[0]], $questions], true)) { return false; }
                }
            }
            $ready = []; $execution = 0;
            foreach ($trace['agentCalls'] ?? [] as $call) {
                $result = json_decode($call['result'], true, flags: JSON_THROW_ON_ERROR);
                if ('tools_search' === $call['name']) {
                    $refined = \CodeMode\ToolCatalog::SEARCH_PROTOCOL === ($proof['searchProtocol'] ?? null);
                    $envelope = $refined ? $catalog->searchResult($call['arguments']['query']) : null;
                    $expected = $envelope['tools'] ?? $catalog->search($call['arguments']['query']);
                    if ('compact' === $proof['discoveryMode']) {
                        $expected = array_map(static fn ($tool) => (new \CodeMode\CompactToolGuide())->describe($tool), $catalog->describe(array_column($expected, 'name')));
                    }
                    if (null !== $envelope) { $expected = array_replace($envelope, ['tools' => $expected]); }
                    if ($normalize($expected) !== $result) { return false; }
                    foreach ($result['tools'] ?? $result as $tool) { if (false === ($tool['requiresDescribe'] ?? null)) { $ready[$tool['name']] = true; } }
                } elseif ('tools_describe' === $call['name']) {
                    $definitions = $catalog->describe($call['arguments']['names']);
                    $expected = array_map(static fn ($tool) => $tool->jsonSerialize() + ['php' => (new \CodeMode\PhpToolGuide())->describe($tool)], $definitions);
                    if ($normalize($expected) !== $result) { return false; }
                    foreach ($result as $tool) { $ready[$tool['name']] = true; }
                } elseif ('php_execute' === $call['name']) {
                    foreach ($trace['executions'][$execution++]['toolCalls'] ?? [] as $tool) {
                        if ('succeeded' === $tool['status'] && !isset($ready[$tool['name']])) { return false; }
                    }
                }
            }
            return true;
        } catch (\Throwable) { return false; }
    }
}
