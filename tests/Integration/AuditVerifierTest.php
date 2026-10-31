<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\AuditDataset;
use App\Demo\AuditVerifier;
use App\Demo\BackendFactory;
use PHPUnit\Framework\TestCase;

final class AuditVerifierTest extends TestCase
{
    public function testCorrectLookingReportRequiresSuccessfulReads(): void
    {
        $calls = array_map(static fn ($name): array => ['name' => $name, 'status' => 'incomplete'], BackendFactory::FIXTURE_TOOLS);
        self::assertFalse((new AuditVerifier())->verify((new AuditDataset())->expected(), 'fixture', $calls)['passed']);
    }

    public function testExternalResultsAreValidatedButNeverClaimedAsVerified(): void
    {
        $verifier = new AuditVerifier();
        self::assertNull($verifier->verify((new AuditDataset())->expected(), 'drupal', [])['passed']);
        self::assertFalse($verifier->verify(['audited' => -1, 'findings' => []], 'drupal', [])['passed']);
        self::assertFalse($verifier->verify(['audited' => 1, 'findings' => [['id' => 1, 'title' => 'Article', 'issues' => ['missing_alt', 'missing_alt']]]], 'drupal', [])['passed']);
    }
}
