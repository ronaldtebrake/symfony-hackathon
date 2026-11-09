<?php

declare(strict_types=1);

namespace CodeMode\Mcp;

use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\BrokerWorker;
use CodeMode\SandboxException;
use CodeMode\Tool;
use CodeMode\ToolBroker;
use CodeMode\ToolCatalog;
use CodeMode\ToolDefinition;
use Mcp\Client;
use Mcp\Schema\Content\TextContent;

/** The factory, endpoint and policies are host configuration. Never accept them from generated PHP. */
final readonly class McpGateway
{
    /**
     * @param \Closure(): Client $connect Creates a fresh, connected SDK client; never captures a live connection.
     * @param array<string, \Closure(ExecutionIdentity, \stdClass): bool> $policies Explicit local authorization; upstream annotations do not grant access.
     */
    public function __construct(private \Closure $connect, private array $policies)
    {
        foreach ($policies as $name => $policy) {
            if (!is_string($name) || !$policy instanceof \Closure) {
                throw new \InvalidArgumentException('MCP tool policies must map names to authorization callbacks.');
            }
        }
    }

    public function discover(): ToolCatalog
    {
        // Discovery also handles untrusted upstream data: supervise its time, heap and result size.
        $broker = new ToolBroker([new Tool('catalog.read', (object) ['type' => 'object', 'additionalProperties' => false],
            static fn (): bool => true, fn (): array => $this->collectDefinitions(),
        )]);
        $limits = new ExecutionLimits(toolTimeMs: 3000, maxFrameBytes: 65536, maxOutputBytes: 65536, maxToolResultBytes: 65536);
        $worker = new BrokerWorker($broker, new ExecutionIdentity('catalog-reader'), ['catalog.read'], $limits);
        try {
            $data = $worker->call('catalog.read', new \stdClass(), BrokerWorker::now() + 5);
            return new ToolCatalog(array_map(static fn (\stdClass $item): ToolDefinition => new ToolDefinition($item->name, $item->description, $item->inputSchema, $item->outputSchema ?? null), $data));
        } finally {
            $worker->close();
        }
    }

    /** @return list<ToolDefinition> */
    private function collectDefinitions(): array
    {
        $client = ($this->connect)();
        try {
            $definitions = [];
            $seenCursors = [];
            $seenNames = [];
            $cursor = null;
            $total = 0;
            do {
                $page = $client->listTools($cursor);
                foreach ($page->tools as $tool) {
                    if (++$total > 128 || isset($seenNames[$tool->name])) {
                        throw new SandboxException('catalog_limit', 'Upstream tool catalog exceeds its bounds or contains duplicate names.');
                    }
                    $seenNames[$tool->name] = true;
                    if (!isset($this->policies[$tool->name])) {
                        continue;
                    }
                    $definitions[] = new ToolDefinition(
                        $tool->name,
                        $tool->description ?? '',
                        json_decode(json_encode((object) $tool->inputSchema, JSON_THROW_ON_ERROR, 32), false, 32, JSON_THROW_ON_ERROR),
                        null === $tool->outputSchema ? null : json_decode(json_encode((object) $tool->outputSchema, JSON_THROW_ON_ERROR, 32), false, 32, JSON_THROW_ON_ERROR),
                    );
                }
                $cursor = $page->nextCursor;
                if (null !== $cursor) {
                    if (isset($seenCursors[$cursor]) || count($seenCursors) >= 8 || strlen($cursor) > 1024) {
                        throw new SandboxException('catalog_limit', 'Upstream catalog pagination exceeds its bounds.');
                    }
                    $seenCursors[$cursor] = true;
                }
            } while (null !== $cursor);
            return $definitions;
        } finally {
            $client->disconnect();
        }
    }

    public function broker(ToolCatalog $catalog): ToolBroker
    {
        return new ToolBroker($this->approvedTools($catalog));
    }

    /** Host-only bindings, preserving the gateway's per-call policies and supervised invocation. @return list<Tool> */
    public function approvedTools(ToolCatalog $catalog): array
    {
        $tools = [];
        foreach ($catalog->all() as $definition) {
            $authorize = $this->policies[$definition->name] ?? null;
            if (null === $authorize) {
                throw new SandboxException('tool_denied', 'Catalog tool has no host policy.');
            }
            $tools[] = new Tool($definition->name, $definition->inputSchema, $authorize,
                fn (\stdClass $arguments): mixed => $this->invoke($definition->name, $arguments),
            );
        }
        return $tools;
    }

    private function invoke(string $name, \stdClass $arguments): mixed
    {
        // This executes in the supervised broker child. Connections are opened after fork.
        $client = ($this->connect)();
        try {
            $result = $client->callTool($name, get_object_vars($arguments));
            if ($result->isError) {
                throw new SandboxException('tool_failed', 'The upstream MCP tool rejected the request.');
            }
            if (null !== $result->structuredContent) {
                return $result->structuredContent;
            }
            if (1 !== count($result->content) || !$result->content[0] instanceof TextContent) {
                throw new SandboxException('tool_failed', 'Code Mode requires JSON structured content or one text result.');
            }
            $text = $result->content[0]->text;
            try {
                return json_decode($text, false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return ['text' => $text];
            }
        } finally {
            $client->disconnect();
        }
    }
}
