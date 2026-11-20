<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class EditorialReviewProofTest extends TestCase
{
    public function testCurrentSymfonyAppReproducesTheReviewerExchange(): void
    {
        $root = \dirname(__DIR__, 2);
        $process = new Process([\PHP_BINARY, $root.'/scripts/editorial-review-proof.php'], $root, timeout: 45);
        $process->mustRun();
        $fresh = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        $saved = json_decode(file_get_contents($root.'/docs/evidence/editorial-review.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame($this->withoutTimings($saved), $this->withoutTimings($fresh));
        $this->assertSame(['discover', 'findings', 'raw_articles', 'raw_media', 'refresh_after_revocation'], array_column($saved['calls'], 'label'));
        $this->assertTrue($saved['verified']);
        $this->assertNotContains(false, $saved['checks']);
        foreach ($saved['sourceSha256'] as $path => $hash) {
            $this->assertSame($hash, hash_file('sha256', $root.'/'.$path));
        }
    }

    private function withoutTimings(array $values): array
    {
        unset($values['durationMs']);
        foreach ($values as $key => $value) {
            if (\is_array($value)) {
                $values[$key] = $this->withoutTimings($value);
            }
        }

        return $values;
    }
}
