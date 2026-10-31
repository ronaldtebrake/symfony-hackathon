<?php

declare(strict_types=1);

namespace CodeMode;

interface SandboxExecutor
{
    /** @param list<string> $allowedTools Host-selected capability allowlist. Empty means no tools. */
    public function execute(
        string $code,
        ToolBroker $broker,
        ExecutionIdentity $identity,
        array $allowedTools = [],
        ?ExecutionLimits $limits = null,
    ): ExecutionResult;
}
