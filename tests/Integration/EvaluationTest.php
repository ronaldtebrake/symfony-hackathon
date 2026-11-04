<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\{DemoFactory, EvaluationRunner, EvaluationScenario, EvaluationVerifier};
use CodeMode\{ExecutionIdentity, ExecutionLimits, ExecutionResult, SandboxExecutor, ToolBroker};
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\AI\Platform\Bridge\HuggingFace\Factory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Console\Tester\CommandTester;

final class EvaluationTest extends WebTestCase
{
    private function noSandbox(): SandboxExecutor
    {
        return new class implements SandboxExecutor {
            public function execute(string $code, ToolBroker $broker, ExecutionIdentity $identity, array $allowedTools = [], ?ExecutionLimits $limits = null): ExecutionResult { throw new \LogicException('Direct tools must not execute PHP.'); }
        };
    }

    private function runner(): EvaluationRunner { return new EvaluationRunner(new DemoFactory(dirname(__DIR__, 2)), new MockHttpClient()); }

    public static function scenarios(): iterable { foreach (EvaluationScenario::IDS as $id) { yield $id => [$id]; } }

    #[DataProvider('scenarios')]
    public function testIndependentReferenceAnswersCoverageAndTimelines(string $id): void
    {
        $run = $this->runner()->run($id, 'direct', false, testSandbox: $this->noSandbox());
        self::assertSame('passed', $run['status'], json_encode($run['verification']));
        self::assertSame(0, $run['trace']['modelRequests']);
        self::assertNull($run['trace']['providerTokens']);
        self::assertSame([], $run['trace']['programs']);
        foreach (['answer', 'malformedAnswer', 'coverage', 'digest', 'timing', 'payload'] as $tamper) {
            $bad = $run;
            switch ($tamper) {
                case 'answer': $bad['turns'][0]['answer'] = '{}'; break;
                case 'malformedAnswer': $bad['turns'][0]['answer'] = '{"incomplete":'; break;
                case 'coverage': $bad['trace']['brokerCalls'] = []; break;
                case 'digest': foreach ($bad['trace']['timeline'] as &$span) { if ('broker' === $span['stage']) { $span['evidence']['resultSha256'] = 'invented'; } } unset($span); break;
                case 'timing': $bad['trace']['timeline'][0]['endMs'] = -1; break;
                case 'payload': ++$bad['trace']['toolPayloadBytes']; break;
            }
            self::assertFalse((new EvaluationVerifier())->verify($bad)['passed'], $tamper);
            self::assertArrayHasKey('answersCorrect', (new EvaluationVerifier())->verify($bad)['checks']);
        }
    }

