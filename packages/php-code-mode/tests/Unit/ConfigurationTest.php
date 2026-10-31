<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\DockerEngine;
use CodeMode\ExecutionLimits;
use CodeMode\GvisorSandbox;
use CodeMode\SandboxException;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    public function testMutableImageTagsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GvisorSandbox('php:latest');
    }

    public function testUnlimitedExecutionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExecutionLimits(wallTimeMs: 0);
    }

    public function testMissingGvisorFailsClosed(): void
    {
        // This fake control endpoint is trusted test infrastructure, never a code runner.
        $docker = new DockerEngine([PHP_BINARY, '-r', 'echo "null";']);
        try {
            $docker->requireGvisor();
            self::fail('A missing runtime must fail closed.');
        } catch (SandboxException $e) {
            self::assertSame('isolation_unavailable', $e->reason);
        }
    }

    public function testApplicationSecretsAreNotPassedToDockerClient(): void
    {
        putenv('CODE_MODE_HOST_SECRET=secret');
        try {
            $docker = new DockerEngine([PHP_BINARY, '-r', 'echo getenv("CODE_MODE_HOST_SECRET") === false ? "absent" : "leaked";']);
            self::assertSame('absent', $docker->control([]));
        } finally {
            putenv('CODE_MODE_HOST_SECRET');
        }
    }
}
