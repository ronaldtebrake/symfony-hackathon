<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\AdoptionProofVerifier;
use App\Demo\ReportStore;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdoptionProofTest extends WebTestCase
{
    public function testReviewedLiveCaptureAndEarlierFilteredAttemptRemainDistinct(): void
    {
        $report = self::evidence();
        $this->assertTrue((new AdoptionProofVerifier())->verify($report)['passed']);
        $this->assertSame(8614, $report['liveTrace']['providerTokens']['total']);
        $earlier = array_values(array_filter($report['previousAttempts'], static fn ($r) => 'live' === $r['mode']));
        $this->assertCount(1, $earlier);
        $checks = (new AdoptionProofVerifier())->verify($earlier[0]);
        $this->assertFalse($checks['passed']);
        $this->assertFalse($checks['checks']['poisonReachedModelAsToolData']);
        $this->assertTrue($checks['checks']['followUpMatchesFixture']);
        $this->assertSame(8763, $earlier[0]['liveTrace']['providerTokens']['total']);
    }

    public static function tampering(): iterable
    {
        foreach (['source', 'ledger', 'cleanup', 'answer', 'payload', 'usage', 'exposure', 'budget'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('tampering')]
    public function testMissingOrInconsistentEvidenceCannotPass(string $case): void
    {
        $r = self::evidence();
        match ($case) {
            'source' => $r['liveTrace']['programs'][0] = 'return "invented proof";',
            'ledger' => $r['probe']['executions'][0]['toolCalls'][1]['status'] = 'succeeded',
            'cleanup' => $r['liveTrace']['executions'][0]['containerRemoved'] = false,
            'answer' => $r['turns'][1]['answer'] = '{"audited":48,"missing":[]}',
            'payload' => ++$r['liveTrace']['toolPayloadBytes'],
            'usage' => ++$r['liveTrace']['providerTokens']['total'],
            'exposure' => $r['liveTrace']['requestEvidence'] = [],
            'budget' => $r['probe']['executions'][2]['reason'] = null,
        };
        $this->assertFalse((new AdoptionProofVerifier())->verify($r)['passed']);
    }

    public function testFreshCheckoutFallbackAndLocalFailurePrecedence(): void
    {
        $dir = sys_get_temp_dir().'/code-mode-adoption-'.bin2hex(random_bytes(6));
        mkdir($dir.'/docs/evidence', 0700, true);
        $bytes = json_encode(self::evidence(), \JSON_THROW_ON_ERROR);
        file_put_contents($dir.'/docs/evidence/adoption-proof.json', $bytes);
        try {
            $store = new ReportStore($dir);
            $this->assertSame($bytes, $store->readAdoptionProof());
            $store->saveAdoptionProof(['status' => 'failed']);
            $this->assertSame('failed', json_decode($store->readAdoptionProof(), true)['status']);
        } finally {
            unlink($dir.'/var/demo/adoption.json');
            rmdir($dir.'/var/demo');
            rmdir($dir.'/var');
            unlink($dir.'/docs/evidence/adoption-proof.json');
            rmdir($dir.'/docs/evidence');
            rmdir($dir.'/docs');
            rmdir($dir);
        }
    }

    public function testViewerIsReadOnlyLocalAndCannotSelectArbitraryFiles(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/adoption-report?file=../../.env.local');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $this->assertSame('adoption-proof', json_decode($client->getResponse()->getContent(), true)['kind']);
        $client->request('POST', '/demo/adoption-report');
        self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/adoption-report', server: ['REMOTE_ADDR' => '203.0.113.1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/demo/adoption-report', server: ['HTTP_HOST' => 'attacker.example']);
        self::assertResponseStatusCodeSame(403);
    }

    private static function evidence(): array
    {
        return json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/adoption-proof.json'), true, flags: \JSON_THROW_ON_ERROR);
    }
}
