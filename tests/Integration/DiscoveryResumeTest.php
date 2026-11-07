<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\{AdoptionProofRunner, DiscoveryCatalog};
use App\CodeMode\DemoIdentityProvider;
use CodeMode\{DockerEngine, ExecutionLimits};
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DiscoveryResumeTest extends TestCase
{
    public function testResumeChecksRecordedConditionsWithoutExecutingToolsOrCallingProvider(): void
    {
        $report = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/compact-discovery.json'), true, flags: JSON_THROW_ON_ERROR);
        $proof = $report['attempts'][0]['proof'];
        $saved = $_SERVER;
        $_SERVER['CODE_MODE_IMAGE'] = $proof['image'];
        $_SERVER['HUGGINGFACE_MODEL'] = $proof['model'];
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');
        $limits = new ExecutionLimits(wallTimeMs: 5000, toolTimeMs: 1000, memoryMiB: 128, maxToolCalls: 10);
        try {
            $runner = new AdoptionProofRunner(DiscoveryCatalog::sessions($proof['image'], new DockerEngine(), $limits), new DemoIdentityProvider(), $http, $limits);
            $runner->assertResumeConditions($proof);
            foreach (['model', 'image', 'systemPrompt', 'questions', 'generationSettings', 'identity', 'limits', 'fixtureSha256', 'approvedTools', 'searchProtocol'] as $field) {
                $changed = $proof; $changed[$field] = null;
                try { $runner->assertResumeConditions($changed); self::fail('Changed '.$field.' was accepted.'); }
                catch (\RuntimeException $error) { self::assertStringContainsString($field, $error->getMessage()); }
            }
            $changed = $proof; $changed['catalogProfile'] = 'unknown';
            $this->expectException(\InvalidArgumentException::class);
            $runner->assertResumeConditions($changed);
        } finally { $_SERVER = $saved; }
    }
}
