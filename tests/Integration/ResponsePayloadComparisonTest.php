<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\ResponsePayloadComparison;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ResponsePayloadComparisonTest extends WebTestCase
{
    public function testCurrentFormatterPreservesResultsAndFailuresInEveryRetainedSample(): void
    {
        $report = (new ResponsePayloadComparison(dirname(__DIR__, 2)))->report();
        self::assertSame('verified', $report['status']);
        self::assertCount(9, $report['samples']);
        foreach ($report['samples'] as $sample) {
            $old = json_decode($sample['before'], true, flags: JSON_THROW_ON_ERROR);
            $new = json_decode($sample['after'], true, flags: JSON_THROW_ON_ERROR);
            if (isset($old['error'])) {
                self::assertSame($old['error']['reason'], $new['error']['reason']);
                self::assertArrayNotHasKey('value', $new);
            } else {
                self::assertSame($old['value'] ?? $old['result'], $new['value']);
            }
            self::assertSame(strlen($sample['after']), $sample['afterBytes']);
            self::assertLessThan($sample['beforeTokens'], $sample['afterTokens']);
            self::assertArrayNotHasKey('toolCalls', $new);
            self::assertArrayNotHasKey('runId', $new);
        }
    }

    public function testMeasurementsAreReadOnlyAndLoopbackOnly(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/response-payloads');
        self::assertResponseIsSuccessful();
        self::assertSame('verified', json_decode($client->getResponse()->getContent(), true)['status']);
        $client->request('POST', '/demo/response-payloads');
        self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/response-payloads', server: ['REMOTE_ADDR' => '203.0.113.1']);
        self::assertResponseStatusCodeSame(403);
    }
}
