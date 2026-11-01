<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\{SecurityProofRunner, SecurityProofVerifier};
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
