<?php

declare(strict_types=1);

namespace App\Demo\HttpConversation;

use App\Demo\RunTrace;
use Mcp\Client;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/** The model can reach only the two actual HTTP entry tools; application checks stay on the server. */
final readonly class McpToolbox implements ToolboxInterface
{
    private array $tools;

    public function __construct(private Client $client, private RunTrace $trace)
    {
        $listed = $client->listTools();
        $names = array_column($listed->tools, 'name');
        sort($names);
        if (['execute_tools', 'search_tools'] !== $names || null !== $listed->nextCursor) {
            throw new \RuntimeException('Unexpected HTTP entry catalog.');
        }
        $this->tools = array_map(static fn ($tool) => new Tool(new ExecutionReference(self::class, 'execute'), $tool->name, $tool->description ?? '', json_decode(json_encode($tool->inputSchema, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR)), $listed->tools);
    }

    public function getTools(): array
    {
        return $this->tools;
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        if (++$this->trace->agentToolCalls > 8 || !\in_array($toolCall->getName(), ['search_tools', 'execute_tools'], true)) {
            throw new \RuntimeException('Agent tool call is outside the capture limits.');
        }
        $result = $this->client->callTool($toolCall->getName(), $toolCall->getArguments());
        $text = $result->content[0]->text ?? null;
        if (!\is_string($text) || null === $result->structuredContent || json_decode($text, true) !== $result->structuredContent) {
            throw new \RuntimeException('MCP text and structured content disagree.');
        }
        $this->trace->toolPayloadBytes += \strlen($text);
        $this->trace->captureCall($toolCall->getName(), $toolCall->getArguments(), $text);

        return new ToolResult($toolCall, $text);
    }
}
