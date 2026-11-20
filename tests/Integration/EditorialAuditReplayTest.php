<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\EditorialAuditReplay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EditorialAuditReplayTest extends TestCase
{
    #[DataProvider('changes')]
    public function testCapturedPartialAndControlMustAgreeWithTheApplicationOracle(string $change): void
    {
        $root = \dirname(__DIR__, 2);
        $report = json_decode(file_get_contents($root.'/docs/evidence/editorial-audit-replay.json'), true, flags: \JSON_THROW_ON_ERROR);
        $revoked = &$report['runs']['revoked'];
        switch ($change) {
            case 'different source in archived capture':
                $report['source'] .= "\n";
                break;
            case 'original':
                EditorialAuditReplay::verify($report);
                $this->assertCount(0, $revoked['calls'][12]['trace']['operations'][0]['nativeEvents']);

                return;
            case 'result from the control copied after denial':
                $revoked['outcome']['media'] = $report['runs']['control']['outcome']['media'];
                break;
            case 'revoked before checkpoint delivery':
                $revoked['operator']['afterCall'] = 11;
                break;
            case 'handler invoked after revocation':
                $revoked['calls'][12]['trace']['operations'][0]['nativeEvents'][] = ['phase' => 'requested', 'name' => 'cms_get_media'];
                break;
            case 'media values hidden in text response':
                $revoked['calls'][12]['response']['result']['content'][] = ['type' => 'text', 'text' => 'extra record'];
                break;
        }
        $this->expectException(\UnexpectedValueException::class);
        EditorialAuditReplay::verify($report);
    }

    public static function changes(): iterable
    {
        foreach (['original', 'different source in archived capture', 'result from the control copied after denial', 'revoked before checkpoint delivery', 'handler invoked after revocation', 'media values hidden in text response'] as $case) {
            yield $case => [$case];
        }
    }
}
