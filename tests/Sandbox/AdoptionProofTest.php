<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Demo\AdoptionProofRunner;
use App\Demo\AdoptionProofVerifier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdoptionProofTest extends KernelTestCase
{
    public function testNativeSymfonyToolsEnforceDenialRecoveryAndSharedBudgetInGvisor(): void
    {
        self::bootKernel();
        foreach (\CodeMode\DiscoveryMode::cases() as $mode) {
            $report = self::getContainer()->get(AdoptionProofRunner::class)->run(discovery: $mode);
            self::assertSame('complete', $report['status'], json_encode($report['verification']));
            self::assertTrue((new AdoptionProofVerifier())->verifyProbe($report['probe']));
            self::assertNull($report['liveTrace']);
            self::assertSame(0, $report['probe']['modelRequests']);
            self::assertCount(\CodeMode\DiscoveryMode::Compact === $mode ? 4 : 5, $report['probe']['agentCalls']);
        }
    }
}
