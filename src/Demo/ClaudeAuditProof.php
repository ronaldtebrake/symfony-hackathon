<?php

declare(strict_types=1);

namespace App\Demo;

use App\Demo\AuditMatrix\Tools;

/** Independent offline checks. A faithfully captured failure is not a correct audit. */
final class ClaudeAuditProof
{
    public static function verify(array $run, string $question): array
    {
        $native = $run['client']['nativeCodeMode'] ?? false;
        $catalog = 'catalog' === ($run['surface'] ?? null);
        $http = array_values(array_filter($run['http'] ?? [], static fn ($row) => 'tools/call' === ($row['request']['method'] ?? null)));
        $agent = array_values(array_filter($run['agentCalls'] ?? [], static fn ($call) => str_starts_with($call['name'] ?? '', 'mcp__symfony_audit__')));
        $blocks = array_column($run['code'] ?? [], null, 'id');
        $messages = [] !== $http && \count($http) === \count($agent);
        $ledgerMatches = true;
        $budgetsMatch = true;
        $resultsMatch = true;
        $executions = $reserved = $succeeded = $denied = 0;
        $coverage = array_fill_keys(Tools::NAMES, []);
        $ledger = [];
        foreach ($http as $row) {
            $params = $row['request']['params'];
            $match = null;
            foreach ($agent as $key => $candidate) {
                if ('mcp__symfony_audit__'.$params['name'] === $candidate['name'] && $params['arguments'] === $candidate['arguments']
                    && ($params['_meta']['claudecode/toolUseId'] ?? null) === $candidate['id']) {
                    $match = $candidate;
                    unset($agent[$key]);
                    break;
                }
            }
            $result = $row['response']['result'] ?? [];
            $value = $result['structuredContent'] ?? [];
            $failed = $result['isError'] ?? false;
            $messages = $messages && null !== $match && 200 === $row['httpStatus'] && $row['request']['id'] === ($row['response']['id'] ?? null)
                && json_decode($result['content'][0]['text'] ?? '', true) === $value && $failed === isset($value['error'])
                && $failed === ($match['isError'] ?? null) && 0 === ($match['turn'] ?? null);
            if ($native) {
                $messages = $messages && isset($blocks[$match['codeId'] ?? '']) && '<synthetic>' === ($match['origin'] ?? null);
            } else {
                $messages = $messages && null === ($match['codeId'] ?? null) && json_decode($match['result'] ?? '', true) === $value;
            }
            $operation = $row['trace']['operations'][0] ?? [];
            $budget = $operation['budget'] ?? [];
            $budgetsMatch = $budgetsMatch && ($budget[0]['attempts'] ?? null) === $executions && ($budget[0]['reservedCalls'] ?? null) === $reserved;
            $calls = 'search_tools' === $params['name'] ? [] : ($catalog ? $params['arguments']['calls'] : [['name' => $params['name'], 'arguments' => $params['arguments']]]);
            if ([] !== $calls) {
                ++$executions;
                $reserved += \count($calls);
            }
            $lastBudget = $budget[array_key_last($budget)] ?? [];
            $budgetsMatch = $budgetsMatch && ($lastBudget['attempts'] ?? null) === $executions && ($lastBudget['reservedCalls'] ?? null) === $reserved
                && $executions <= 20 && $reserved <= 20;
            $expectedEvents = [];
            $expectedStatuses = [];
            $completed = 0;
            foreach ($calls as $i => $call) {
                $allowed = \in_array($call['name'], Tools::NAMES, true);
                if ('cms_list_articles' !== $call['name']) {
                    foreach ($call['arguments']['ids'] ?? [] as $id) {
                        $allowed = $allowed && \is_int($id) && $id >= 1 && $id <= ('cms_get_articles' === $call['name'] ? 48 : 24);
                    }
                }
                $expectedStatuses[] = ['name' => $call['name'], 'status' => $allowed ? 'succeeded' : 'denied'];
                if (!$allowed) {
                    ++$denied;
                    $resultsMatch = $resultsMatch && $failed && ['error' => ['reason' => 'tool_denied', 'tool' => $call['name'], 'index' => $i], 'completedCalls' => $completed] === $value;
                    break;
                }
                ++$succeeded;
                ++$completed;
                $expectedEvents[] = ['name' => $call['name'], 'phase' => 'requested'];
                $expectedEvents[] = ['name' => $call['name'], 'phase' => 'succeeded'];
                if (!$failed) {
                    $actual = $catalog ? ($value['results'][$i]['value'] ?? null) : $value;
                    $expected = self::expectedRead($call);
                    $resultsMatch = $resultsMatch && null !== $expected && $actual === $expected;
                    if ($catalog) {
                        $resultsMatch = $resultsMatch && ($value['results'][$i]['name'] ?? null) === $call['name'];
                    }
                    foreach ($actual['items'] ?? [] as $item) {
                        $coverage[$call['name']][] = $item['id'];
                    }
                }
            }
            $actualStatuses = array_map(static fn ($call) => ['name' => $call['name'], 'status' => $call['status']], $operation['calls'] ?? []);
            $ledgerMatches = $ledgerMatches && $expectedStatuses === $actualStatuses && $expectedEvents === ($operation['nativeEvents'] ?? null)
                && 1 === \count($row['trace']['operations'] ?? []) && 0 === ($row['trace']['dropped'] ?? null)
                && ($operation['operation'] ?? null) === $params['name'] && ($operation['status'] ?? null) === ($failed ? 'failed' : 'succeeded');
            $ledger = array_merge($ledger, $actualStatuses);
        }
        foreach ($coverage as &$ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
        }
        unset($ids);
        $covered = range(1, 48) === $coverage['cms_list_articles'] && range(1, 48) === $coverage['cms_get_articles'] && range(1, 24) === $coverage['cms_get_media'];
        $turn = $run['turns'][0] ?? [];
        $answer = json_decode($turn['answer'] ?? '', true);
        $correctness = (new AuditVerifier())->verify($answer, 'fixture', $ledger);
        $ids = array_column($answer['findings'] ?? [], 'id');
        $sorted = $ids;
        sort($sorted);
        $correctness['passed'] = $correctness['passed'] && $covered && $ids === $sorted;
        $codeMatches = $native ? [] !== $blocks : [] === $blocks;
        foreach ($blocks as $block) {
            $codeMatches = $codeMatches && $block['sha256'] === hash('sha256', $block['code']) && $block['model'] === $run['client']['model'];
        }
        $onlyApprovedTools = true;
        foreach ($run['agentCalls'] ?? [] as $call) {
            $onlyApprovedTools = $onlyApprovedTools && (str_starts_with($call['name'], 'mcp__symfony_audit__') || ($native && 'searchTools' === $call['name']));
        }
        $usage = ClaudeUsage::summarize($run['usageRows'] ?? []);
        $usageMatches = $usage['complete'];
        foreach ($usage['responses'] as $response) {
            $usageMatches = $usageMatches && $response['model'] === $run['client']['model'] && 'medium' === ($response['effort'] ?? null)
                && false === ($response['sidechain'] ?? null) && 0 === $response['turn'];
        }
        $advertised = array_column(ClaudeComparisonProof::tools($run), 'name');
        $clientRows = array_values(array_filter($run['http'] ?? [], static fn ($row) => \in_array($row['request']['method'] ?? null, ['server/discover', 'initialize'], true)));
        $request = $clientRows[0]['request'] ?? [];
        $client = $request['params']['_meta']['io.modelcontextprotocol/clientInfo'] ?? $request['params']['clientInfo'] ?? [];
        $checks = [
            'sameSingleQuestion' => 1 === \count($run['turns'] ?? []) && ($turn['question'] ?? null) === $question,
            'actualInteractiveClient' => 'live' === ($run['mode'] ?? null) && 'claude-code' === ($client['name'] ?? null)
                && $client['version'] === ($run['client']['version'] ?? null) && true === ($run['client']['interactive'] ?? null)
                && $native === ($run['client']['nativeBannerObserved'] ?? null),
            'fullVersusCatalogAdvertisement' => ($catalog ? ['search_tools', 'execute_tools'] : Tools::NAMES) === $advertised
                && false === ($run['client']['enableToolSearch'] ?? null) && true === ($run['client']['alwaysLoad'] ?? null),
            'actualCallsBoundToClient' => $messages && [] === $agent,
            'generatedCodeMatchesMode' => $codeMatches,
            'noExternalToolCalls' => $onlyApprovedTools,
            'exactFixtureResponses' => $resultsMatch,
            'handlerAndDenialTraces' => $ledgerMatches,
            'persistentCallBudget' => $budgetsMatch,
            'completeMainConversationUsage' => $usageMatches,
            'answerHash' => hash('sha256', $turn['answer'] ?? '') === ($turn['answerSha256'] ?? null),
            'noGeneratedCodeOnServer' => ['serverExecution' => 'in-process', 'serverGeneratedCode' => false] === ($run['runtime'] ?? null),
            'cleanedDisposableApp' => true === ($run['cleanup']['applicationRemoved'] ?? null),
        ];
        $integrity = !\in_array(false, $checks, true);

        return ['passed' => $integrity && $correctness['passed'], 'captureVerified' => $integrity, 'checks' => $checks, 'correctness' => $correctness,
            'coverage' => array_map('count', $coverage), 'fullDataReturned' => $covered,
            'counts' => ['mcpCalls' => \count($http), 'executionSubmissions' => $executions, 'reservedCalls' => $reserved, 'succeededReads' => $succeeded, 'deniedReads' => $denied]];
    }

