<?php

declare(strict_types=1);

namespace App\Demo;

/** Verify a retained native-client capture without running its generated JavaScript. */
final class ClaudeCodeModeProof
{
    public static function verify(array $run): array
    {
        $http = $run['http'] ?? [];
        $calls = array_values(array_filter($http, static fn ($row) => 'tools/call' === ($row['request']['method'] ?? null)));
        $native = array_values(array_filter($run['agentCalls'] ?? [], static fn ($call) => str_starts_with($call['name'] ?? '', 'mcp__symfony_catalog__')));
        $blocks = array_column($run['code'] ?? [], null, 'id');
        $value = static fn ($i) => $calls[$i]['response']['result']['structuredContent'] ?? [];
        $operation = static fn ($i) => $calls[$i]['profiler']['operations'][0] ?? [];
        $read = ['calls' => [['name' => 'publication_read', 'arguments' => ['id' => 101]]]];
        $messages = 4 === \count($calls) && 4 === \count($native);
        foreach ($calls as $i => $row) {
            $result = $row['response']['result'] ?? [];
            $call = $native[$i] ?? [];
            $code = $blocks[$call['codeId'] ?? ''] ?? [];
            $messages = $messages && 200 === ($row['httpStatus'] ?? null)
                && ($row['request']['id'] ?? null) === ($row['response']['id'] ?? null)
                && json_decode($result['content'][0]['text'] ?? '', true) === $value($i)
                && ($result['isError'] ?? false) === isset($value($i)['error'])
                && ($call['isError'] ?? false) === isset($value($i)['error'])
                && ($call['name'] ?? null) === 'mcp__symfony_catalog__'.$row['request']['params']['name']
                && ($call['arguments'] ?? null) === $row['request']['params']['arguments']
                && ($call['turn'] ?? null) === ($i < 2 ? 0 : 1)
                && ($code['turn'] ?? null) === ($call['turn'] ?? null)
                && str_contains($code['code'] ?? '', $call['name'] ?? 'missing')
                && str_contains($code['result'] ?? '', json_encode($value($i), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))
                && 1 === \count($row['profiler']['operations'] ?? []) && 0 === ($row['profiler']['dropped'] ?? null);
        }
        $generated = [] !== $blocks && \count($blocks) === \count($run['code'] ?? []);
        foreach ($blocks as $block) {
            $generated = $generated && ($block['model'] ?? null) === ($run['client']['model'] ?? null)
                && ($block['sha256'] ?? null) === hash('sha256', $block['code'] ?? '')
                && false === ($block['isError'] ?? null) && '' !== ($block['result'] ?? '');
        }
        $listed = array_values(array_filter($http, static fn ($row) => 'tools/list' === ($row['request']['method'] ?? null)));
        $names = array_column($listed[0]['response']['result']['tools'] ?? [], 'name');
        sort($names);
        $init = array_values(array_filter($http, static fn ($row) => 'initialize' === ($row['request']['method'] ?? null)));
        $client = $init[0]['request']['params']['clientInfo'] ?? [];
        $allowed = ['results' => [['name' => 'publication_read', 'value' => ['id' => 101, 'title' => 'A Symfony application owns its tools']]]];
        $denied = ['error' => ['reason' => 'tool_denied', 'tool' => 'publication_read', 'index' => 0], 'completedCalls' => 0];
        $turns = $run['turns'] ?? [];
        $change = $run['permissionChange'] ?? [];
        $saved = array_values(array_filter($operation(2)['budget'] ?? [], static fn ($row) => 'saved' === $row['phase']));
        $last = [] === $saved ? [] : $saved[array_key_last($saved)];
        $checks = [
            'nativeClientCapture' => 'claude-code-mode-http/v1' === ($run['format'] ?? null) && 'live' === ($run['mode'] ?? null)
                && true === ($run['client']['nativeCodeMode'] ?? null) && 'claude-code' === ($client['name'] ?? null)
                && ($client['version'] ?? null) === ($run['client']['version'] ?? null)
                && str_starts_with($run['client']['startupBanner'] ?? '', 'Code Mode (experimental) is on'),
            'generatedJavaScriptRetained' => $generated,
            'nativeCallsMatchHttpAndCode' => $messages,
            'twoAdvertisedTools' => ['execute_tools', 'search_tools'] === $names,
            'expectedFlow' => ['search_tools', 'execute_tools', 'execute_tools', 'search_tools'] === array_column(array_column(array_column($calls, 'request'), 'params'), 'name')
                && ['lookup', 'lookup', 'revoked', 'revoked'] === array_column($calls, 'phase'),
            'discoveryThenAuthorizedRead' => ['publication_read'] === array_column($value(0)['tools'] ?? [], 'name')
                && $read === ($calls[1]['request']['params']['arguments'] ?? null) && $allowed === $value(1),
            'identicalReadDenied' => $read === ($calls[2]['request']['params']['arguments'] ?? null) && $denied === $value(2),
            'sameSearchNowHidesContract' => ($calls[0]['request']['params']['arguments'] ?? null) === ($calls[3]['request']['params']['arguments'] ?? null)
                && [] === ($value(3)['tools'] ?? null) && 0 === ($value(3)['matched'] ?? null),
            'deniedHandlerNeverInvoked' => [['name' => 'publication_read', 'phase' => 'requested'], ['name' => 'publication_read', 'phase' => 'succeeded']] === ($operation(1)['nativeEvents'] ?? null)
                && [] === ($operation(2)['nativeEvents'] ?? null) && 'tool_denied' === ($operation(2)['reason'] ?? null),
            'persistentBudgetIncludesDenial' => 0 === ($operation(1)['budget'][0]['attempts'] ?? null)
                && 1 === ($operation(2)['budget'][0]['attempts'] ?? null) && 2 === ($last['attempts'] ?? null) && 2 === ($last['reservedCalls'] ?? null),
            'revocationBetweenTurns' => 2 === \count($turns) && true === ($change['sameUser'] ?? null) && true === ($change['sameSession'] ?? null)
                && false === ($change['budgetReset'] ?? null) && 'demo-reader: publication.read_published revoked' === ($change['output'] ?? null)
                && strtotime($turns[0]['answeredAt'] ?? '') < strtotime($change['at'] ?? '')
                && strtotime($change['at'] ?? '') < strtotime($turns[1]['askedAt'] ?? ''),
            'answersDistinguishEarlierData' => str_contains($turns[0]['answer'] ?? '', 'A Symfony application owns its tools')
                && str_contains($turns[1]['answer'] ?? '', 'The fresh read did not succeed, and the tool is no longer visible.')
                && str_contains($turns[1]['answer'] ?? '', 'came from the earlier read, before the revocation'),
            'oneBlockHandlesDeniedFollowUp' => isset($native[2]['codeId']) && ($native[2]['codeId'] ?? null) === ($native[3]['codeId'] ?? null),
            'plainSymfonyRuntime' => 'in-process' === ($run['runtime']['serverExecution'] ?? null) && false === ($run['runtime']['serverGeneratedCode'] ?? null),
            'isolatedRecordingStateAndCleanup' => true === ($run['installation']['disposableCopy'] ?? null)
                && false === ($run['installation']['existingRecordingStateChanged'] ?? null) && true === ($run['cleanup']['applicationRemoved'] ?? null),
        ];

        return ['passed' => !\in_array(false, $checks, true), 'checks' => $checks];
    }

    public static function load(string $root): array
    {
        $report = json_decode(file_get_contents($root.'/docs/evidence/claude-code-mode-http.json'), true, 64, \JSON_THROW_ON_ERROR);
        foreach ($report['attempts'] as &$run) {
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
