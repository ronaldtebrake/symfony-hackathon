<?php

declare(strict_types=1);

namespace CodeMode\Provider;

use CodeMode\Mcp\McpGateway;

/** Host-owned MCP connection and policies; aliases never select endpoints or credentials. */
final readonly class McpProvider implements ToolProviderInterface
{
    /** @param array<string, string> $methods PHP method alias => approved upstream name. */
    public function __construct(private string $namespace, private McpGateway $gateway, private array $methods)
    {
    }

    public function name(): string
    {
        return $this->namespace;
    }

    public function source(): string
    {
        return 'mcp';
    }

    public function tools(): iterable
    {
        if ([] === $this->methods || \count(array_unique($this->methods)) !== \count($this->methods)) {
            throw new \InvalidArgumentException('Map each approved MCP tool at most once.');
        }
        $catalog = $this->gateway->discover();
        $handlers = [];
        foreach ($this->gateway->approvedTools($catalog) as $handler) {
            $handlers[$handler->name] = $handler;
        }
        foreach ($this->methods as $alias => $name) {
            $handler = $handlers[$name] ?? throw new \InvalidArgumentException('Provider alias names an unapproved MCP tool.');
            yield new ToolBinding($alias, $catalog->describe([$name])[0], $handler->authorize, $handler->invoke);
        }
    }
}
