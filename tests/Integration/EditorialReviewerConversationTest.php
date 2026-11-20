<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class EditorialReviewerConversationTest extends TestCase
{
    public function testRealClientCallsMatchTheReproducibleSdkResults(): void
    {
        $evidence = \dirname(__DIR__, 2).'/docs/evidence/';
        $agent = json_decode(file_get_contents($evidence.'editorial-review-agent.json'), true, flags: \JSON_THROW_ON_ERROR);
        $sdk = json_decode(file_get_contents($evidence.'editorial-review.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('symfony-editorial-review-agent-v1', $agent['format']);
        $this->assertSame($sdk['datasetSha256'], $agent['datasetSha256']);
        $this->assertSame(4, $agent['operatorChangeBeforeTurn']);
        $this->assertSame([1, 0, 2, 1], array_map('count', array_column($agent['turns'], 'http')));
        $reference = array_column($sdk['calls'], null, 'label');
        $labels = ['findings', 'raw_articles', 'raw_media', 'refresh_after_revocation'];
        foreach ($agent['turns'] as $turn) {
            $this->assertNotSame('', trim($turn['answer']));
            $this->assertCount(\count($turn['http']), $turn['calls']);
            foreach ($turn['http'] as $i => $exchange) {
                $expected = $reference[array_shift($labels)];
                $requested = $exchange['request']['params'];
                $this->assertSame(200, $exchange['httpStatus']);
                $this->assertSame('tools/call', $exchange['request']['method']);
                $this->assertSame($expected['request']['params']['name'], $requested['name']);
                $this->assertSame($expected['request']['params']['arguments'], $requested['arguments']);
                $this->assertSame('mcp__symfony_audit__'.$requested['name'], $turn['calls'][$i]['tool']);
                $this->assertSame($requested['arguments'], $turn['calls'][$i]['arguments']);
                $result = $exchange['response']['result'];
                $this->assertSame($expected['response']['result']['isError'], $result['isError']);
                $this->assertSame($expected['response']['result']['structuredContent'], $result['structuredContent']);
                $this->assertSame($result['structuredContent'], json_decode($result['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR));
                $this->assertSame($expected['trace']['sourceReads'], $exchange['trace']['sourceReads']);
            }
        }
        foreach ($sdk['followUp']['highestImpact'] as $image) {
            $this->assertStringContainsString(implode(', ', $image['article_ids']), $agent['turns'][1]['answer']);
        }
    }
}
