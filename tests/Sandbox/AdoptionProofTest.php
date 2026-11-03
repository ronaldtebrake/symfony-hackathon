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
        $report = self::getContainer()->get(AdoptionProofRunner::class)->run();
        self::assertSame('complete', $report['status'], json_encode($report['verification']));
        self::assertTrue((new AdoptionProofVerifier())->verifyProbe($report['probe']));
        self::assertNull($report['liveTrace']);
        self::assertSame(0, $report['probe']['modelRequests']);
    }
}
