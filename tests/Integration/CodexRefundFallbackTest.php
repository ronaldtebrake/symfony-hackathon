<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\RefundRecording\Proof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodexRefundFallbackTest extends TestCase
{
    #[DataProvider('changedObservations')]
    public function testRejectsAlteredCapture(string $field, mixed $replacement): void
    {
        $report = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/codex-refund-fallback.json'), true, flags: \JSON_THROW_ON_ERROR);
        Proof::verify($report);
        $report['attempts'][0]['wire'][4][$field] = $replacement;
        $this->expectException(\UnexpectedValueException::class);
        Proof::verify($report);
    }

    public static function changedObservations(): iterable
    {
        yield 'denied call ran a handler' => ['nativeEvents', ['requested']];
        yield 'denial leaked its old policy' => ['result', ['manualReviewAboveMinor' => 5000]];
        yield 'the follow-up checked another policy' => ['arguments', ['policyId' => 'other']];
        yield 'capture included private transport metadata' => ['sessionId', 'private'];
    }
}
