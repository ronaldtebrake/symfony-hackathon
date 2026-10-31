<?php

declare(strict_types=1);

namespace CodeMode;

final class SandboxException extends \RuntimeException
{
    /** @param list<array{name: string, status: string, durationMs?: float, resultBytes?: int}> $toolCalls Host-recorded calls, including work before a failed execution. */
    public function __construct(public readonly string $reason, string $message, public readonly array $toolCalls = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
