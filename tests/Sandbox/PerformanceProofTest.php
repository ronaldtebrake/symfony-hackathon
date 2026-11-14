<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Demo\Performance\ProofRunner;
use App\Demo\Performance\ProofVerifier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PerformanceProofTest extends KernelTestCase
{
    public function testMcpInvestigationFollowUpAndDeniedReadsRunInGvisor(): void
    {
        self::bootKernel();
        $report = self::getContainer()->get(ProofRunner::class)->run();
        $this->assertSame('complete', $report['status'], json_encode($report['verification']));
        $this->assertTrue((new ProofVerifier())->verify($report)['passed']);
        $this->assertCount(2, $report['turns']);
        $this->assertCount(2, $report['denials']);
    }
}
