<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\Performance\{BaselineVerifier, DirectToolbox, FrozenDataset, PerformanceComparison, ReadDataset, RequestCollector};
use App\Demo\{ReportStore, RunTrace};
use CodeMode\{ExecutionIdentity, ExecutionLimits};
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Platform\Result\ToolCall;

final class PerformanceBaselineTest extends WebTestCase
{
    private function source(): string { return file_get_contents(dirname(__DIR__, 2).'/docs/evidence/performance-conversation.json'); }
    private function data(): FrozenDataset { return new FrozenDataset(json_decode($this->source(), true, flags: JSON_THROW_ON_ERROR)['dataset']); }
    private function evidence(): array { return json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/performance-baseline.json'), true, flags: JSON_THROW_ON_ERROR); }

    public function testViewerRechecksFixedEvidenceAndCannotStartInference(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/performance-baseline?file=../../.env.local'); self::assertResponseIsSuccessful();
        $r = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($r['comparison']['paired']); self::assertSame(8217, $r['comparison']['directTools']['total']);
        $client->request('POST', '/demo/performance-baseline'); self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/performance-baseline', server: ['REMOTE_ADDR' => '203.0.113.1']); self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/demo/performance-baseline', server: ['HTTP_HOST' => 'attacker.example']); self::assertResponseStatusCodeSame(403);
    }

    public function testLivePairCorrectlyReportsFewerRequestsButMoreCodeModeTokens(): void
    {
        $r = $this->evidence(); $summary = (new PerformanceComparison())->summarize($r, $this->source());
        self::assertTrue($summary['paired']);
        self::assertSame(8217, $summary['directTools']['total']); self::assertSame(7, $summary['directTools']['requests']);
        self::assertSame(5, $summary['directTools']['readerCalls']);
        self::assertSame(-6477, $summary['totalTokensSavedByCodeMode']); self::assertSame(1, $summary['modelRequestsSavedByCodeMode']);
        self::assertSame(-78.8, $summary['tokenReductionPercent']);
        self::assertSame([3535, 4682], array_column($summary['directTools']['turns'], 'total'));
        self::assertSame(['failed'], array_column(json_decode($this->source(), true)['previousAttempts'], 'status'));
    }

    public static function tampering(): iterable
    {
        foreach (['source', 'dataset', 'model', 'question', 'permissions', 'settings', 'schema', 'entry', 'prompt', 'answer', 'usage', 'request_usage', 'context', 'answer_hash', 'ledger', 'result', 'cleanup', 'boundary', 'budget', 'turn', 'canary'] as $case) { yield $case => [$case]; }
    }

    #[DataProvider('tampering')]
    public function testUnpairedIncorrectOrUnmeteredEvidenceCannotClaimSavings(string $case): void
    {
        $r = $this->evidence();
        match ($case) {
            'source' => $r['sourceCaptureSha256'] = str_repeat('0', 64),
            'dataset' => $r['datasetSha256'] = str_repeat('0', 64),
            'model' => $r['model'] = 'other',
            'question' => $r['questions'][1] = 'Other task',
            'permissions' => $r['identity']['capabilities'][] = 'admin',
            'settings' => $r['generationSettings']['temperature'] = 1,
            'schema' => $r['approvedTools'][0]['outputSchema']['additionalProperties'] = true,
            'entry' => $r['entryTools'] = [],
            'prompt' => $r['systemPrompt'] .= ' Hints for the answer',
            'answer' => $r['turns'][1]['answer'] = '{"queriesSaved":12}',
            'usage' => ++$r['trace']['providerTokens']['total'],
            'request_usage' => $r['trace']['providerResponses'][0]['tokens'] = null,
            'context' => $r['trace']['requestEvidence'][4]['assistantMessageSha256'] = [],
            'answer_hash' => $r['trace']['providerResponses'][6]['answerSha256'] = str_repeat('0', 64),
            'ledger' => $r['trace']['brokerCalls'][0]['arguments'] = ['admin' => true],
            'result' => $r['trace']['agentCalls'][0]['result'] = '{}',
            'cleanup' => $r['trace']['brokerCalls'][0]['workerClosed'] = false,
            'boundary' => $r['securityProof']['ledger'][1]['status'] = 'succeeded',
            'budget' => $r['limits']['maxToolCalls'] = 100,
            'turn' => $r['turns'][1]['agentCallStart'] = 0,
            'canary' => $r['trace']['agentCalls'][0]['result'] = RequestCollector::CANARY,
        };
        $summary = (new PerformanceComparison())->summarize($r, $this->source());
        self::assertFalse($summary['paired']); self::assertNull($summary['totalTokensSavedByCodeMode']);
        self::assertNull($summary['tokenReductionPercent']);
    }

