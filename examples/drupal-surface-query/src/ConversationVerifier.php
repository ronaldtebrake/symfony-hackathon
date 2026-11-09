<?php

declare(strict_types=1);

namespace SurfaceProof;

use CodeMode\{ExecutionLimits, ToolCatalog, ToolDefinition};
use CodeMode\Provider\PhpToolInterface;

/** Checks host observations and data, not the prose quality of a model's explanation. */
final class ConversationVerifier
{
    public static function equal(mixed $a, mixed $b): bool
    {
        $sort = function (mixed $v) use (&$sort): mixed {
            if (!is_array($v)) { return $v; }
            if (!array_is_list($v)) { ksort($v); }
            return array_map($sort, $v);
        };
        return $sort($a) === $sort($b);
    }

    public function check(array $report): array
    {
        $before = $report['before'] ?? []; $after = $report['after'] ?? [];
        $trace = $report['trace'] ?? []; $turns = $report['turns'] ?? [];
        $runs = $trace['executions'] ?? []; $upstream = $trace['upstreamCalls'] ?? [];
        $calls = $trace['agentCalls'] ?? []; $requests = $trace['modelRequests'] ?? 0;
        $expected = Verifier::expected($before);
        $matches = array_values($before['expected'] ?? []);
        usort($matches, static fn ($a, $b) => $a['id'] <=> $b['id']);
        $empty = ['matches' => [], 'count' => 0];
        $values = [['draft' => $empty, 'field_denied' => $empty],
            ['matches' => $matches, 'count' => 3, 'returnedBySearch' => ['orchard' => 2, 'garden' => 2]]];
        $checks = [
            'liveCapture' => 1 === ($report['version'] ?? null) && 'data-surface-conversation' === ($report['kind'] ?? null)
                && 'live' === ($report['mode'] ?? null) && Conversation::PROTOCOL === ($report['protocol'] ?? null)
                && Conversation::PROMPT === ($report['systemPrompt'] ?? null) && Conversation::SETTINGS === ($report['generationSettings'] ?? null)
                && !isset($report['error']) && !isset($report['snapshotFailed']),
            'unchangedFixtureAndAccess' => Verifier::fixture($before) && Verifier::fixture($after)
                && ($before['contentHash'] ?? null) === ($after['contentHash'] ?? null)
                && ($before['account'] ?? null) === ($after['account'] ?? null) && ($before['nodes'] ?? null) === ($after['nodes'] ?? null)
                && 6 === count($before['sources'] ?? []) && ($before['sources'] ?? null) === ($after['sources'] ?? null),
            'unchangedBoundaries' => 'gvisor' === ($report['runtime']['executor'] ?? null)
                && 1 === preg_match('/\Asha256:[a-f0-9]{64}\z/D', $report['image'] ?? '')
                && ['subject' => 'surface-proof-reader', 'capabilities' => ['published.search']] === ($report['identity'] ?? null)
                && get_object_vars(new ExecutionLimits(wallTimeMs: 15000, toolTimeMs: 3000, maxToolCalls: 2)) + ['executionAttempts' => 2, 'modelRequests' => Conversation::REQUEST_LIMIT] === ($report['limits'] ?? null),
            'approvedProviderOnly' => ['drupal.search' => ['provider' => 'drupal', 'method' => 'search', 'source' => 'mcp', 'upstreamTool' => Connection::WIRE_TOOL]] === ($report['origins'] ?? null),
            'twoTurnsSameBudget' => 2 === count($turns) && 2 === count($runs) && Conversation::QUESTIONS === ($report['questions'] ?? null)
                && Conversation::QUESTIONS === array_column($turns, 'question')
                && [0, 1] === array_column($turns, 'executionStart') && [1, 2] === array_column($turns, 'executionEnd')
                && 0 === ($turns[0]['requestStart'] ?? null) && ($turns[0]['requestEnd'] ?? null) === ($turns[1]['requestStart'] ?? null)
                && $requests === ($turns[1]['requestEnd'] ?? null) && 'execution_limit' === ($report['attemptLimitAfterConversation'] ?? null),
            'restrictedSearchEmpty' => self::equal($values[0], $runs[0]['value'] ?? null),
            'permittedFollowUpCorrect' => self::equal($values[1], $runs[1]['value'] ?? null),
            'answersMatchExecution' => count($turns) === 2,
            'alternativeProposed' => false,
            'generatedPhpAndCleanup' => count($runs) === 2,
            'actualMcpArgumentsAndResults' => count($upstream) === 4,
        ];
        foreach ($turns as $i => $turn) {
            $answer = json_decode($turn['answer'] ?? '', true);
            $checks['answersMatchExecution'] = $checks['answersMatchExecution'] && isset($values[$i])
                && self::equal($values[$i], $answer['data'] ?? null) && is_string($answer['message'] ?? null) && '' !== trim($answer['message']);
            if (0 === $i) {
                $checks['alternativeProposed'] = is_string($answer['alternative'] ?? null) && '' !== trim($answer['alternative']);
            }
        }
        $interface = null;
        try {
            $definitions = array_map(static fn ($c) => new ToolDefinition($c['name'], $c['description'], json_decode(json_encode($c['inputSchema'], JSON_THROW_ON_ERROR)), isset($c['outputSchema']) ? json_decode(json_encode($c['outputSchema'], JSON_THROW_ON_ERROR)) : null), $report['contracts'] ?? []);
            $interface = new PhpToolInterface(['drupal' => ['search' => 'drupal.search']], new ToolCatalog($definitions));
        } catch (\Throwable) {}
        $checks['schemaDerivedInterface'] = null !== $interface && $interface->stubs() === ($report['interfaces'] ?? null);
        $phpCalls = array_values(array_filter($calls, static fn ($c) => 'php_execute' === ($c['name'] ?? null)));
        $checks['generatedPhpAndCleanup'] = $checks['generatedPhpAndCleanup'] && count($phpCalls) === 2;
        $ids = []; $brokerCalls = [];
        foreach ($runs as $i => $run) {
            $id = $run['runId'] ?? '';
            $checks['generatedPhpAndCleanup'] = $checks['generatedPhpAndCleanup'] && 'succeeded' === ($run['status'] ?? null)
                && null === ($run['reason'] ?? null) && true === ($run['containerRemoved'] ?? null)
                && 'gvisor' === ($run['runtime']['executor'] ?? null) && 1 === preg_match('/\Acode-mode-[a-f0-9]{32}\z/D', $id) && !in_array($id, $ids, true)
                && is_string($run['php'] ?? null) && '' !== $run['php'] && $run['php'] === ($phpCalls[$i]['arguments']['code'] ?? null)
                && $run['php'] !== 'return 1;' && null !== $interface && $interface->wrap($run['php']) === ($run['executedPhp'] ?? null)
                && hash('sha256', $run['executedPhp']) === ($run['sourceSha256'] ?? null)
                && self::equal(['value' => $run['value']], json_decode($phpCalls[$i]['result'] ?? '', true))
                && ['drupal.search', 'drupal.search'] === array_column($run['toolCalls'] ?? [], 'name')
                && ['succeeded', 'succeeded'] === array_column($run['toolCalls'] ?? [], 'status');
            $ids[] = $id;
            array_push($brokerCalls, ...($run['toolCalls'] ?? []));
        }
        $checks['hostLedgerLinked'] = $brokerCalls === ($trace['brokerCalls'] ?? null) && 4 === count($brokerCalls);
        foreach ([['PRIVATE_DRAFT_CANARY' => $empty, 'PRIVATE_FIELD_CANARY' => $empty], ['SCMQ orchard' => $expected['orchard'], 'SCMQ garden' => $expected['garden']]] as $i => $batch) {
            foreach (array_slice($upstream, 2 * $i, 2) as $offset => $event) {
                $keywords = $event['arguments']['values']['keywords'] ?? '';
                $checks['actualMcpArgumentsAndResults'] = $checks['actualMcpArgumentsAndResults'] && array_key_exists($keywords, $batch)
                    && self::equal(['values' => ['keywords' => $keywords, 'limit' => 10]], $event['arguments'] ?? null)
                    && self::equal(Verifier::sorted($batch[$keywords] ?? null), Verifier::sorted($event['value'] ?? null))
                    && 'succeeded' === ($event['status'] ?? null) && 'surface-proof-reader' === ($event['subject'] ?? null)
                    && 'drupal.search' === ($event['name'] ?? null) && Connection::WIRE_TOOL === ($event['upstreamTool'] ?? null)
                    && strlen(json_encode($event['value'] ?? null, JSON_THROW_ON_ERROR, 32)) === ($brokerCalls[2 * $i + $offset]['resultBytes'] ?? null);
                unset($batch[$keywords]);
            }
            $checks['actualMcpArgumentsAndResults'] = $checks['actualMcpArgumentsAndResults'] && [] === $batch;
        }
        $beforePhp = array_slice($calls, 0, array_search('php_execute', array_column($calls, 'name'), true) ?: 0);
        $checks['agentDiscoveredContract'] = in_array('tools_search', array_column($beforePhp, 'name'), true)
            && in_array(['drupal.search'], array_column(array_column(array_filter($beforePhp, static fn ($c) => 'tools_describe' === $c['name']), 'arguments'), 'names'), true);
        $responses = $trace['providerResponses'] ?? []; $evidence = $trace['requestEvidence'] ?? [];
        $checks['providerUsageAndAnswersObserved'] = $requests >= 4 && $requests <= Conversation::REQUEST_LIMIT && count($responses) === $requests && count($evidence) === $requests;
        $totals = ['prompt' => 0, 'completion' => 0, 'total' => 0];
        foreach ($responses as $i => $response) {
            $checks['providerUsageAndAnswersObserved'] = $checks['providerUsageAndAnswersObserved'] && $i + 1 === ($response['request'] ?? null);
            foreach ($totals as $key => $_) {
                $n = $response['tokens'][$key] ?? null;
                $checks['providerUsageAndAnswersObserved'] = $checks['providerUsageAndAnswersObserved'] && is_int($n) && $n >= 0;
                $totals[$key] += is_int($n) ? $n : 0;
            }
        }
        $checks['providerUsageAndAnswersObserved'] = $checks['providerUsageAndAnswersObserved'] && $totals['total'] > 0
            && $totals['prompt'] + $totals['completion'] === $totals['total'] && $totals === ($trace['providerTokens'] ?? null);
        foreach ($evidence as $i => $request) {
            $checks['providerUsageAndAnswersObserved'] = $checks['providerUsageAndAnswersObserved'] && $i + 1 === ($request['request'] ?? null)
                && hash('sha256', Conversation::PROMPT) === ($request['systemPromptSha256'] ?? null)
                && in_array($request['userMessageSha256'] ?? [], [array_map(static fn ($q) => hash('sha256', $q), array_slice(Conversation::QUESTIONS, 0, 1)), array_map(static fn ($q) => hash('sha256', $q), Conversation::QUESTIONS)], true);
        }
        foreach ($turns as $turn) {
            $checks['providerUsageAndAnswersObserved'] = $checks['providerUsageAndAnswersObserved']
                && hash('sha256', $turn['answer'] ?? '') === ($responses[($turn['requestEnd'] ?? 0) - 1]['answerSha256'] ?? null);
        }
        $followUp = $evidence[$turns[1]['requestStart'] ?? -1] ?? [];
        $checks['conversationRetained'] = ($followUp['userMessageSha256'] ?? null) === array_map(static fn ($q) => hash('sha256', $q), Conversation::QUESTIONS)
            && in_array(hash('sha256', $turns[0]['answer'] ?? ''), $followUp['assistantMessageSha256'] ?? [], true);
        return $checks;
    }
}
