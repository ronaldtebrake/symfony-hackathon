<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\Attribute\McpTool;

final class StatusTool
{
    #[McpTool(name: 'app_status', description: 'Check whether the application MCP server is running.')]
    public function __invoke(): string
    {
        return 'ok';
    }
}
