<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Demo\AdoptionProofRunner;
use App\Demo\AdoptionProofVerifier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdoptionProofTest extends KernelTestCase
{
    public function testTruncatedSearchCannotAuthorizeAnOmittedToolAndRefinementCannotResetAttempts(): void
    {
        self::bootKernel();
        $docker = new \CodeMode\DockerEngine(json_decode(\App\Demo\BackendFactory::env('CODE_MODE_DOCKER_COMMAND', '["docker"]'), true, flags: JSON_THROW_ON_ERROR));
        $session = \App\Demo\DiscoveryCatalog::sessions(\App\Demo\BackendFactory::env('CODE_MODE_IMAGE'), $docker, new \CodeMode\ExecutionLimits())->create(new \CodeMode\ExecutionIdentity('reader', ['articles.read_published']));
        $session->describe(['article_metadata']);
        self::assertTrue($session->searchResult('article read')['truncated']);
        try { $session->execute('return $tools->call("publishing_read", ["id" => 1])->id;'); self::fail('Omitted tool activated.'); }
        catch (\CodeMode\SandboxException $error) { self::assertSame('tool_denied', $error->reason); }
        self::assertFalse($session->searchResult('complete body')['truncated']);
        $session->describe(['publishing_read']);
        self::assertSame(1, $session->execute('return $tools->call("publishing_read", ["id" => 1])->id;')->value);
        $session->searchResult('publishing_read');
        try { $session->execute('return 1;'); self::fail('Refinement reset attempts.'); }
        catch (\CodeMode\SandboxException $error) { self::assertSame('execution_limit', $error->reason); }
    }

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
        self::assertTrue((new AdoptionProofVerifier())->verifyProbe($report['probe']));
        $report['probe']['agentCalls'][1]['result'] = '[]';
        self::assertFalse((new AdoptionProofVerifier())->verifyProbe($report['probe']));
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
            self::assertCount(5, $report['probe']['agentCalls']);
        }
    }
}
