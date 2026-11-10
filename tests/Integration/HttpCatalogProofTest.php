<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\HttpCatalogProof;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HttpCatalogProofTest extends WebTestCase
{
    public function testReviewedFreshHttpProofAndSourceAreConsistent(): void
    {
        $report = HttpCatalogProof::load(dirname(__DIR__, 2));
        self::assertTrue($report['verification']['passed']);
        $tampered = $report['capture'];
        foreach ($tampered['calls'] as &$call) {
            if ('after_restart' === $call['label']) { $call['response']['result']['structuredContent'] = ['results' => []]; }
        }
        self::assertFalse(HttpCatalogProof::verify($tampered)['passed']);
    }

    public function testViewerReadsOnlyReviewedEvidenceAndCannotExecuteTools(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/http-catalog?file=../../.env.local');
        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode($client->getResponse()->getContent(), true)['verification']['passed']);
        $client->request('POST', '/demo/http-catalog'); self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/http-catalog', server: ['REMOTE_ADDR' => '203.0.113.1']); self::assertResponseStatusCodeSame(403);
    }
}
