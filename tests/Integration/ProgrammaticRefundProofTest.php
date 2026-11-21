<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\RefundRecording\ProgrammaticProof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProgrammaticRefundProofTest extends TestCase
{
    #[DataProvider('mismatches')]
    public function testRejectsClaimsThatDifferFromTheCapturedEvents(\Closure $change, string $reason): void
    {
        $receipt = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/programmatic-refund.json'), true, flags: \JSON_THROW_ON_ERROR);
        ProgrammaticProof::verify($receipt);
        $change($receipt);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($reason);
        ProgrammaticProof::verify($receipt);
    }

    public static function mismatches(): iterable
    {
        yield 'permission revoked too late' => [static function (array &$record): void {
            [$record['events'][3], $record['events'][4]] = [$record['events'][4], $record['events'][3]];
        }, 'reordered trace'];
        yield 'invented policy handler dispatch' => [static function (array &$record): void {
            $record['events'][4]['nativeEvents'][] = ['name' => 'refund_get_policy', 'phase' => 'requested'];
        }, 'denial before policy dispatch'];
        yield 'a different order reached the interpreter' => [static function (array &$record): void {
            $index = array_search($record['orderReadbackProgramId'], array_column($record['programs'], 'id'), true);
            $record['programs'][$index]['result']['text'] = str_replace('7500', '17500', $record['programs'][$index]['result']['text']);
        }, 'order retained by the client'];
        yield 'two separate scripts' => [static function (array &$record): void {
            $index = array_search($record['programId'], array_column($record['programs'], 'id'), true);
            $call = array_pop($record['programs'][$index]['calls']);
            $call['programId'] = $record['orderReadbackProgramId'];
            $last = array_key_last($record['programs']);
            $record['programs'][$last]['calls'][] = $call;
        }, 'same program'];
    }
}