    public static function contracts(array $run): array
    {
        if ('direct' === $run['surface']) {
            $contracts = ClaudeComparisonProof::tools($run);
        } else {
            $contracts = [];
            foreach ($run['http'] as $row) {
                if ('search_tools' === ($row['request']['params']['name'] ?? null)) {
                    $contracts = array_merge($contracts, $row['response']['result']['structuredContent']['tools'] ?? []);
                }
            }
        }
        $result = [];
        foreach ($contracts as $contract) {
            $result[$contract['name']] = ['description' => $contract['description'], 'inputSchema' => $contract['inputSchema']];
        }
        ksort($result);

        return $result;
    }

    public static function load(string $root): array
    {
        $report = json_decode(file_get_contents($root.'/docs/evidence/claude-audit-matrix.json'), true, 64, \JSON_THROW_ON_ERROR);
        $matched = $report['protocol']['order'] === array_column($report['attempts'], 'arm');
        $first = $report['attempts'][0];
        foreach ($report['attempts'] as &$run) {
            $run['verification'] = self::verify($run, $report['protocol']['question']);
            $run['usage'] = ClaudeUsage::summarize($run['usageRows']);
            $run['currentSourceMatches'] = [] !== $run['sourceSha256'];
            foreach ($run['sourceSha256'] as $path => $hash) {
                $run['currentSourceMatches'] = $run['currentSourceMatches'] && is_file($root.'/'.$path) && hash_file('sha256', $root.'/'.$path) === $hash;
            }
            foreach (['sourceSha256', 'datasetSha256', 'limits', 'identity', 'runtime'] as $key) {
                $matched = $matched && $run[$key] === $first[$key];
            }
            foreach (['version', 'model', 'effort', 'permissionMode', 'builtInTools', 'strictMcpConfig', 'enableToolSearch', 'alwaysLoad'] as $key) {
                $matched = $matched && $run['client'][$key] === $first['client'][$key];
            }
            $matched = $matched && $run['verification']['checks']['sameSingleQuestion'] && self::contracts($run) === self::contracts($first);
            $turn = $run['turns'][0];
            $run['durationSeconds'] = isset($turn['answeredAt']) ? (float) (new \DateTimeImmutable($turn['answeredAt']))->format('U.u') - (float) (new \DateTimeImmutable($turn['askedAt']))->format('U.u') : null;
        }
        unset($run);
        $report['matched'] = $matched;
        $report['implementation'] = [];
        foreach (['Tools', 'Access', 'Gateway'] as $class) {
            $path = 'src/Demo/AuditMatrix/'.$class.'.php';
            $report['implementation'][] = ['path' => $path, 'source' => file_get_contents($root.'/'.$path)];
        }

        return $report;
    }

    private static function expectedRead(array $call): ?array
    {
        $fixture = new FixtureServer();
        $a = $call['arguments'];
        try {
            return match ($call['name']) {
                'cms_list_articles' => $fixture->listArticles($a['offset'], $a['limit'])->structuredContent,
                'cms_get_articles' => $fixture->getArticles($a['ids'])->structuredContent,
                'cms_get_media' => $fixture->getMedia($a['ids'])->structuredContent,
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }
}
