<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use CodeModeDemo\Drupal\ProofVerifier;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/examples/drupal/lib/ProofVerifier.php';

final class DrupalProofVerifierTest extends TestCase
{
    private function recorded(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/drupal-authorization-proof-2026-11-02.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testRecordedReadsHaveIndependentDrupalAndHostEvidence(): void
    {
        $report = $this->recorded();
        self::assertSame('complete', $report['status']);
        self::assertFalse($report['modelCalled']);
        self::assertSame('runsc', $report['runtime']);
        self::assertTrue($report['contentUnchanged']);
        self::assertTrue($report['accountStillRestricted']);
        self::assertCount(2, $report['cases']);
        foreach ($report['cases'] as $case) {
            self::assertTrue(ProofVerifier::casePassed($case, $report['fixture']));
            self::assertNotEmpty($case['runId']);
            self::assertStringNotContainsString('RESTRICTED-CONTENT-CANARY', json_encode($case));
        }
    }

    public function testUpstreamFailureWithoutDrupalDenialCannotPass(): void
    {
        $report = $this->recorded();
        $case = $report['cases'][1];
        $case['drupalEvents'] = [];
        self::assertFalse(ProofVerifier::casePassed($case, $report['fixture']));
        $case = $report['cases'][1];
        $case['reason'] = 'tool_timeout';
        self::assertFalse(ProofVerifier::casePassed($case, $report['fixture']));
        $case = $report['cases'][1];
        $case['drupalEvents'][0]['account'] = 1;
        self::assertFalse(ProofVerifier::casePassed($case, $report['fixture']));
        $case = $report['cases'][1];
        $case['drupalEvents'][] = ['event' => 'article_read', 'account' => 2, 'node' => 2];
        self::assertFalse(ProofVerifier::casePassed($case, $report['fixture']));
    }

    public function testLeakedContentMissingCleanupOrElevatedPermissionsCannotPass(): void
    {
        $report = $this->recorded();
        $case = $report['cases'][1];
        $case['value'] = ['title' => 'restricted data'];
        self::assertFalse(ProofVerifier::casePassed($case, $report['fixture']));
        $case = $report['cases'][0];
        $case['containerRemoved'] = false;
        self::assertFalse(ProofVerifier::casePassed($case, $report['fixture']));
        $fixture = $report['fixture'];
        $fixture['account']['permissions'][] = 'bypass node access';
        self::assertFalse(ProofVerifier::fixture($fixture));
        $fixture = $report['fixture'];
        $fixture['restricted']['exists'] = false;
        self::assertFalse(ProofVerifier::fixture($fixture));
    }
}
