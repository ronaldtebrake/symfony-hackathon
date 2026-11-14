<?php

declare(strict_types=1);

namespace CodeMode;

final class SandboxException extends \RuntimeException
{
    /**
     * @param list<array{name: string, status: string, durationMs?: float, resultBytes?: int, reason?: string}> $toolCalls host-recorded calls, including work before failure
     * @param ?string                                                                                           $runId     Host-generated execution identifier, when execution reached allocation. Never supplied by the guest.
     */
    public function __construct(public readonly string $reason, string $message, public readonly array $toolCalls = [], ?\Throwable $previous = null, public readonly ?string $runId = null, public readonly ExecutionProfile $profile = ExecutionProfile::Unknown)
    {
        parent::__construct($message, 0, $previous);
    }
}
