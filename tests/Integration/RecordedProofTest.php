<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\ReportStore;
use PHPUnit\Framework\TestCase;

final class RecordedProofTest extends TestCase
{
    public function testMissingOrOversizedCodexCaptureCannotBeReadAsEvidence(): void
    {
        $root = sys_get_temp_dir().'/codex-proof-'.bin2hex(random_bytes(8));
        $store = new ReportStore($root);
        self::assertNull($store->readCodexProof());
        mkdir($root.'/docs/evidence', 0700, true);
        $path = $root.'/docs/evidence/codex-conversation-2026-11-01.json';
        try {
            file_put_contents($path, str_repeat(' ', 1048577));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('size limit');
            $store->readCodexProof();
        } finally {
            unlink($path);
            rmdir($root.'/docs/evidence');
            rmdir($root.'/docs');
            rmdir($root);
        }
    }
}