    public function testLocalFailureAndPriorAttemptsTakePrecedenceWithoutRewritingEvidence(): void
    {
        $fs = new \Symfony\Component\Filesystem\Filesystem(); $root = sys_get_temp_dir().'/performance-baseline-'.bin2hex(random_bytes(6));
        try {
            $fs->mkdir($root.'/docs/evidence'); $r = $this->evidence();
            file_put_contents($root.'/docs/evidence/performance-baseline.json', json_encode($r));
            $failed = $r; $failed['status'] = 'failed'; $failed['error'] = 'request_budget'; $failed['previousAttempts'] = [$r];
            $store = new ReportStore($root); $store->savePerformanceBaseline($failed);
            self::assertSame($failed, json_decode($store->readPerformanceBaseline(), true));
            self::assertSame($r, json_decode((new ReportStore($root, reviewedOnly: true))->readPerformanceBaseline(), true));
            self::assertFalse((new PerformanceComparison())->summarize($failed, $this->source())['paired']);
        } finally { $fs->remove($root); }
    }

    public function testFrozenReadersReplayExactValuesAndExcludePrivateProfile(): void
    {
        $source = json_decode($this->source(), true, flags: JSON_THROW_ON_ERROR)['dataset']; $data = $this->data();
        self::assertSame($source['requests'], $data->requests());
        foreach ($source['profiles'] as $id => $profile) { self::assertSame($profile, $data->profile($id)); self::assertSame($source['logs'][$id], $data->logs($id)); }
        self::assertFalse($data->allowed('profile-04'));
        $this->expectException(\RuntimeException::class); $data->profile('profile-04');
    }

    public function testDirectReadersEnforcePolicySchemaAndHostOwnedCallBudget(): void
    {
        $trace = new RunTrace(); $trace->captureDetails = true;
        $tools = new DirectToolbox($this->data(), $trace, new ExecutionIdentity('local-performance-reader', ['performance.read_public'])); $tools->beginTurn(0);
        $tools->execute(new ToolCall('1', 'perf_profile', ['requestId' => 'profile-01']));
        $tools->execute(new ToolCall('2', 'perf_profile', ['requestId' => 'profile-04']));
        $tools->execute(new ToolCall('3', 'perf_logs', ['requestId' => 'profile-04']));
        $tools->execute(new ToolCall('4', 'unapproved_reader', []));
        for ($i = 4; $i < 11; ++$i) { $tools->execute(new ToolCall((string) $i, 'perf_requests', [])); }
        self::assertTrue(BaselineVerifier::security(['calls' => $trace->agentCalls, 'ledger' => $trace->brokerCalls], $this->data()));
        self::assertStringNotContainsString(RequestCollector::CANARY, json_encode($trace));
        $tools->beginTurn(1);
        $tools->execute(new ToolCall('next', 'perf_requests', []));
        self::assertSame('succeeded', $trace->brokerCalls[11]['status']);
        $tools->execute(new ToolCall('invalid', 'perf_profile', ['requestId' => 'profile-01', 'extra' => true]));
        self::assertNotSame('succeeded', $trace->brokerCalls[12]['status']);
        $this->expectException(\LogicException::class); $tools->beginTurn(1);
    }

    public function testMissingCapabilityAndOversizedInputFailClosed(): void
    {
        $trace = new RunTrace(); $trace->captureDetails = true;
        $tools = new DirectToolbox($this->data(), $trace, new ExecutionIdentity('local-performance-reader', [])); $tools->beginTurn(0);
        $tools->execute(new ToolCall('missing', 'perf_requests', []));
        self::assertSame('tool_denied', $trace->brokerCalls[0]['reason']);
        self::assertTrue($trace->brokerCalls[0]['workerClosed']);
        $tools->execute(new ToolCall('large', 'perf_profile', ['requestId' => str_repeat('a', 20000)]));
        self::assertSame('frame_limit', $trace->brokerCalls[1]['reason']);
        self::assertFalse($trace->brokerCalls[1]['workerClosed']);
    }

    public function testSlowDirectReaderIsTerminatedAndWorkerClosed(): void
    {
        $data = new class($this->data()) implements ReadDataset {
            public function __construct(private ReadDataset $inner) {}
            public function allowed(string $id): bool { return $this->inner->allowed($id); }
            public function requests(): array { usleep(500000); return $this->inner->requests(); }
            public function profile(string $id): array { return $this->inner->profile($id); }
            public function logs(string $id): array { return $this->inner->logs($id); }
        };
        $trace = new RunTrace(); $trace->captureDetails = true;
        $tools = new DirectToolbox($data, $trace, new ExecutionIdentity('local-performance-reader', ['performance.read_public']), new ExecutionLimits(toolTimeMs: 50));
        $tools->beginTurn(0); $tools->execute(new ToolCall('slow', 'perf_requests', []));
        self::assertSame('tool_timeout', $trace->brokerCalls[0]['reason']);
        self::assertTrue($trace->brokerCalls[0]['workerClosed']);
        self::assertSame(['error' => ['reason' => 'tool_timeout']], json_decode($trace->agentCalls[0]['result'], true));
    }
}
