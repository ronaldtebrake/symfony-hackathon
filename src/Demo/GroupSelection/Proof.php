<?php

declare(strict_types=1);

namespace App\Demo\GroupSelection;

use App\Demo\Groups\PublishingGroups;
use App\Demo\Refund\Fixture;
use App\Demo\Refund\Tools;
use App\Demo\RefundStudy\Study as RefundStudy;
use App\Demo\RunTrace;

/** Check the public receipts offline, including which tool definitions preceded each group choice. */
final class Proof
{
    public static function verify(array $report): void
    {
        self::expect('symfony-group-selection-v1' === ($report['format'] ?? null) && Study::protocol() === ($report['protocol'] ?? null), 'comparison protocol');
        $sets = [];
        foreach ($report['attempts'] as $a) {
            self::attempt($a);
            $sets[$a['run']][] = [$a['case'], $a['arm']];
        }
        self::expect([] !== $sets, 'at least one attempt set');
        foreach ($sets as $pairs) {
            self::expect(Study::ORDER === $pairs, 'every arm in counterbalanced order');
        }
    }

    public static function attempt(array $a, bool $guided = false): void
    {
        $case = $a['case'];
        $arm = $a['arm'];
        self::expect(isset(Study::QUESTIONS[$case]) && $a['question'] === Study::QUESTIONS[$case] && \in_array($arm, ['groups', 'catalog'], true), 'matched task');
        $count = $a['providerRequests'];
        self::expect(\is_int($count) && $count >= 0 && $count <= Study::LIMIT && $count === \count($a['requestEvidence']), 'bounded model requests');
        self::expect($a['usage'] === RunTrace::tokens($a['providerResponses'], $count), 'provider token sum');
        $score = Study::score($case, $a['answer'], $a['calls']);
        self::expect($score === $a['checks'] && $a['status'] === ($score['passed'] ? 'passed' : 'failed'), 'grounded correctness');
        foreach ($a['requestEvidence'] as $i => $request) {
            self::expect($i + 1 === $request['request'] && hash('sha256', Study::PROMPT) === $request['systemPromptSha256']
                && [hash('sha256', $a['question'])] === $request['userMessageSha256'], 'identical prompt, no skill or extra user message');
        }
        foreach ($a['providerResponses'] as $i => $response) {
            self::expect($i + 1 === $response['request'] && \in_array($response['reportedModel'], [null, RefundStudy::MODEL], true), 'model routing');
        }
        if (null !== $a['answer']) {
            $last = $a['providerResponses'][array_key_last($a['providerResponses'])] ?? [];
            self::expect(hash('sha256', $a['answer']) === ($last['answerSha256'] ?? null) && $count === \count($a['inputs']) && null !== $a['usage'], 'provider answer identity and accounting');
        }
        self::calls($a, $guided);
    }

