<?php

declare(strict_types=1);

namespace App\Demo\RefundStudy;

use App\Demo\HintEvaluation\Study as HintStudy;
use App\Demo\HttpConversation\Runner;
use App\Demo\Refund\Fixture;
use App\Demo\Refund\Tools;
use App\Demo\ThreeWayAuditRunner;

final class Proof
{
    public const FORMAT = 'symfony-refund-skill-study/v1';

    public static function verify(array $report, string $root): void
    {
        $protocol = $report['protocol'];
        self::check(self::FORMAT === $report['format'] && ThreeWayAuditRunner::MODEL === $protocol['model']
            && Study::QUESTIONS === $protocol['questions'] && Study::PROMPT === $protocol['system'] && Runner::SETTINGS === $protocol['settings']
            && Study::ARMS === $protocol['order'] && Study::LIMIT === $protocol['maxProviderRequestsPerTurn'], 'protocol');
        self::check([] !== $report['attempts'] && 0 === \count($report['attempts']) % \count(Study::ARMS), 'complete arm sets');
        $schemas = [];
        foreach ($report['attempts'] as $i => $row) {
            $arm = $row['arm'];
            self::check(Study::ARMS[$i % 3] === $arm && 'live' === $row['source'], 'arm order');
            $discover = $row['hostCalls'];
            $userHashes = [];
            $reads = 0;
            if (isset($row['schemaSha256'])) {
                $group = $discover[2]['response']['result'];
                self::check(Study::GROUP === $discover[2]['request']['params']['name'] && 'groups/get' === $discover[2]['request']['method']
                    && 'groups/list' === $discover[1]['request']['method'] && 'server/discover' === $discover[0]['request']['method'], 'group selection');
                self::check(hash('sha256', json_encode($group['tools'], \JSON_THROW_ON_ERROR)) === $row['schemaSha256'], 'contracts');
                $schemas[] = $row['schemaSha256'];
                if (\array_key_exists('guidanceSha256', $row)) {
                    $j = 3;
                    $text = Study::guidance($arm, $group, static function ($method, $params) use ($discover, &$j): array {
                        $receipt = $discover[$j++];
                        self::check($method === $receipt['request']['method'] && $params['uri'] === $receipt['request']['params']['uri'], 'reviewed skill resource');

                        return $receipt['response']['result'];
                    }, file_get_contents($root.'/'.Fixture::SKILL_FILE));
                    if (null !== $text) {
                        $userHashes[] = hash('sha256', "Host-reviewed workflow reference for this group:\n\n".$text);
                    }
                    self::check((null === $text ? null : hash('sha256', $text)) === $row['guidanceSha256'] && $j === \count($discover), 'instruction identity');
                }
            }
            foreach ($row['turns'] as $k => $turn) {
                $stage = $turn['stage'];
                self::check($stage === array_keys(Study::QUESTIONS)[$k] && Study::QUESTIONS[$stage] === $turn['question'], 'turn order');
                self::check(Study::score($stage, $turn['answer'], $turn['calls']) === $turn['checks'], 'answer and read checks');
                $userHashes[] = hash('sha256', $turn['question']);
                foreach ($turn['requestEvidence'] as $evidence) {
                    self::check(hash('sha256', $protocol['system']) === $evidence['systemPromptSha256'] && $userHashes === $evidence['userMessageSha256'], 'model-visible question and guidance');
                }
                $n = $turn['providerRequests'];
                self::check($n <= Study::LIMIT && HintStudy::tokens($turn['providerResponses'], $n) === $turn['usage'], 'usage');
                if (\is_string($turn['answer'])) {
                    $responses = $turn['providerResponses'];
                    self::check(($responses[array_key_last($responses)]['answerSha256'] ?? null) === hash('sha256', $turn['answer']), 'provider answer');
                }
                foreach ($turn['calls'] as $call) {
                    $name = $call['request']['params']['name'];
                    $rpc = $call['response'];
                    self::check('reader' === $call['user'] && 200 === $call['httpStatus'] && $call['request']['id'] === $rpc['id'] && 'tools/call' === $call['request']['method']
                        && \in_array($name, Tools::NAMES, true) && hash('sha256', json_encode($rpc, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)) === $call['responseSha256'], 'HTTP exchange');
                    $operation = $call['trace']['operations'][0];
                    $budget = $operation['budget'];
                    self::check($reads === $budget[0]['attempts'] && $reads + 1 === $budget[array_key_last($budget)]['attempts'], 'shared user budget');
                    ++$reads;
                    if ('tool_denied' === ($rpc['result']['structuredContent']['error']['reason'] ?? null)) {
                        self::check(true === $rpc['result']['isError'] && 0 === $rpc['result']['structuredContent']['completedCalls']
                            && [] === $operation['nativeEvents'] && 'denied' === $operation['calls'][0]['status'], 'denial before handler');
                    } elseif (!($rpc['result']['isError'] ?? false)) {
                        self::check([['name' => $name, 'phase' => 'requested'], ['name' => $name, 'phase' => 'succeeded']] === $operation['nativeEvents'], 'successful native handler');
                    }
                }
                if ('revoked' === $stage) {
                    self::check(['action' => 'revoke', 'after' => 'review', 'before' => 'revoked', 'sameReader' => true, 'budgetReset' => false] === $row['operator'], 'operator');
                }
            }
            $pass = 2 === \count($row['turns']) && !\in_array(false, array_column(array_column($row['turns'], 'checks'), 'passed'), true);
            self::check(($pass ? 'passed' : 'failed') === $row['status'], 'verdict');
        }
        self::check(\count(array_unique($schemas)) <= 1, 'identical contracts');
    }

    private static function check(bool $pass, string $description): void
    {
        if (!$pass) {
            throw new \UnexpectedValueException('Invalid refund study evidence: '.$description);
        }
    }
}
