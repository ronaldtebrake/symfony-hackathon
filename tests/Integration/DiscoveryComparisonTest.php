<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\DiscoveryComparison;
use App\Demo\ReportStore;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DiscoveryComparisonTest extends WebTestCase
{
    public function testLargeCatalogRetainsFailuresPartialUsageAndPerQuestionCorrectness(): void
    {
        $report = $this->originalLarge();
        $this->assertSame(\App\Demo\DiscoveryCatalog::PROFILE, $report['catalogProfile']);
        $summary = (new DiscoveryComparison())->summarize($report);
        $this->assertTrue($summary['matchedConditions']);
        $this->assertTrue($summary['complete']);
        $this->assertFalse($summary['allCorrect']);
        $this->assertSame([0, 0], array_column($summary['totals'], 'correct'));
        $this->assertSame([1, 0], array_column($summary['totals'], 'correctAnswers'));
        $this->assertSame([13, 14], array_column($summary['totals'], 'modelRequests'));
        $this->assertSame([15340, 26612], array_column($summary['totals'], 'observedTokens'));
        $this->assertSame([12, 14], array_column($summary['totals'], 'meteredResponses'));
        $this->assertNull($summary['totals']['full']['providerTokens']);
        $this->assertSame(26612, $summary['totals']['compact']['providerTokens']);
        $this->assertSame([true, true, true, true], array_column($summary['rows'], 'catalogVerified'));
        $this->assertSame([false, false, false, false], array_column($summary['rows'], 'bodyReaderFound'));
        foreach ($report['attempts'] as $attempt) {
            $this->assertTrue((new \App\Demo\AdoptionProofVerifier())->verifyProbe($attempt['proof']['probe']));
        }
        $report['attempts'][1]['proof']['model'] = 'other';
        $this->assertFalse((new DiscoveryComparison())->summarize($report)['matchedConditions']);
    }

    public function testLargeCatalogVerifierRejectsAlteredFallbackAndSchemaEvidence(): void
    {
        $report = $this->originalLarge();
        $call = &$report['attempts'][1]['proof']['liveTrace']['agentCalls'][0];
        $result = json_decode($call['result'], true);
        foreach ($result as &$tool) {
            if (true === ($tool['requiresDescribe'] ?? null)) {
                $tool['requiresDescribe'] = false;
                break;
            }
        }
        unset($tool);
        $call['result'] = json_encode($result, \JSON_THROW_ON_ERROR);
        $this->assertFalse((new DiscoveryComparison())->summarize($report)['rows'][1]['catalogVerified']);
        $report['attempts'][0]['proof']['approvedTools'][0]['inputSchema']['additionalProperties'] = true;
        $this->assertFalse((new DiscoveryComparison())->summarize($report)['rows'][0]['catalogVerified']);
        $report['attempts'][2]['proof']['liveTrace']['requestEvidence'][0]['userMessageSha256'] = [hash('sha256', 'changed question')];
        $this->assertFalse((new DiscoveryComparison())->summarize($report)['rows'][2]['catalogVerified']);
    }

    public function testRefinedSearchAndBudgetGuidanceProduceCorrectMatchedAnswers(): void
    {
        $all = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/compact-discovery.json'), true, flags: \JSON_THROW_ON_ERROR);
        $report = $all['previousAttempts'][5];
        $summary = (new DiscoveryComparison())->summarize($report);
        $this->assertTrue($summary['allCorrect']);
        $this->assertSame([2, 2], array_column($summary['totals'], 'correct'));
        $this->assertSame([4, 4], array_column($summary['totals'], 'correctAnswers'));
        $this->assertSame([16, 14], array_column($summary['totals'], 'modelRequests'));
        $this->assertSame([31185, 31946], array_column($summary['totals'], 'providerTokens'));
        $this->assertSame([true, true, true, true], array_column($summary['rows'], 'bodyReaderFound'));
        $this->assertCount(7, $all['previousAttempts']);
        foreach ($all['previousAttempts'] as $previous) {
            $this->assertTrue((new DiscoveryComparison())->summarize($previous)['matchedConditions']);
        }
        foreach ($report['attempts'] as $attempt) {
            $this->assertTrue((new \App\Demo\AdoptionProofVerifier())->verifyProbe($attempt['proof']['probe']));
        }
        $call = &$report['attempts'][0]['proof']['liveTrace']['agentCalls'][0];
        $result = json_decode($call['result'], true);
        $result['truncated'] = !$result['truncated'];
        $call['result'] = json_encode($result, \JSON_THROW_ON_ERROR);
        $this->assertFalse((new DiscoveryComparison())->summarize($report)['rows'][0]['catalogVerified']);
    }

    public function testArchiveRetainsBothFailedOptimizationExperiments(): void
    {
        $report = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/compact-discovery.json'), true, flags: \JSON_THROW_ON_ERROR);
        $first = (new DiscoveryComparison())->summarize($report['previousAttempts'][6]);
        $this->assertTrue($first['allCorrect']);
        $this->assertSame([33027, 35006], array_column($first['totals'], 'providerTokens'));
        $last = (new DiscoveryComparison())->summarize($report);
        $this->assertTrue($last['matchedConditions']);
        $this->assertFalse($last['allCorrect']);
        $this->assertSame([0, 0], array_column($last['totals'], 'correct'));
        $this->assertSame([49526, 33726], array_column($last['totals'], 'providerTokens'));
        $this->assertSame([true, true, true, true], array_column($last['rows'], 'catalogVerified'));
        $before = (new DiscoveryComparison())->summarize($report['previousAttempts'][5]);
        $this->assertSame([0, 14], array_column($before['totals'], 'unusedContracts'));
        $this->assertSame([61234, 72060], array_column($before['totals'], 'replayedDiscoveryBytes'));
        foreach ($report['attempts'] as $attempt) {
            $this->assertTrue((new \App\Demo\AdoptionProofVerifier())->verifyProbe($attempt['proof']['probe']));
        }
    }

    public function testMatchedLiveResultsAndLocalProbeRemainIndependentlyVerifiable(): void
    {
        $report = $this->evidence();
        $summary = (new DiscoveryComparison())->summarize($report);
        $this->assertTrue($summary['allCorrect']);
        $this->assertTrue($summary['matchedConditions']);
        $this->assertSame(['full', 'compact', 'compact', 'full'], array_column($summary['rows'], 'discovery'));
        $this->assertSame([2, 2], array_column($summary['totals'], 'correct'));
        $this->assertSame([14, 10], array_column($summary['totals'], 'modelRequests'));
        $this->assertSame([16330, 10854], array_column($summary['totals'], 'providerTokens'));
        $all = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/compact-discovery.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertTrue((new DiscoveryComparison())->summarize($all['previousAttempts'][0])['allCorrect']);
    }

    public static function mismatches(): iterable
    {
        foreach (['model', 'prompt', 'schema', 'fixture', 'identity', 'limits', 'answer', 'body', 'usage', 'ledger', 'pending', 'failed', 'missingArm'] as $case) {
            yield [$case];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mismatches')]
    public function testMismatchFailureOrMissingArmCannotPass(string $case): void
    {
        $r = $this->evidence();
        $proof = &$r['attempts'][1]['proof'];
        match ($case) {
            'model' => $proof['model'] = 'other',
            'prompt' => $proof['systemPrompt'] = 'different instructions',
            'schema' => $proof['approvedTools'][0]['inputSchema'] = [],
            'fixture' => $proof['fixtureSha256'] = str_repeat('0', 64),
            'identity' => $proof['identity']['capabilities'][] = 'admin',
            'limits' => ++$proof['limits']['executionAttemptsPerConversation'],
            'answer' => $proof['turns'][1]['answer'] = '{"audited":48,"missing":[]}',
            'body' => $proof['turns'][0]['answer'] = '{"id":1,"title":"Article 1","body":"filtered"}',
            'usage' => ++$proof['liveTrace']['providerTokens']['total'],
            'ledger' => $proof['probe']['executions'][0]['toolCalls'][1]['status'] = 'succeeded',
            'pending' => $r['attempts'][1]['status'] = 'pending',
            'failed' => $r['attempts'][1]['status'] = 'failed',
            'missingArm' => $r['attempts'] = [$r['attempts'][0]],
        };
        $this->assertFalse((new DiscoveryComparison())->summarize($r)['allCorrect']);
    }

    public function testLocalIncompleteCaptureTakesPrecedenceOverReviewedEvidence(): void
    {
        $dir = sys_get_temp_dir().'/code-mode-discovery-'.bin2hex(random_bytes(6));
        mkdir($dir.'/docs/evidence', 0700, true);
        $bytes = json_encode($this->evidence(), \JSON_THROW_ON_ERROR);
        file_put_contents($dir.'/docs/evidence/compact-discovery.json', $bytes);
        try {
            $store = new ReportStore($dir);
            $this->assertSame($bytes, $store->readDiscoveryComparison());
            $store->saveDiscoveryComparison(['status' => 'failed']);
            $this->assertSame('failed', json_decode($store->readDiscoveryComparison(), true)['status']);
        } finally {
            unlink($dir.'/var/demo/discovery.json');
            rmdir($dir.'/var/demo');
            rmdir($dir.'/var');
            unlink($dir.'/docs/evidence/compact-discovery.json');
            rmdir($dir.'/docs/evidence');
            rmdir($dir.'/docs');
            rmdir($dir);
        }
    }

    public function testArchivedExperimentHasNoViewerEndpoint(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/discovery-report');
        self::assertResponseStatusCodeSame(404);
    }

    private function originalLarge(): array
    {
        $report = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/compact-discovery.json'), true, flags: \JSON_THROW_ON_ERROR);
        foreach ([$report, ...($report['previousAttempts'] ?? [])] as $batch) {
            if ('publishing-16-v1' === ($batch['catalogProfile'] ?? null) && !isset($batch['searchProtocol'])) {
                return $batch;
            }
        }
        throw new \RuntimeException('The original failed large-catalog batch must remain in evidence.');
    }

    private function evidence(): array
    {
        $report = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/compact-discovery.json'), true, flags: \JSON_THROW_ON_ERROR);
        foreach ([$report, ...($report['previousAttempts'] ?? [])] as $batch) {
            if ('live' === $batch['mode'] && 'publishing-2-v1' === ($batch['catalogProfile'] ?? 'publishing-2-v1')) {
                return $batch;
            }
        }
        throw new \RuntimeException('The original small-catalog comparison must remain in evidence.');
    }
}
