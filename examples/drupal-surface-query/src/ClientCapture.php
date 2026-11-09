<?php

declare(strict_types=1);

namespace SurfaceProof;

use CodeMode\{ExecutionLimits, ToolCatalog, ToolDefinition};
use CodeMode\Provider\PhpToolInterface;

/** Offline reader of an external agent's events and independent host evidence. */
final class ClientCapture
{
    public function read(string $directory): array
    {
        $json = static fn ($file) => json_decode(file_get_contents($directory.'/'.$file), true, 64, JSON_THROW_ON_ERROR);
        $lines = static fn ($file) => array_map(static fn ($line) => json_decode($line, true, 64, JSON_THROW_ON_ERROR), file($directory.'/'.$file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $report = ['version' => 1, 'kind' => 'data-surface-client-conversation', 'client' => $json('client.json'),
            'events' => $json('client-events.json'), 'host' => $json('manifest.json'), 'after' => $json('after.json'),
            'executions' => $lines('executions.jsonl'), 'upstreamCalls' => $lines('upstream.jsonl')];
        $report['verification'] = $this->check($report);
        $report['status'] = in_array(false, $report['verification'], true) ? 'failed' : 'complete';
        return $report;
    }

    public function check(array $report): array
    {
        $client = $report['client'] ?? []; $host = $report['host'] ?? [];
        $before = $host['before'] ?? []; $after = $report['after'] ?? [];
        $turns = $client['turns'] ?? []; $runs = $report['executions'] ?? []; $upstream = $report['upstreamCalls'] ?? [];
        $events = $report['events'] ?? []; $calls = array_values(array_filter($events, static fn ($e) => 'mcpToolCall' === ($e['item']['type'] ?? null)));
        $phpCalls = array_values(array_filter($calls, static fn ($e) => 'php_execute' === ($e['item']['tool'] ?? null)));
        $expected = Verifier::expected($before); $empty = ['matches' => [], 'count' => 0];
        $matches = array_values($before['expected'] ?? []); usort($matches, static fn ($a, $b) => $a['id'] <=> $b['id']);
        $values = [['draft' => $empty, 'field_denied' => $empty], ['matches' => $matches, 'count' => 3, 'returnedBySearch' => ['orchard' => 2, 'garden' => 2]]];
        $checks = [
            'liveClientCompleted' => 'captured' === ($client['status'] ?? null) && !isset($client['error']) && !isset($client['clientError'])
                && 'Codex app-server' === ($client['client'] ?? null) && is_string($client['model'] ?? null)
                && 'openai' === ($client['modelProvider'] ?? null) && true === ($client['ephemeral'] ?? null),
            'sameConversation' => 2 === count($turns) && 2 === count($runs) && 2 === count($phpCalls)
                && Conversation::QUESTIONS === ($client['questions'] ?? null) && Conversation::QUESTIONS === array_column($turns, 'question')
                && ['completed', 'completed'] === array_column($turns, 'status') && Conversation::PROMPT === ($client['systemPrompt'] ?? null),
            'fixtureAndPermissionsUnchanged' => Verifier::fixture($before) && Verifier::fixture($after)
                && ($before['contentHash'] ?? null) === ($after['contentHash'] ?? null) && ($before['account'] ?? null) === ($after['account'] ?? null)
                && ($before['nodes'] ?? null) === ($after['nodes'] ?? null) && 6 === count($before['sources'] ?? []) && ($before['sources'] ?? null) === ($after['sources'] ?? null),
            'sameIsolatedLimits' => 'gvisor' === ($host['runtime']['executor'] ?? null) && 1 === preg_match('/\Asha256:[a-f0-9]{64}\z/D', $host['image'] ?? '')
                && get_object_vars(new ExecutionLimits(wallTimeMs: 15000, toolTimeMs: 3000, maxToolCalls: 2)) + ['executionAttempts' => 2] === ($host['limits'] ?? null)
                && 1 === preg_match('/\A[a-f0-9]{32}\z/D', $host['sessionId'] ?? ''),
            'approvedProviderOnly' => ['drupal.search' => ['provider' => 'drupal', 'method' => 'search', 'source' => 'mcp', 'upstreamTool' => Connection::WIRE_TOOL]] === ($host['origins'] ?? null),
            'answersMatchActualResults' => count($runs) === 2,
            'permittedAlternativeProposed' => false,
            'generatedPhpAndCleanup' => count($runs) === 2,
            'actualUpstreamCalls' => count($upstream) === 4,
            'clientEventsBindBothTurns' => count($turns) === 2,
        ];
        $interface = null;
        try {
            $definitions = array_map(static fn ($c) => new ToolDefinition($c['name'], $c['description'], json_decode(json_encode($c['inputSchema'], JSON_THROW_ON_ERROR)), isset($c['outputSchema']) ? json_decode(json_encode($c['outputSchema'], JSON_THROW_ON_ERROR)) : null), $host['contracts'] ?? []);
            $interface = new PhpToolInterface(['drupal' => ['search' => 'drupal.search']], new ToolCatalog($definitions));
        } catch (\Throwable) {}
        $checks['schemaDerivedInterface'] = null !== $interface && $interface->stubs() === ($host['interfaces'] ?? null);
        $ids = [];
        foreach ($runs as $i => $run) {
            $trace = $run['trace'] ?? []; $turn = $turns[$i] ?? []; $call = $phpCalls[$i] ?? [];
            $answers = array_values(array_filter($turn['messages'] ?? [], static fn ($m) => 'final_answer' === ($m['phase'] ?? null)));
            $answer = json_decode($answers[0]['text'] ?? '', true);
            $turnEvents = array_values(array_filter($events, static fn ($e) => ($e['turnId'] ?? null) === ($turn['turnId'] ?? null)));
            $users = array_values(array_filter($turnEvents, static fn ($e) => 'userMessage' === ($e['item']['type'] ?? null)));
            $finals = array_values(array_filter($turnEvents, static fn ($e) => 'agentMessage' === ($e['item']['type'] ?? null) && 'final_answer' === ($e['item']['phase'] ?? null)));
            $checks['clientEventsBindBothTurns'] = $checks['clientEventsBindBothTurns'] && count($users) === 1 && count($finals) === 1
                && ($users[0]['threadId'] ?? null) === ($client['threadId'] ?? null)
                && ($users[0]['item']['content'][0]['text'] ?? null) === ($turn['question'] ?? null)
                && ($finals[0]['item'] ?? null) === ($answers[0] ?? null);
            $checks['answersMatchActualResults'] = $checks['answersMatchActualResults'] && isset($values[$i]) && 1 === count($answers)
                && ConversationVerifier::equal($values[$i], $trace['value'] ?? null) && ConversationVerifier::equal($values[$i], $answer['data'] ?? null)
                && is_string($answer['message'] ?? null) && '' !== trim($answer['message']);
            if (0 === $i) { $checks['permittedAlternativeProposed'] = is_string($answer['alternative'] ?? null) && '' !== trim($answer['alternative']); }
            $id = $trace['runId'] ?? '';
            $checks['generatedPhpAndCleanup'] = $checks['generatedPhpAndCleanup'] && !isset($trace['error']) && 'gvisor' === ($trace['runtime']['executor'] ?? null)
                && true === ($trace['containerRemoved'] ?? null) && 1 === preg_match('/\Acode-mode-[a-f0-9]{32}\z/D', $id) && !in_array($id, $ids, true)
                && null !== $interface && is_string($run['code'] ?? null) && '' !== $run['code'] && $interface->wrap($run['code']) === ($trace['executedPhp'] ?? null)
                && hash('sha256', $trace['executedPhp']) === ($trace['sourceSha256'] ?? null)
                && $run['code'] === ($call['item']['arguments']['code'] ?? null) && 'completed' === ($call['item']['status'] ?? null)
                && ($client['threadId'] ?? null) === ($call['threadId'] ?? null) && ($turn['turnId'] ?? null) === ($call['turnId'] ?? null)
                && ConversationVerifier::equal(['value' => $trace['value']], $call['item']['result']['structuredContent'] ?? null)
                && ['drupal.search', 'drupal.search'] === array_column($trace['toolCalls'] ?? [], 'name')
                && ['succeeded', 'succeeded'] === array_column($trace['toolCalls'] ?? [], 'status');
            $ids[] = $id;
            $batch = 0 === $i ? ['PRIVATE_DRAFT_CANARY' => $empty, 'PRIVATE_FIELD_CANARY' => $empty] : ['SCMQ orchard' => $expected['orchard'], 'SCMQ garden' => $expected['garden']];
            foreach (array_slice($upstream, 2 * $i, 2) as $offset => $event) {
                $keywords = $event['arguments']['values']['keywords'] ?? '';
                $checks['actualUpstreamCalls'] = $checks['actualUpstreamCalls'] && isset($batch[$keywords])
                    && ConversationVerifier::equal(['values' => ['keywords' => $keywords, 'limit' => 10]], $event['arguments'] ?? null)
                    && ConversationVerifier::equal(Verifier::sorted($batch[$keywords] ?? null), Verifier::sorted($event['value'] ?? null))
                    && 'succeeded' === ($event['status'] ?? null) && 'surface-proof-reader' === ($event['subject'] ?? null)
                    && 'drupal.search' === ($event['name'] ?? null) && Connection::WIRE_TOOL === ($event['upstreamTool'] ?? null)
                    && strlen(json_encode($event['value'] ?? null, JSON_THROW_ON_ERROR, 32)) === ($trace['toolCalls'][$offset]['resultBytes'] ?? null);
                unset($batch[$keywords]);
            }
            $checks['actualUpstreamCalls'] = $checks['actualUpstreamCalls'] && [] === $batch;
        }
        $checks['observedMcpCallsUseCodeMode'] = count($calls) >= 4;
        foreach ($calls as $call) {
            $checks['observedMcpCallsUseCodeMode'] = $checks['observedMcpCallsUseCodeMode'] && 'data_surface' === ($call['item']['server'] ?? null)
                && ($client['threadId'] ?? null) === ($call['threadId'] ?? null)
                && in_array($call['item']['tool'] ?? null, ['tools_search', 'tools_describe', 'php_execute'], true);
        }
        $names = array_column(array_column($calls, 'item'), 'tool');
        $checks['agentDiscoveredContract'] = in_array('tools_search', $names, true) && in_array('tools_describe', $names, true)
            && array_search('tools_describe', $names, true) < array_search('php_execute', $names, true);
        return $checks;
    }
}
