<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\Refund\ProtocolProof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefundProtocolTest extends TestCase
{
    #[DataProvider('mutations')]
    public function testWireCaptureBindsTheReviewedSkillAndPermissionChange(?string $mutation): void
    {
        $root = \dirname(__DIR__, 2);
        $report = json_decode(file_get_contents($root.'/docs/evidence/refund-protocol.json'), true, flags: \JSON_THROW_ON_ERROR);
        if (null !== $mutation) {
            $this->expectException(\UnexpectedValueException::class);
            switch ($mutation) {
                case 'changed skill':
                    $report['calls']['guide']['response']['result']['contents'][0]['text'] .= 'fake';
                    break;
                case 'denied handler reached':
                    $report['calls']['denied_policy']['trace']['operations'][0]['nativeEvents'][] = ['phase' => 'requested'];
                    break;
                case 'revocation moved':
                    $report['operator']['after'] = 'policy';
                    break;
                case 'invented discovery call':
                    $report['calls']['manifest']['request']['method'] = 'resources/list';
                    break;
                case 'different principal':
                    $report['calls']['viewer_guide']['user'] = 'reader';
                    break;
                case 'shared resource listing':
                    $call = &$report['calls']['top_level_resources'];
                    $call['response']['result']['cacheScope'] = 'public';
                    $call['responseJson'] = str_replace('"private"', '"public"', $call['responseJson']);
                    $call['responseSha256'] = hash('sha256', $call['responseJson']);
                    break;
            }
        }
        ProtocolProof::verify($report, $root);
        $this->assertSame('tool_denied', $report['calls']['denied_policy']['response']['result']['structuredContent']['error']['reason']);
    }

    public static function mutations(): iterable
    {
        foreach ([null, 'changed skill', 'denied handler reached', 'revocation moved', 'invented discovery call', 'different principal', 'shared resource listing'] as $case) {
            yield $case ?? 'recorded proof' => [$case];
        }
    }
}