    private static function calls(array $a, bool $guided): void
    {
        $groupArm = 'groups' === $a['arm'];
        $byRound = $selected = $remoteDefinitions = [];
        foreach ($a['calls'] as $index => $r) {
            self::expect('reader' === $r['user'] && 200 === $r['httpStatus'] && str_contains($r['cacheControl'], 'private')
                && str_contains($r['cacheControl'], 'no-store'), 'authenticated, non-shared reply');
            $request = json_decode(json_encode($r['request'], \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
            self::expect($request === json_decode($r['requestJson'], true, flags: \JSON_THROW_ON_ERROR)
                && $r['response'] === json_decode($r['responseJson'], true, flags: \JSON_THROW_ON_ERROR)
                && $r['responseSha256'] === hash('sha256', $r['responseJson'])
                && $index + 1 === $r['request']['id'] && $r['request']['id'] === $r['response']['id'], 'wire integrity');
            $round = $r['modelRequest'];
            $method = $r['request']['method'];
            self::expect(\is_int($round) && $round >= 0 && $round <= $a['providerRequests'], 'model request correlation');
            if (0 === $round) {
                self::expect($index < 2 && $method === (0 === $index ? 'server/discover' : ($groupArm ? 'groups/list' : 'tools/list')), 'host discovery before inference');
                if ('server/discover' === $method) {
                    self::expect(isset($r['response']['result']['capabilities']['extensions'][PublishingGroups::ID]), 'Groups negotiation');
                }
                if ('groups/list' === $method) {
                    $groups = $r['response']['result']['groups'];
                    self::expect(array_keys(Discovery::GROUPS) === array_column($groups, 'name')
                        && array_column(Discovery::GROUPS, 'description') === array_column($groups, 'description')
                        && [] === array_diff(array_merge(...array_map(array_keys(...), $groups)), ['name', 'description']), 'three lightweight group summaries');
                } elseif ('tools/list' === $method) {
                    $remoteDefinitions = $r['response']['result']['tools'];
                    self::expect(Discovery::CATALOG === array_column($remoteDefinitions, 'name'), 'two catalog tools');
                }
                continue;
            }
            $byRound[$round][] = $r;
            $params = $r['request']['params'];
            if ('groups/get' === $method) {
                $name = $params['name'];
                $result = $r['response']['result'];
                $instructions = $guided && 'refund-review' === $name ? Fixture::instructions() : null;
                self::expect($groupArm && isset(Discovery::GROUPS[$name]) && Discovery::GROUPS[$name]['members'] === array_column($result['tools'], 'name')
                    && $instructions === ($result['instructions'] ?? null) && !isset($result['resources']), 'members and guidance of selected group');
                if (null !== $instructions && 'passed' === $a['status']) {
                    $message = json_encode(['available' => array_column($result['tools'], 'name'), 'instructions' => $instructions], \JSON_THROW_ON_ERROR);
                    $receipt = ['bytes' => \strlen($message), 'sha256' => hash('sha256', $message)];
                    self::expect(\in_array($receipt, $a['requestEvidence'][$round]['toolMessages'] ?? [], true), 'reviewed procedure delivered as a tool result before next inference');
                }
                $selected[] = $name;
            } else {
                self::expect('tools/call' === $method && \in_array($params['name'], $groupArm ? Tools::NAMES : Discovery::CATALOG, true), 'native arm operation');
                $op = $r['trace']['operations'][0] ?? [];
                self::expect($params['name'] === ($op['operation'] ?? null), 'Symfony trace operation');
                foreach ($op['calls'] as $call) {
                    if ('succeeded' === $call['status']) {
                        self::expect(\in_array(['name' => $call['name'], 'phase' => 'succeeded'], $op['nativeEvents'], true), 'matching Symfony handler event');
                    }
                }
            }
        }
        self::expect($a['selectedGroups'] === $selected, 'selected group accounting');
        if ($guided && 'passed' === $a['status']) {
            self::guidedWorkflow($a['calls']);
        }
        $names = $groupArm ? ['open_group'] : Discovery::CATALOG;
        $prefix = static fn (string $name): string => 'symfony_'.$name;
        foreach ($a['inputs'] as $i => $input) {
            $round = $i + 1;
            self::expect($round === $input['request'] && array_map($prefix, $names) === array_column($input['definitions'], 'name'), 'model-visible tool definitions');
            foreach ($remoteDefinitions as $index => $definition) {
                $advertised = $input['definitions'][$index + ($groupArm ? 1 : 0)];
                self::expect($definition['description'] === $advertised['description'] && $definition['inputSchema'] === $advertised['parameters'], 'MCP contract exposed unchanged');
            }
            if ($groupArm) {
                self::expect(array_keys(Discovery::GROUPS) === $input['definitions'][0]['parameters']['properties']['name']['enum'], 'all choices offered to the model');
            }
            // A hallucinated tool name can be rejected locally. Only real MCP traffic appears in the receipt list.
            $modelCalls = $input['modelCalls'];
            foreach ($byRound[$round] ?? [] as $r) {
                $p = $r['request']['params'];
                $group = 'groups/get' === $r['request']['method'];
                $expected = ['name' => $prefix($group ? 'open_group' : $p['name']), 'arguments' => $group ? ['name' => $p['name']] : $p['arguments']];
                $match = array_search($expected, $modelCalls, true);
                self::expect(false !== $match, 'model-selected arguments match actual MCP request');
                unset($modelCalls[$match]);
                if ($group) {
                    $remoteDefinitions = $r['response']['result']['tools'];
                    $names = ['open_group', ...array_column($remoteDefinitions, 'name')];
                }
            }
        }
    }

    /** The second read must follow model receipt of the first read's policy reference. */
    private static function guidedWorkflow(array $calls): void
    {
        $openedRound = $orderRound = $policyId = null;
        foreach ($calls as $call) {
            $method = $call['request']['method'];
            $params = $call['request']['params'];
            $round = $call['modelRequest'];
            if ('groups/get' === $method) {
                $openedRound = 'refund-review' === $params['name'] ? $round : null;
                $orderRound = $policyId = null;
            }
            if ('tools/call' !== $method || null === $openedRound || $round <= $openedRound || !empty($call['response']['result']['isError'])) {
                continue;
            }
            if ('refund_get_order' === $params['name'] && ['orderId' => 'R-1042'] === $params['arguments']) {
                $policyId = $call['response']['result']['structuredContent']['policyId'] ?? null;
                $orderRound = $round;
            } elseif ('refund_get_policy' === $params['name'] && null !== $orderRound && $round > $orderRound
                && \is_string($policyId) && ['policyId' => $policyId] === $params['arguments']) {
                return;
            }
        }
        self::expect(false, 'opened refund group, read order and followed its policy on a later model request');
    }

    private static function expect(bool $valid, string $label): void
    {
        if (!$valid) {
            throw new \UnexpectedValueException('Invalid group-selection evidence: '.$label);
        }
    }
}
