<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Demo\AdoptionProofRunner;
use App\Demo\AdoptionProofVerifier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdoptionProofTest extends KernelTestCase
{
    public function testLargeCatalogUsesFallbackWithTheSameDenialRecoveryAndBudget(): void
    {
        self::bootKernel();
        $attempts = [];
        foreach (\CodeMode\DiscoveryMode::cases() as $mode) {
            $report = self::getContainer()->get(AdoptionProofRunner::class)->run(discovery: $mode, catalog: \App\Demo\DiscoveryCatalog::PROFILE);
            self::assertSame('complete', $report['status'], json_encode($report['verification']));
            self::assertCount(16, $report['approvedTools']);
            self::assertContains('publishing_list', $report['catalogSummary']['requiresDescribe']);
            self::assertSame(1, count(array_filter($report['probe']['agentCalls'], static fn ($call) => 'tools_describe' === $call['name'])));
            $attempts[] = ['repetition' => 1, 'discovery' => $mode->value, 'status' => $report['status'], 'proof' => json_decode(json_encode($report, JSON_THROW_ON_ERROR), true)];
        }
        $batch = ['mode' => 'local', 'catalogProfile' => \App\Demo\DiscoveryCatalog::PROFILE, 'attempts' => $attempts];
        self::assertTrue((new \App\Demo\DiscoveryComparison())->summarize($batch)['allCorrect']);
        $batch['attempts'][1]['proof']['probe']['agentCalls'][1]['result'] = '[]';
        self::assertFalse((new \App\Demo\DiscoveryComparison())->summarize($batch)['allCorrect']);
    }

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
