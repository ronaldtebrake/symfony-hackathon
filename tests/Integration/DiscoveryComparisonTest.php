<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\{DiscoveryComparison, ReportStore};
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DiscoveryComparisonTest extends WebTestCase
{
    private function evidence(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/compact-discovery.json'), true, flags: JSON_THROW_ON_ERROR);
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
        self::assertTrue((new DiscoveryComparison())->summarize($report['previousAttempts'][0])['allCorrect']);
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
        $client->request('POST', '/demo/discovery-report'); self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/discovery-report', server: ['REMOTE_ADDR' => '203.0.113.1']); self::assertResponseStatusCodeSame(403);
    }
}
