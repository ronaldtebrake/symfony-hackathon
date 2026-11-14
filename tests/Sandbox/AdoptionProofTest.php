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
        $docker = new \CodeMode\DockerEngine(json_decode(\App\Demo\BackendFactory::env('CODE_MODE_DOCKER_COMMAND', '["docker"]'), true, flags: \JSON_THROW_ON_ERROR));
        $session = \App\Demo\DiscoveryCatalog::sessions(\App\Demo\BackendFactory::env('CODE_MODE_IMAGE'), $docker, new \CodeMode\ExecutionLimits())->create(new \CodeMode\ExecutionIdentity('reader', ['articles.read_published']));
        $session->describe(['article_metadata']);
        $this->assertTrue($session->searchResult('article read')['truncated']);
        try {
            $session->execute('return $tools->call("publishing_read", ["id" => 1])->id;');
            $this->fail('Omitted tool activated.');
        } catch (\CodeMode\SandboxException $error) {
            $this->assertSame('tool_denied', $error->reason);
        }
        $this->assertFalse($session->searchResult('complete body')['truncated']);
        $session->describe(['publishing_read']);
        $this->assertSame(1, $session->execute('return $tools->call("publishing_read", ["id" => 1])->id;')->value);
        $session->searchResult('publishing_read');
        try {
            $session->execute('return 1;');
            $this->fail('Refinement reset attempts.');
        } catch (\CodeMode\SandboxException $error) {
            $this->assertSame('execution_limit', $error->reason);
        }
    }

    public function testLargeCatalogUsesFallbackWithTheSameDenialRecoveryAndBudget(): void
    {
        self::bootKernel();
        $attempts = [];
        foreach (\CodeMode\DiscoveryMode::cases() as $mode) {
            $report = self::getContainer()->get(AdoptionProofRunner::class)->run(discovery: $mode, catalog: \App\Demo\DiscoveryCatalog::PROFILE);
            $this->assertSame('complete', $report['status'], json_encode($report['verification']));
            $this->assertCount(16, $report['approvedTools']);
            $this->assertContains('publishing_list', $report['catalogSummary']['requiresDescribe']);
            $this->assertSame(1, \count(array_filter($report['probe']['agentCalls'], static fn ($call) => 'tools_describe' === $call['name'])));
            $attempts[] = ['repetition' => 1, 'discovery' => $mode->value, 'status' => $report['status'], 'proof' => json_decode(json_encode($report, \JSON_THROW_ON_ERROR), true)];
        }
        $this->assertTrue((new AdoptionProofVerifier())->verifyProbe($report['probe']));
        $report['probe']['agentCalls'][1]['result'] = '[]';
        $this->assertFalse((new AdoptionProofVerifier())->verifyProbe($report['probe']));
    }

    public function testNativeSymfonyToolsEnforceDenialRecoveryAndSharedBudgetInGvisor(): void
    {
        self::bootKernel();
        foreach (\CodeMode\DiscoveryMode::cases() as $mode) {
            $report = self::getContainer()->get(AdoptionProofRunner::class)->run(discovery: $mode);
            $this->assertSame('complete', $report['status'], json_encode($report['verification']));
            $this->assertTrue((new AdoptionProofVerifier())->verifyProbe($report['probe']));
            $this->assertNull($report['liveTrace']);
            $this->assertSame(0, $report['probe']['modelRequests']);
            $this->assertCount(5, $report['probe']['agentCalls']);
        }
    }
}
