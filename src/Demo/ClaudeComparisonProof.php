<?php

declare(strict_types=1);

namespace App\Demo;

/** Offline checks for both paths. Failed attempts remain visible and keep their usage. */
final class ClaudeComparisonProof
{
    public static function verify(array $run, array $questions): array
    {
        $calls = array_values(array_filter($run['http'] ?? [], static fn ($row) => 'tools/call' === ($row['request']['method'] ?? null)));
        $agent = array_values(array_filter($run['agentCalls'] ?? [], static fn ($call) => str_starts_with($call['name'] ?? '', 'mcp__symfony_catalog__')));
        $blocks = array_column($run['code'] ?? [], null, 'id');
        $native = 'native' === ($run['path'] ?? null);
        $value = static fn ($i) => $calls[$i]['response']['result']['structuredContent'] ?? [];
        $operation = static fn ($i) => $calls[$i]['profiler']['operations'][0] ?? [];
        $messages = 4 === \count($calls) && \count($calls) === \count($agent);
        foreach ($calls as $i => $row) {
            $call = $agent[$i] ?? [];
            $result = $row['response']['result'] ?? [];
            $messages = $messages && 200 === ($row['httpStatus'] ?? null)
                && ($row['request']['id'] ?? null) === ($row['response']['id'] ?? null)
                && ($call['name'] ?? null) === 'mcp__symfony_catalog__'.$row['request']['params']['name']
                && ($call['arguments'] ?? null) === $row['request']['params']['arguments']
                && ($call['turn'] ?? null) === ($i < 2 ? 0 : 1)
                && json_decode($result['content'][0]['text'] ?? '', true) === $value($i)
                && ($result['isError'] ?? false) === isset($value($i)['error'])
                && ($call['isError'] ?? false) === isset($value($i)['error'])
                && 1 === \count($row['profiler']['operations'] ?? []) && 0 === ($row['profiler']['dropped'] ?? null);
            if ($native) {
                $block = $blocks[$call['codeId'] ?? ''] ?? [];
                $messages = $messages && ($block['turn'] ?? null) === ($call['turn'] ?? null)
                    && str_contains($block['code'] ?? '', $call['name'] ?? 'missing')
                    && str_contains($block['result'] ?? '', json_encode($value($i), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            } else {
                $messages = $messages && null === ($call['codeId'] ?? null) && json_decode($call['result'] ?? '', true) === $value($i);
            }
        }
        $codeMatches = $native ? [] !== $blocks : [] === $blocks;
        foreach ($blocks as $block) {
            $codeMatches = $codeMatches && ($block['model'] ?? null) === ($run['client']['model'] ?? null)
                && ($block['sha256'] ?? null) === hash('sha256', $block['code'] ?? '') && false === ($block['isError'] ?? null);
        }
        $usage = ClaudeUsage::summarize($run['usageRows'] ?? []);
        $usageMatches = $usage['complete'];
        foreach ($usage['responses'] as $response) {
            $usageMatches = $usageMatches && $response['model'] === ($run['client']['model'] ?? null)
                && ($response['effort'] ?? null) === ($run['client']['effort'] ?? null) && false === ($response['sidechain'] ?? null)
                && \in_array($response['turn'], [0, 1], true);
        }
        $turns = $run['turns'] ?? [];
        $answers = 2 === \count($turns) && 2 === \count($run['answerReview']['answers'] ?? []);
        foreach ($turns as $i => $turn) {
            $review = $run['answerReview']['answers'][$i] ?? [];
            $answers = $answers && '' !== ($turn['answer'] ?? '') && ($review['turn'] ?? null) === $i
                && true === ($review['correct'] ?? null) && ($review['sha256'] ?? null) === hash('sha256', $turn['answer'] ?? '')
                && ($turn['answerSha256'] ?? null) === ($review['sha256'] ?? null);
        }
        $init = array_values(array_filter($run['http'] ?? [], static fn ($row) => 'initialize' === ($row['request']['method'] ?? null)));
        $client = $init[0]['request']['params']['clientInfo'] ?? [];
        $names = array_column(self::tools($run), 'name');
        sort($names);
        $change = $run['permissionChange'] ?? [];
        $saved = array_values(array_filter($operation(2)['budget'] ?? [], static fn ($row) => 'saved' === $row['phase']));
        $last = [] === $saved ? [] : $saved[array_key_last($saved)];
        $read = ['calls' => [['name' => 'publication_read', 'arguments' => ['id' => 101]]]];
        $checks = [
            'sameQuestions' => 2 === \count($questions) && $questions === array_column($turns, 'question'),
            'expectedClientMode' => 'live' === ($run['mode'] ?? null) && 'claude-code' === ($client['name'] ?? null)
                && ($client['version'] ?? null) === ($run['client']['version'] ?? null)
                && $native === ($run['client']['nativeCodeMode'] ?? null) && true === ($run['client']['interactive'] ?? null)
                && (!$native || str_starts_with($run['client']['startupBanner'] ?? '', 'Code Mode (experimental) is on')),
            'generatedCodeMatchesPath' => $codeMatches,
            'actualCallsAndResultsMatch' => $messages,
            'completeMainConversationUsage' => $usageMatches,
            'twoEntryTools' => ['execute_tools', 'search_tools'] === $names,
            'discoveryReadRevokeReadSearch' => ['search_tools', 'execute_tools', 'execute_tools', 'search_tools'] === array_column(array_column(array_column($calls, 'request'), 'params'), 'name')
                && ['lookup', 'lookup', 'revoked', 'revoked'] === array_column($calls, 'phase'),
            'authorizedRead' => ['publication_read'] === array_column($value(0)['tools'] ?? [], 'name')
                && $read === ($calls[1]['request']['params']['arguments'] ?? null)
                && ['results' => [['name' => 'publication_read', 'value' => ['id' => 101, 'title' => 'A Symfony application owns its tools']]]] === $value(1),
            'sameReadDenied' => $read === ($calls[2]['request']['params']['arguments'] ?? null)
                && ['error' => ['reason' => 'tool_denied', 'tool' => 'publication_read', 'index' => 0], 'completedCalls' => 0] === $value(2),
            'sameSearchHidesContract' => ($calls[0]['request']['params']['arguments'] ?? null) === ($calls[3]['request']['params']['arguments'] ?? null)
                && [] === ($value(3)['tools'] ?? null) && 0 === ($value(3)['matched'] ?? null),
            'handlerOnlyRanBeforeRevocation' => [['name' => 'publication_read', 'phase' => 'requested'], ['name' => 'publication_read', 'phase' => 'succeeded']] === ($operation(1)['nativeEvents'] ?? null)
                && [] === ($operation(2)['nativeEvents'] ?? null) && 'tool_denied' === ($operation(2)['reason'] ?? null),
            'budgetNotReset' => 0 === ($operation(1)['budget'][0]['attempts'] ?? null) && 1 === ($operation(2)['budget'][0]['attempts'] ?? null)
                && 2 === ($last['attempts'] ?? null) && 2 === ($last['reservedCalls'] ?? null),
            'permissionChangedBetweenAnswers' => true === ($change['sameUser'] ?? null) && true === ($change['sameSession'] ?? null)
                && false === ($change['budgetReset'] ?? null) && 'demo-reader: publication.read_published revoked' === ($change['output'] ?? null)
                && strtotime($turns[0]['answeredAt'] ?? '') < strtotime($change['at'] ?? '')
                && strtotime($change['at'] ?? '') < strtotime($turns[1]['askedAt'] ?? ''),
            'reviewedAnswersMatchCapturedText' => $answers,
            'unchangedServerRuntime' => 'in-process' === ($run['runtime']['serverExecution'] ?? null) && false === ($run['runtime']['serverGeneratedCode'] ?? null),
            'disposableStateCleaned' => true === ($run['installation']['disposableCopy'] ?? null)
                && false === ($run['installation']['existingRecordingStateChanged'] ?? null) && true === ($run['cleanup']['applicationRemoved'] ?? null),
        ];

        return ['passed' => !\in_array(false, $checks, true), 'checks' => $checks];
    }

    public static function tools(array $run): array
    {
        $rows = array_values(array_filter($run['http'] ?? [], static fn ($row) => 'tools/list' === ($row['request']['method'] ?? null)));

        return $rows[0]['response']['result']['tools'] ?? [];
    }

    public static function load(string $root): array
    {
        $report = json_decode(file_get_contents($root.'/docs/evidence/claude-http-comparison.json'), true, 64, \JSON_THROW_ON_ERROR);
        foreach ($report['attempts'] as &$run) {
            $run['verification'] = self::verify($run, $report['protocol']['questions']);
            $run['usage'] = ClaudeUsage::summarize($run['usageRows'] ?? []);
            $run = HttpCatalogProof::verifyRunRevision($root, $run);
            $run['activeDurationSeconds'] = array_sum(array_map(static fn ($turn) => isset($turn['answeredAt']) ? (float) (new \DateTimeImmutable($turn['answeredAt']))->format('U.u') - (float) (new \DateTimeImmutable($turn['askedAt']))->format('U.u') : 0, $run['turns'] ?? []));
        }
        unset($run);
        $report['pairs'] = [];
        foreach (array_unique(array_column($report['attempts'], 'pair')) as $id) {
            $runs = array_values(array_filter($report['attempts'], static fn ($run) => $run['pair'] === $id));
            $first = $runs[0] ?? [];
            $second = $runs[1] ?? [];
            $matched = 2 === \count($runs) && ['regular', 'native'] === array_column($runs, 'path')
                && array_column($first['turns'] ?? [], 'question') === array_column($second['turns'] ?? [], 'question')
                && self::tools($first) === self::tools($second) && [] !== self::tools($first);
            foreach (['version', 'model', 'effort', 'permissionMode', 'builtInTools', 'strictMcpConfig'] as $key) {
                $matched = $matched && isset($first['client'][$key]) && ($first['client'][$key] ?? null) === ($second['client'][$key] ?? null);
            }
            foreach (['sourceSha256', 'installation', 'runtime'] as $key) {
                $matched = $matched && isset($first[$key]) && ($first[$key] ?? null) === ($second[$key] ?? null);
            }
            $report['pairs'][] = ['id' => $id, 'matched' => $matched, 'attemptIds' => array_column($runs, 'id'),
                'bothCorrect' => $matched && !\in_array(false, array_column(array_column($runs, 'verification'), 'passed'), true)];
        }

        return $report;
    }
}
