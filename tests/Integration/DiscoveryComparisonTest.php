<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\{DiscoveryComparison, ReportStore};
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DiscoveryComparisonTest extends WebTestCase
{
    public function testLargeCatalogRetainsFailuresPartialUsageAndPerQuestionCorrectness(): void
    {
        $report = $this->originalLarge();
        self::assertSame(\App\Demo\DiscoveryCatalog::PROFILE, $report['catalogProfile']);
        $summary = (new DiscoveryComparison())->summarize($report);
        self::assertTrue($summary['matchedConditions']);
        self::assertTrue($summary['complete']);
        self::assertFalse($summary['allCorrect']);
        self::assertSame([0, 0], array_column($summary['totals'], 'correct'));
        self::assertSame([1, 0], array_column($summary['totals'], 'correctAnswers'));
        self::assertSame([13, 14], array_column($summary['totals'], 'modelRequests'));
        self::assertSame([15340, 26612], array_column($summary['totals'], 'observedTokens'));
        self::assertSame([12, 14], array_column($summary['totals'], 'meteredResponses'));
        self::assertNull($summary['totals']['full']['providerTokens']);
        self::assertSame(26612, $summary['totals']['compact']['providerTokens']);
        self::assertSame([true, true, true, true], array_column($summary['rows'], 'catalogVerified'));
        self::assertSame([false, false, false, false], array_column($summary['rows'], 'bodyReaderFound'));
        foreach ($report['attempts'] as $attempt) {
            self::assertTrue((new \App\Demo\AdoptionProofVerifier())->verifyProbe($attempt['proof']['probe']));
        }
        $report['attempts'][1]['proof']['model'] = 'other';
        self::assertFalse((new DiscoveryComparison())->summarize($report)['matchedConditions']);
    }

    public function testLargeCatalogVerifierRejectsAlteredFallbackAndSchemaEvidence(): void
    {
        $report = $this->originalLarge();
        $call = &$report['attempts'][1]['proof']['liveTrace']['agentCalls'][0];
        $result = json_decode($call['result'], true);
        foreach ($result as &$tool) { if (true === ($tool['requiresDescribe'] ?? null)) { $tool['requiresDescribe'] = false; break; } }
        unset($tool);
        $call['result'] = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertFalse((new DiscoveryComparison())->summarize($report)['rows'][1]['catalogVerified']);
        $report['attempts'][0]['proof']['approvedTools'][0]['inputSchema']['additionalProperties'] = true;
        self::assertFalse((new DiscoveryComparison())->summarize($report)['rows'][0]['catalogVerified']);
        $report['attempts'][2]['proof']['liveTrace']['requestEvidence'][0]['userMessageSha256'] = [hash('sha256', 'changed question')];
        self::assertFalse((new DiscoveryComparison())->summarize($report)['rows'][2]['catalogVerified']);
    }

    private function originalLarge(): array
    {
        $report = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/compact-discovery.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ([$report, ...($report['previousAttempts'] ?? [])] as $batch) {
            if ('publishing-16-v1' === ($batch['catalogProfile'] ?? null) && !isset($batch['searchProtocol'])) { return $batch; }
        }
        throw new \RuntimeException('The original failed large-catalog batch must remain in evidence.');
    }

    public function testRefinedSearchAndBudgetGuidanceProduceCorrectMatchedAnswers(): void
    {
        $report = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/compact-discovery.json'), true, flags: JSON_THROW_ON_ERROR);
        $summary = (new DiscoveryComparison())->summarize($report);
        self::assertTrue($summary['allCorrect']);
        self::assertSame([2, 2], array_column($summary['totals'], 'correct'));
        self::assertSame([4, 4], array_column($summary['totals'], 'correctAnswers'));
        self::assertSame([16, 14], array_column($summary['totals'], 'modelRequests'));
        self::assertSame([31185, 31946], array_column($summary['totals'], 'providerTokens'));
        self::assertSame([true, true, true, true], array_column($summary['rows'], 'bodyReaderFound'));
        self::assertCount(5, $report['previousAttempts']);
        foreach ($report['previousAttempts'] as $previous) { self::assertTrue((new DiscoveryComparison())->summarize($previous)['matchedConditions']); }
        foreach ($report['attempts'] as $attempt) { self::assertTrue((new \App\Demo\AdoptionProofVerifier())->verifyProbe($attempt['proof']['probe'])); }
        $call = &$report['attempts'][0]['proof']['liveTrace']['agentCalls'][0];
        $result = json_decode($call['result'], true); $result['truncated'] = !$result['truncated'];
        $call['result'] = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertFalse((new DiscoveryComparison())->summarize($report)['rows'][0]['catalogVerified']);
    }

    private function evidence(): array
    {
        $report = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/compact-discovery.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ([$report, ...($report['previousAttempts'] ?? [])] as $batch) {
            if ('live' === $batch['mode'] && 'publishing-2-v1' === ($batch['catalogProfile'] ?? 'publishing-2-v1')) { return $batch; }
        }
        throw new \RuntimeException('The original small-catalog comparison must remain in evidence.');
    }

    public function testMatchedLiveResultsAndLocalProbeRemainIndependentlyVerifiable(): void
    {
        $report = $this->evidence();
        $summary = (new DiscoveryComparison())->summarize($report);
        self::assertTrue($summary['allCorrect']);
        self::assertTrue($summary['matchedConditions']);
        self::assertSame(['full', 'compact', 'compact', 'full'], array_column($summary['rows'], 'discovery'));
        self::assertSame([2, 2], array_column($summary['totals'], 'correct'));
        self::assertSame([14, 10], array_column($summary['totals'], 'modelRequests'));
        self::assertSame([16330, 10854], array_column($summary['totals'], 'providerTokens'));
        $all = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/compact-discovery.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue((new DiscoveryComparison())->summarize($all['previousAttempts'][0])['allCorrect']);
    }

    public static function mismatches(): iterable
    {
        foreach (['model', 'prompt', 'schema', 'fixture', 'identity', 'limits', 'answer', 'body', 'usage', 'ledger', 'pending', 'failed', 'missingArm'] as $case) { yield [$case]; }
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
        self::assertFalse((new DiscoveryComparison())->summarize($r)['allCorrect']);
    }

    public function testLocalIncompleteCaptureTakesPrecedenceOverReviewedEvidence(): void
    {
        $dir = sys_get_temp_dir().'/code-mode-discovery-'.bin2hex(random_bytes(6));
        mkdir($dir.'/docs/evidence', 0700, true);
        $bytes = json_encode($this->evidence(), JSON_THROW_ON_ERROR);
        file_put_contents($dir.'/docs/evidence/compact-discovery.json', $bytes);
        try {
            $store = new ReportStore($dir);
            self::assertSame($bytes, $store->readDiscoveryComparison());
            $store->saveDiscoveryComparison(['status' => 'failed']);
            self::assertSame('failed', json_decode($store->readDiscoveryComparison(), true)['status']);
        } finally {
            unlink($dir.'/var/demo/discovery.json'); rmdir($dir.'/var/demo'); rmdir($dir.'/var');
            unlink($dir.'/docs/evidence/compact-discovery.json'); rmdir($dir.'/docs/evidence'); rmdir($dir.'/docs'); rmdir($dir);
        }
    }

    public function testViewerOnlyReadsTheFixedReportOnLoopback(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/discovery-report?file=../../.env.local');
        self::assertResponseIsSuccessful();
        $report = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('discovery-comparison', $report['kind']);
        self::assertSame((new DiscoveryComparison())->summarize($report), $report['summary']);
        foreach ($report['previousAttempts'] ?? [] as $previous) {
            self::assertSame((new DiscoveryComparison())->summarize($previous), $previous['summary']);
        }
        $client->request('POST', '/demo/discovery-report'); self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/discovery-report', server: ['REMOTE_ADDR' => '203.0.113.1']); self::assertResponseStatusCodeSame(403);
    }
}
