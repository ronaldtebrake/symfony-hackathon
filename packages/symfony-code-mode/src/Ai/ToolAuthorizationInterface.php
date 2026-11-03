<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Ai;

use CodeMode\ExecutionIdentity;

/** Host policy, called before validation and again immediately before invoking the toolbox. */
interface ToolAuthorizationInterface
{
    public function isGranted(ExecutionIdentity $identity, string $tool, \stdClass $arguments): bool;
}
