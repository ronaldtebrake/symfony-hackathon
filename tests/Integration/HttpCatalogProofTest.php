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

    public function testRevocationRequiresTheSameArticleAndNoResults(): void
    {
        $report = HttpCatalogProof::load(dirname(__DIR__, 2))['capture'];
        foreach ($report['calls'] as &$call) {
            if ('after_revocation' === $call['label']) { $call['request']['params']['arguments']['calls'][0]['arguments']['id'] = 202; }
        }
        self::assertFalse(HttpCatalogProof::verify($report)['checks']['permissionRevokedAfterDiscovery']);
        unset($call);
        $report = HttpCatalogProof::load(dirname(__DIR__, 2))['capture'];
        foreach ($report['calls'] as &$call) {
            if ('after_revocation' === $call['label']) {
                $call['response']['result']['structuredContent']['results'] = [['name' => 'publication_read', 'value' => ['id' => 101]]];
                $call['response']['result']['content'][0]['text'] = json_encode($call['response']['result']['structuredContent']);
            }
        }
        self::assertFalse(HttpCatalogProof::verify($report)['checks']['permissionRevokedAfterDiscovery']);
    }
}
