<?php

declare(strict_types=1);

namespace App\Demo\HttpConversation;

use App\Demo\ThreeWayAuditRunner;

final class Proof
{
    public static function verify(array $run): array
    {
        $calls = array_values(array_filter($run['http'] ?? [], static fn ($row) => 'tools/call' === ($row['request']['method'] ?? null)));
        $value = static fn ($i) => $calls[$i]['response']['result']['structuredContent'] ?? [];
        $operation = static fn ($i) => $calls[$i]['profiler']['operations'][0] ?? [];
        $read = ['calls' => [['name' => 'publication_read', 'arguments' => ['id' => 101]]]];
        $allowed = ['results' => [['name' => 'publication_read', 'value' => ['id' => 101, 'title' => 'A Symfony application owns its tools']]]];
        $denied = ['error' => ['reason' => 'tool_denied', 'tool' => 'publication_read', 'index' => 0], 'completedCalls' => 0];
        $answers = array_map(static fn ($turn) => json_decode($turn['answer'] ?? '', true), $run['turns'] ?? []);
        $trace = $run['trace'] ?? [];
        $consistent = [] !== $calls && \count($calls) === \count($trace['agentCalls'] ?? []);
        foreach ($calls as $i => $call) {
            $result = $call['response']['result'];
            $agent = $trace['agentCalls'][$i] ?? [];
            $consistent = $consistent && 200 === $call['httpStatus'] && $call['request']['id'] === $call['response']['id']
                && json_decode($result['content'][0]['text'] ?? '', true) === ($result['structuredContent'] ?? null)
                && ($result['isError'] ?? false) === isset($result['structuredContent']['error'])
                && ($agent['name'] ?? null) === $call['request']['params']['name']
                && ($agent['arguments'] ?? null) === $call['request']['params']['arguments']
                && ($agent['result'] ?? null) === ($result['content'][0]['text'] ?? null)
                && ($agent['bytesToModel'] ?? null) === \strlen($agent['result'] ?? '')
                && 1 === \count($call['profiler']['operations'] ?? []) && 0 === ($call['profiler']['dropped'] ?? null);
        }
        $responses = $trace['providerResponses'] ?? [];
        $requests = $trace['requestEvidence'] ?? [];
        $count = $trace['modelRequests'] ?? 0;
        $usage = $count >= 4 && $count <= 8 && \count($responses) === $count && \count($requests) === $count;
        foreach (['prompt', 'completion', 'total'] as $key) {
            $parts = array_map(static fn ($response) => $response['tokens'][$key] ?? null, $responses);
            $usage = $usage && !\in_array(null, $parts, true) && ($trace['providerTokens'][$key] ?? null) === array_sum($parts);
        }
        foreach ($responses as $i => $response) {
            $tokens = $response['tokens'] ?? [];
            $usage = $usage && ($response['request'] ?? null) === $i + 1 && ($requests[$i]['request'] ?? null) === $i + 1
                && \is_int($tokens['prompt'] ?? null) && \is_int($tokens['completion'] ?? null)
                && $tokens['prompt'] >= 0 && $tokens['completion'] >= 0
                && ($tokens['total'] ?? null) === $tokens['prompt'] + $tokens['completion'];
        }
        $prompt = 'revocation-v2' === ($run['protocol'] ?? null) ? Runner::PROMPT : Runner::PROMPT_V1;
        $history = ($run['systemPrompt'] ?? null) === $prompt && 2 === \count($run['turns'] ?? []);
        foreach ($run['turns'] ?? [] as $i => $turn) {
            $request = $requests[$turn['requestStart']] ?? [];
            $history = $history && ($turn['question'] ?? null) === (Runner::QUESTIONS[$i] ?? null)
                && ($request['userMessageSha256'] ?? null) === array_map(static fn ($q) => hash('sha256', $q), \array_slice(Runner::QUESTIONS, 0, $i + 1))
                && ($responses[($turn['requestEnd'] ?? 0) - 1]['answerSha256'] ?? null) === hash('sha256', $turn['answer'] ?? '');
            if (1 === $i) {
                $history = $history && \in_array(hash('sha256', $run['turns'][0]['answer'] ?? ''), $request['assistantMessageSha256'] ?? [], true);
            }
        }
        $toolDigests = array_map(static fn ($call) => ['bytes' => \strlen($call['result']), 'sha256' => hash('sha256', $call['result'])], $trace['agentCalls'] ?? []);
        $previousCount = 0;
        foreach ($requests as $request) {
            $received = $request['toolMessages'] ?? [];
            $history = $history && ($request['systemPromptSha256'] ?? null) === hash('sha256', $prompt)
                && $received === \array_slice($toolDigests, 0, \count($received)) && \count($received) >= $previousCount;
            $previousCount = \count($received);
        }
        $history = $history && $previousCount === \count($toolDigests);
        $advertised = array_values(array_filter($run['http'] ?? [], static fn ($row) => 'tools/list' === ($row['request']['method'] ?? null)));
        $names = array_column($advertised[0]['response']['result']['tools'] ?? [], 'name');
        sort($names);
        $query = strtolower(trim($calls[0]['request']['params']['arguments']['query'] ?? ''));
        $saved = array_values(array_filter($operation(2)['budget'] ?? [], static fn ($budget) => 'saved' === $budget['phase']));
        $lastSaved = [] === $saved ? [] : $saved[array_key_last($saved)];
        $checks = [
            'captureProtocol' => 'symfony-http-conversation/v1' === ($run['format'] ?? null)
                && \in_array($run['mode'] ?? null, ['live', 'test'], true)
                && ('test' === $run['mode'] || 'Hugging Face Inference Providers' === ($run['provider'] ?? null))
                && ThreeWayAuditRunner::MODEL === ($run['model'] ?? null) && Runner::SETTINGS === ($run['settings'] ?? null)
                && ['modelRequests' => 8, 'agentToolCalls' => 8, 'executionAttempts' => 2, 'callsPerAttempt' => 10] === ($run['limits'] ?? null),
            'completedWithoutError' => !isset($run['error']),
            'twoHttpEntryTools' => ['execute_tools', 'search_tools'] === $names,
            'actualMessagesAgree' => $consistent,
            'modelChoseExpectedFlow' => ['search_tools', 'execute_tools', 'execute_tools', 'search_tools'] === array_column(array_column(array_column($calls, 'request'), 'params'), 'name')
                && ['lookup', 'lookup', 'revoked', 'revoked'] === array_column($calls, 'phase'),
            'bm25Discovery' => '' !== $query && 'publication_read' !== $query && str_ends_with($run['searchComponent'] ?? '', '\\Bm25ToolSearch')
                && ['publication_read'] === array_column($value(0)['tools'] ?? [], 'name'),
            'authorizedRead' => $read === ($calls[1]['request']['params']['arguments'] ?? null) && $allowed === $value(1),
            'sameReadDeniedAfterRevocation' => $read === ($calls[2]['request']['params']['arguments'] ?? null) && $denied === $value(2),
            'revokedContractHidden' => [] === ($value(3)['tools'] ?? null) && 0 === ($value(3)['matched'] ?? null),
            'operatorChangedPermissionOnly' => 'demo-reader: publication.read_published revoked' === ($run['permissionChange']['output'] ?? null)
                && false === ($run['permissionChange']['budgetReset'] ?? null) && true === ($run['permissionChange']['sameConnection'] ?? null)
                && true === ($run['permissionChange']['sameUser'] ?? null),
            'nativeHandlerInvokedOnlyForAllowedRead' => [['name' => 'publication_read', 'phase' => 'requested'], ['name' => 'publication_read', 'phase' => 'succeeded']] === ($operation(1)['nativeEvents'] ?? null)
                && [] === ($operation(2)['nativeEvents'] ?? null) && 'tool_denied' === ($operation(2)['reason'] ?? null),
            'persistentBudgetConsumed' => 0 === ($operation(1)['budget'][0]['attempts'] ?? null) && 1 === ($operation(2)['budget'][0]['attempts'] ?? null)
                && 2 === ($lastSaved['attempts'] ?? null) && 2 === ($lastSaved['reservedCalls'] ?? null),
            'structuredAnswersMatch' => 'read' === ($answers[0]['status'] ?? null) && 101 === ($answers[0]['articleId'] ?? null)
                && 'A Symfony application owns its tools' === ($answers[0]['title'] ?? null) && true === ($answers[0]['contractVisible'] ?? null)
                && 'denied' === ($answers[1]['status'] ?? null) && 101 === ($answers[1]['articleId'] ?? null)
                && \array_key_exists('title', $answers[1] ?? []) && null === $answers[1]['title'] && false === ($answers[1]['contractVisible'] ?? null),
            'sameConversationHistory' => $history,
            'providerUsageReported' => 'test' === ($run['mode'] ?? null) || $usage,
            'resultBytesAgree' => ($trace['toolPayloadBytes'] ?? null) === array_sum(array_column($toolDigests, 'bytes')),
            'plainPhpRuntime' => 'in-process' === ($run['runtime']['execution'] ?? null) && false === ($run['runtime']['generatedCode'] ?? null)
                && [] === array_diff(['proc_open', 'pcntl_fork', 'posix_kill'], $run['runtime']['disabledFunctions'] ?? []),
            'cleanup' => true === ($run['cleanup']['applicationRemoved'] ?? null),
        ];

        return ['passed' => !\in_array(false, $checks, true), 'checks' => $checks,
            'scope' => 'Checks exact structured answers, linked HTTP/tool messages, profiler events, persistent budgets and history. One fixed conversation does not establish general reliability, prompt-injection resistance or comparative cost.'];
    }

    public static function load(string $root): array
    {
        $path = $root.'/docs/evidence/http-conversation.json';
        $report = is_file($path) ? json_decode(file_get_contents($path), true, 64, \JSON_THROW_ON_ERROR) : ['attempts' => []];
        foreach ($report['attempts'] as &$run) {
            if ('live' !== ($run['mode'] ?? null)) {
                throw new \UnexpectedValueException('Only reviewed live captures can be published as model evidence.');
            }
            $run['recordedVerification'] = $run['verification'] ?? null;
            $run['verification'] = self::verify($run);
            $run['currentSourceMatches'] = [] !== ($run['sourceSha256'] ?? []);
            foreach ($run['sourceSha256'] ?? [] as $path => $hash) {
                $current = str_starts_with($path, 'vendor/ronaldtebrake/') ? $root.'/packages/'.substr($path, \strlen('vendor/ronaldtebrake/')) : $root.'/examples/symfony/'.$path;
                $run['currentSourceMatches'] = $run['currentSourceMatches'] && is_file($current) && hash_file('sha256', $current) === $hash;
            }
        }

        return $report;
    }
}
