<?php

declare(strict_types=1);

use Composition\{AuditDataset, Verifier};
use PHPUnit\Framework\TestCase;

final class ProofTest extends TestCase
{
    private function report(): array { return json_decode(file_get_contents(dirname(__DIR__).'/proof/reference.json'), true, 64, JSON_THROW_ON_ERROR); }
    public function testReviewedReferenceHasExactFindingsAndHostEvidence(): void
    {
        self::assertCount(10, (new AuditDataset())->expected()['findings']);
        self::assertNotContains(false, (new Verifier())->check($this->report()));
    }
    public function testMissingEvidenceOrTamperingCannotPass(): void
    {
        foreach (['value','toolCalls','sourceSha256','containerRemoved'] as $key) {
            $report = $this->report(); unset($report['executions']['audit'][$key]);
            self::assertContains(false, (new Verifier())->check($report), $key);
        }
        foreach (['restricted-article','restricted-media','raw-upstream-name','undiscovered-tool','attempt-budget','infinite-loop'] as $key) {
            $report = $this->report(); unset($report['executions'][$key]);
            self::assertContains(false, (new Verifier())->check($report), $key);
        }
        $report = $this->report(); $report['origins']['media.getMedia']['upstreamTool'] = 'admin_read';
        self::assertContains(false, (new Verifier())->check($report));
    }
}
