<?php

declare(strict_types=1);

namespace CodeMode\Mcp;

use CodeMode\ExecutionIdentity;
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
     * @param \Closure(): Client                                          $connect  creates a fresh, connected SDK client; never captures a live connection
     * @param array<string, \Closure(ExecutionIdentity, \stdClass): bool> $policies explicit local authorization; upstream annotations do not grant access
     */
    public function __construct(private \Closure $connect, private array $policies)
    {
        foreach ($policies as $name => $policy) {
            if (!\is_string($name) || !$policy instanceof \Closure) {
                throw new \InvalidArgumentException('MCP tool policies must map names to authorization callbacks.');
            }
        }
    }

    public function discover(): ToolCatalog
    {
        // Transport deadlines belong to the host-configured client. No worker is created.
        return new ToolCatalog($this->collectDefinitions());
    }

    public function broker(ToolCatalog $catalog): ToolBroker
    {
        return new ToolBroker($this->approvedTools($catalog));
    }

    /** Host-only bindings preserving per-call policies. @return list<Tool> */
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

    /** @return list<ToolDefinition> */
    private function collectDefinitions(): array
    {
        $started = hrtime(true);
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
                        json_decode(json_encode((object) $tool->inputSchema, \JSON_THROW_ON_ERROR, 32), false, 32, \JSON_THROW_ON_ERROR),
                        null === $tool->outputSchema ? null : json_decode(json_encode((object) $tool->outputSchema, \JSON_THROW_ON_ERROR, 32), false, 32, \JSON_THROW_ON_ERROR),
                    );
                }
                if ((hrtime(true) - $started) / 1e6 > 3000) {
                    throw new SandboxException('catalog_deadline_exceeded', 'Catalog returned after its cooperative deadline.');
                }
                if (\strlen(json_encode($definitions, \JSON_THROW_ON_ERROR, 32)) > 65536) {
                    throw new SandboxException('tool_result_limit', 'Catalog exceeds its byte limit.');
                }
                $cursor = $page->nextCursor;
                if (null !== $cursor) {
                    if (isset($seenCursors[$cursor]) || \count($seenCursors) >= 8 || \strlen($cursor) > 1024) {
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

    private function invoke(string $name, \stdClass $arguments): mixed
    {
        // A fresh bounded client; the application owns transport timeouts.
        $client = ($this->connect)();
        try {
            $result = $client->callTool($name, get_object_vars($arguments));
            if ($result->isError) {
                throw new SandboxException('tool_failed', 'The upstream MCP tool rejected the request.');
            }
            if (null !== $result->structuredContent) {
                return $result->structuredContent;
            }
            if (1 !== \count($result->content) || !$result->content[0] instanceof TextContent) {
                throw new SandboxException('tool_failed', 'Code Mode requires JSON structured content or one text result.');
            }
            $text = $result->content[0]->text;
            try {
                return json_decode($text, false, 32, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return ['text' => $text];
            }
        } finally {
            $client->disconnect();
        }
    }
}
