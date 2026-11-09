<?php

declare(strict_types=1);

namespace CodeMode\Provider;

use CodeMode\{Tool, ToolDefinition};

/** Both callbacks are host code; descriptions and schemas remain untrusted data. */
final readonly class ToolBinding
{
    public Tool $handler;

    public function __construct(public string $method, public ToolDefinition $definition, callable $authorize, callable $invoke)
    {
        $this->handler = new Tool($definition->name, $definition->inputSchema, $authorize, $invoke);
    }
}
