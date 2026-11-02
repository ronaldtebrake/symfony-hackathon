<?php

declare(strict_types=1);

namespace CodeMode\Symfony;

use CodeMode\ExecutionIdentity;
use CodeMode\ToolDefinition;

/** Trusted host code. Only explicitly configured services enter the catalog. */
interface ReadToolInterface
{
    public function definition(): ToolDefinition;

    /** Called before schema validation. Handle missing or malformed arguments without granting access. */
    public function authorize(ExecutionIdentity $identity, \stdClass $arguments): bool;

    /** Return JSON data. Recheck mutable resource/field access at the point of reading. */
    public function invoke(\stdClass $arguments, ExecutionIdentity $identity): mixed;
}