    public function testLiveFollowUpUsesTheSameMessagesAndMeasuresDeferredRequests(): void
    {
        $round = 0;
        $http = new MockHttpClient(static function ($method, $url, $options) use (&$round): JsonMockResponse {
            ++$round;
            $body = json_decode($options['body'], true);
            self::assertSame(EvaluationScenario::SETTINGS['max_tokens'], $body['max_tokens']);
            $calls = match ($round) {
                1 => array_map(static fn ($offset) => ['mcp_1', ['offset' => $offset, 'limit' => 12]], [0, 12, 24, 36]),
                2 => array_map(static fn ($ids) => ['mcp_2', ['ids' => $ids]], array_chunk(range(1, 48), 12)),
                3, 4 => [],
                default => throw new \LogicException('Unexpected request.'),
            };
            if (4 === $round) {
                self::assertCount(2, array_filter($body['messages'], static fn ($m) => 'user' === $m['role']));
                self::assertCount(8, array_filter($body['messages'], static fn ($m) => 'tool' === $m['role']));
            }
            $message = ['role' => 'assistant', 'content' => [] === $calls ? json_encode(EvaluationScenario::expected('follow_up', $round - 3)) : null];
            foreach ($calls as $i => [$name, $args]) { $message['tool_calls'][] = ['id' => 'r'.$round.'_'.$i, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args)]]; }
            return new JsonMockResponse(['choices' => [['index' => 0, 'message' => $message, 'finish_reason' => [] === $calls ? 'stop' : 'tool_calls']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 25, 'total_tokens' => 125]]);
        });
        $run = $this->runner()->run('follow_up', 'direct', true, testPlatform: Factory::createPlatform('test-only', httpClient: $http), testSandbox: $this->noSandbox());
        self::assertSame('passed', $run['status'], json_encode($run['verification']));
        self::assertSame(4, $run['trace']['modelRequests']);
        self::assertSame(500, $run['trace']['providerTokens']['total']);
        self::assertCount(4, array_filter($run['trace']['timeline'], static fn ($s) => 'model' === $s['stage'] && 'succeeded' === $s['status']));
    }

    public function testProviderFailureRetainsTraceWithoutLeakingItsMessage(): void
    {
        $http = new MockHttpClient(static fn () => throw new \RuntimeException('SECRET-provider-diagnostics'));
        $run = $this->runner()->run('pagination', 'direct', true, testPlatform: Factory::createPlatform('test-only', httpClient: $http), testSandbox: $this->noSandbox());
        self::assertSame('failed', $run['status']);
        self::assertSame(1, $run['trace']['modelRequests']);
        self::assertStringNotContainsString('SECRET', json_encode($run));
        self::assertFalse($run['verification']['passed']);
    }

    public function testReportRemainsLocalReadOnlyAndCannotSelectPaths(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/evaluation-report?file=../../.env.local'); self::assertResponseIsSuccessful();
        self::assertSame('evaluation', json_decode($client->getResponse()->getContent(), true)['kind']);
        $client->request('POST', '/demo/evaluation-report'); self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/evaluation-report', server: ['REMOTE_ADDR' => '203.0.113.1']); self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/demo/evaluation-report', server: ['HTTP_HOST' => 'attacker.example']); self::assertResponseStatusCodeSame(403);
    }

    public function testReviewedBatchesKeepFailuresAndRecomputeComparableOutcomes(): void
    {
        $report = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/evaluation.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(3, $report['batches']);
        $verifier = new EvaluationVerifier();
        foreach ($report['batches'] as $batch) {
            self::assertCount(20, $batch['runs']);
            self::assertSame(2, $batch['repetitions']);
            self::assertSame(EvaluationScenario::IDS, $batch['scenarios']);
            foreach (EvaluationScenario::IDS as $scenario) {
                self::assertSame(EvaluationScenario::questions($scenario, $batch['manifest']['protocol']), $batch['manifest']['questions'][$scenario]);
            }
            foreach ($batch['runs'] as $run) {
                self::assertSame('passed' === $run['status'], $verifier->verify($run)['passed']);
                if ('reference' === $batch['mode']) { self::assertSame(0, $run['trace']['modelRequests']); }
            }
        }
        $latest = $report['batches'][2];
        foreach (['direct', 'code'] as $approach) {
            self::assertCount(8, array_filter($latest['runs'], static fn ($r) => $r['approach'] === $approach && 'passed' === $r['status']));
        }
        $join = $latest['runs'][2];
        self::assertFalse($verifier->verify($join)['checks']['answersCorrect']);
        self::assertTrue($verifier->verify($join)['checks']['hostCallsMatchFixture'], 'A failed media lookup followed by recovery remains inspectable.');
        $injection = $latest['runs'][9];
        $checks = $verifier->verify($injection)['checks'];
        self::assertFalse($checks['answersCorrect'], 'Malformed model JSON is an answer failure.');
        self::assertTrue($checks['injectionExposureObserved']);
        self::assertTrue($checks['boundaryEnforced']);
        self::assertTrue($checks['sourceAndCleanupLinked'], 'Correct PHP output does not make an incorrect final model answer pass.');
        $boundary = $latest['runs'][7];
        foreach ($boundary['trace']['timeline'] as &$span) { if ('cleanup' === $span['stage']) { $span['status'] = 'failed'; } } unset($span);
        self::assertFalse($verifier->verify($boundary)['passed']);
    }

    public function testInvalidCommandOptionsCannotStartOrOverwriteACapture(): void
    {
        $directory = sys_get_temp_dir().'/evaluation-unused-'.bin2hex(random_bytes(6));
        $command = new \App\Command\EvaluateCommand($this->runner(), new \App\Demo\ReportStore($directory), $directory);
        foreach ([['--repetitions' => '0'], ['--repetitions' => '4'], ['--scenario' => '../private']] as $options) {
            self::assertSame(2, (new CommandTester($command))->execute($options));
            self::assertDirectoryDoesNotExist($directory);
        }
    }

    public function testFreshCheckoutFallbackDoesNotHideAnInterruptedLocalBatch(): void
    {
        $directory = sys_get_temp_dir().'/evaluation-store-'.bin2hex(random_bytes(6));
        mkdir($directory.'/docs/evidence', 0700, true);
        file_put_contents($directory.'/docs/evidence/evaluation.json', '{"kind":"evaluation","batches":[]}');
        $store = new \App\Demo\ReportStore($directory);
        try {
            self::assertSame([], json_decode($store->readEvaluation(), true)['batches']);
            $store->saveEvaluation(['kind' => 'evaluation', 'batches' => [['status' => 'running']]]);
            self::assertSame('running', json_decode($store->readEvaluation(), true)['batches'][0]['status']);
        } finally {
            unlink($directory.'/var/demo/evaluation.json'); rmdir($directory.'/var/demo'); rmdir($directory.'/var');
            unlink($directory.'/docs/evidence/evaluation.json'); rmdir($directory.'/docs/evidence'); rmdir($directory.'/docs'); rmdir($directory);
        }
    }
}
