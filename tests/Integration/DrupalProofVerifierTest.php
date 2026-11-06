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
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/drupal-authorization-proof.json'), true, flags: JSON_THROW_ON_ERROR);
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
            self::assertArrayHasKey('agentResponse', $case);
            self::assertTrue(ProofVerifier::casePassed($case, $report['fixture']));
            self::assertNotEmpty($case['runId']);
            self::assertStringNotContainsString('RESTRICTED-CONTENT-CANARY', json_encode($case));
        }
    }

    public function testCompactAndFullDiscoveryUseTheSameRestrictedAccountAndData(): void
    {
        $compact = $this->recorded();
        $full = $compact['fullDiscoveryProof'];
        self::assertSame('compact', $compact['discoveryMode']);
        self::assertSame('full', $full['discoveryMode']);
        self::assertSame(1, $compact['discoveryRequests']);
        self::assertSame(2, $full['discoveryRequests']);
        self::assertSame($full['described'][0]['inputSchema'], $compact['discovery'][0]['inputSchema']);
        self::assertSame($full['fixture']['account'], $compact['fixture']['account']);
        self::assertSame($full['fixture']['contentHash'], $compact['fixture']['contentHash']);
        self::assertSame($full['limits'], $compact['limits']);
        self::assertSame($full['image'], $compact['image']);
        self::assertTrue($full['contentUnchanged']);
        self::assertTrue($full['accountStillRestricted']);
        foreach ($full['cases'] as $case) { self::assertTrue(ProofVerifier::casePassed($case, $full['fixture'])); }
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

    public function testAgentResponseCannotContainRestrictedContentOrHostLedger(): void
    {
        $report = $this->recorded();
        $case = $report['cases'][1];
        $case['agentResponse']['value'] = ['title' => 'private'];
        self::assertFalse(ProofVerifier::casePassed($case, $report['fixture']));
        $case = $report['cases'][0];
        $case['agentResponse']['toolCalls'] = $case['toolCalls'];
        self::assertFalse(ProofVerifier::casePassed($case, $report['fixture']));
    }
}
