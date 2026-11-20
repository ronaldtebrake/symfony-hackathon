<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\HttpCatalogProof;
use PHPUnit\Framework\TestCase;

final class HttpCatalogProofTest extends TestCase
{
    public function testReviewedFreshHttpProofAndSourceAreConsistent(): void
    {
        $report = HttpCatalogProof::load(\dirname(__DIR__, 2));
        $this->assertTrue($report['verification']['passed']);
        $tampered = $report['capture'];
        foreach ($tampered['calls'] as &$call) {
            if ('after_restart' === $call['label']) {
                $call['response']['result']['structuredContent'] = ['results' => []];
            }
        }
        $this->assertFalse(HttpCatalogProof::verify($tampered)['passed']);
    }

    public function testRevocationRequiresTheSameArticleAndNoResults(): void
    {
        $report = HttpCatalogProof::load(\dirname(__DIR__, 2))['capture'];
        foreach ($report['calls'] as &$call) {
            if ('after_revocation' === $call['label']) {
                $call['request']['params']['arguments']['calls'][0]['arguments']['id'] = 202;
            }
        }
        $this->assertFalse(HttpCatalogProof::verify($report)['checks']['permissionRevokedAfterDiscovery']);
        unset($call);
        $report = HttpCatalogProof::load(\dirname(__DIR__, 2))['capture'];
        foreach ($report['calls'] as &$call) {
            if ('after_revocation' === $call['label']) {
                $call['response']['result']['structuredContent']['results'] = [['name' => 'publication_read', 'value' => ['id' => 101]]];
                $call['response']['result']['content'][0]['text'] = json_encode($call['response']['result']['structuredContent']);
            }
        }
        $this->assertFalse(HttpCatalogProof::verify($report)['checks']['permissionRevokedAfterDiscovery']);
    }
}
