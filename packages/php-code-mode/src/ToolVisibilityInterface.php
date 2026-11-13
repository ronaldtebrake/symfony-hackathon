<?php

declare(strict_types=1);

namespace CodeMode;

/** Host policy for capabilities, without fabricated resource arguments.
 * Re-evaluated during discovery and before every execution. It never replaces
 * the tool's argument, resource or field authorization.
 */
interface ToolVisibilityInterface
{
    public function isVisible(ExecutionIdentity $identity, string $tool): bool;
}
