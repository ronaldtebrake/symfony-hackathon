<?php

declare(strict_types=1);

namespace CodeMode;

final readonly class ExecutionResult
{
    /**
     * @param mixed $value Untrusted guest data, not proof that a tool ran.
     * @param list<array{name: string, status: string, durationMs?: float, resultBytes?: int, reason?: string}> $toolCalls Recorded by the host.
     */
    public function __construct(
        public mixed $value,
        public array $toolCalls,
        public float $durationMs,
        public int $outputBytes,
        public string $runId,
        public ExecutionProfile $profile = ExecutionProfile::Unknown,
    ) {
    }
}
