<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\{ReportStore, SecurityProofRunner, SecurityProofVerifier};
use PHPUnit\Framework\TestCase;

final class SecurityProofTest extends TestCase
{
    public function testSuccessfulLookingValuesDoNotReplaceHostEvidence(): void
    {
        $verifier = new SecurityProofVerifier();
        $read = [['name' => 'cms_list_articles', 'status' => 'succeeded']];
        self::assertTrue($verifier->verify('authorized_read', 48, null, $read, true));
        self::assertFalse($verifier->verify('authorized_read', 48, null, [], true));
        self::assertFalse($verifier->verify('authorized_read', 47, null, $read, true));
        self::assertFalse($verifier->verify('authorized_read', 48, null, $read, null));
        self::assertFalse($verifier->verify('authorized_read', 48, null, $read, false));
        self::assertTrue($verifier->verify('infinite_loop', null, 'wall_timeout', $read, true));
        self::assertFalse($verifier->verify('infinite_loop', null, 'guest_failed', $read, true));
        self::assertFalse($verifier->verify('infinite_loop', null, 'wall_timeout', [], true), 'A timeout before the PHP read ran is not evidence of this loop.');
        self::assertFalse($verifier->verify('infinite_loop', null, 'wall_timeout', $read, false));
    }

    public function testAToolFailureDoesNotProveAuthorizationBlockedTheCall(): void
    {
        $verifier = new SecurityProofVerifier();
        $denied = [['name' => 'cms_delete_article', 'status' => 'denied', 'reason' => 'tool_denied']];
        self::assertTrue($verifier->verify('unauthorized_call', null, 'tool_denied', $denied, true));
        self::assertFalse($verifier->verify('unauthorized_call', null, 'tool_failed', $denied, true));
        self::assertFalse($verifier->verify('unauthorized_call', null, 'tool_denied', [], true));
        $denied[0]['status'] = 'incomplete';
        self::assertFalse($verifier->verify('unauthorized_call', null, 'tool_denied', $denied, true));
    }

    public function testFreshCheckoutCanInspectSecurityEvidenceWithoutHidingLocalFailures(): void
    {
        $root = sys_get_temp_dir().'/code-mode-security-view-'.bin2hex(random_bytes(8));
        mkdir($root.'/docs/evidence', 0700, true);
        $filename = 'security-proof-2026-11-01.json';
        $capture = file_get_contents(dirname(__DIR__, 2).'/docs/evidence/'.$filename);
        file_put_contents($root.'/docs/evidence/'.$filename, $capture);
        $store = new ReportStore($root);
        try {
            self::assertSame($capture, $store->readSecurityProof());
            $report = json_decode($capture, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('complete', $report['status']);
            foreach ($report['cases'] as $case) {
                self::assertTrue((new SecurityProofVerifier())->verify($case['id'], $case['value'], $case['reason'], $case['toolCalls'], $case['containerRemoved']));
            }
            $store->saveSecurityProof(['status' => 'failed', 'cases' => []]);
            self::assertSame('failed', json_decode($store->readSecurityProof(), true)['status']);
            file_put_contents($root.'/var/demo/security.json', str_repeat('x', 1048577));
            $this->expectException(\RuntimeException::class);
            $store->readSecurityProof();
        } finally {
            unlink($root.'/docs/evidence/'.$filename);
            unlink($root.'/var/demo/security.json');
            rmdir($root.'/docs/evidence'); rmdir($root.'/docs');
            rmdir($root.'/var/demo'); rmdir($root.'/var'); rmdir($root);
        }
    }

    public function testMissingRuntimeConfigurationCannotProduceSuccessfulProof(): void
    {
        $saved = $_SERVER['CODE_MODE_IMAGE'] ?? null;
        $_SERVER['CODE_MODE_IMAGE'] = '';
        $updates = [];
        try {
            $report = (new SecurityProofRunner(dirname(__DIR__, 2)))->run(static function (array $report) use (&$updates): void { $updates[] = $report['status']; });
            self::assertSame('failed', $report['status']);
            self::assertSame([], $report['cases']);
            self::assertSame(['running', 'failed'], $updates);
        } finally {
            if (null === $saved) {
                unset($_SERVER['CODE_MODE_IMAGE']);
            } else {
                $_SERVER['CODE_MODE_IMAGE'] = $saved;
            }
        }
    }
}
