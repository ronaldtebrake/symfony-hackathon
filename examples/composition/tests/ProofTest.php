<?php

declare(strict_types=1);

use Composition\AuditDataset;
use Composition\Verifier;
use PHPUnit\Framework\TestCase;

final class ProofTest extends TestCase
{
    public function testReviewedReferenceHasExactFindingsAndHostEvidence(): void
    {
        $this->assertCount(10, (new AuditDataset())->expected()['findings']);
        $this->assertNotContains(false, (new Verifier())->check($this->report()));
    }

    public function testMissingEvidenceOrTamperingCannotPass(): void
    {
        foreach (['value', 'toolCalls', 'sourceSha256', 'containerRemoved'] as $key) {
            $report = $this->report();
            unset($report['executions']['audit'][$key]);
            $this->assertContains(false, (new Verifier())->check($report), $key);
        }
        foreach (['restricted-article', 'restricted-media', 'raw-upstream-name', 'undiscovered-tool', 'attempt-budget', 'infinite-loop'] as $key) {
            $report = $this->report();
            unset($report['executions'][$key]);
            $this->assertContains(false, (new Verifier())->check($report), $key);
        }
        $report = $this->report();
        $report['origins']['media.getMedia']['upstreamTool'] = 'admin_read';
        $this->assertContains(false, (new Verifier())->check($report));
    }

    private function report(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/proof/reference.json'), true, 64, \JSON_THROW_ON_ERROR);
    }
}
