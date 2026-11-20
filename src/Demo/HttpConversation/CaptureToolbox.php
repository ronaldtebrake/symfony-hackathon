<?php

declare(strict_types=1);

namespace App\Demo\HttpConversation;

use App\Demo\RunTrace;
use Symfony\AI\Agent\Bridge\Mcp\Exception\ToolErrorException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Agent\Toolbox\ToolResultConverter;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/** Records the exact tool messages supplied to the model in this two-turn proof. */
final readonly class CaptureToolbox implements ToolboxInterface
{
    private const NAMES = ['catalog_execute_tools', 'catalog_search_tools'];

    public function __construct(private ToolboxInterface $inner, private RunTrace $trace, private ToolResultConverter $converter)
    {
    }

    public function getTools(): array
    {
        $tools = $this->inner->getTools();
        $names = array_map(static fn (Tool $tool) => $tool->getName(), $tools);
        sort($names);
        if (self::NAMES !== $names) {
            throw new \UnexpectedValueException('Unexpected HTTP entry catalog.');
        }

        return $tools;
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        if (++$this->trace->agentToolCalls > 8 || !\in_array($toolCall->getName(), self::NAMES, true)) {
            throw new \RuntimeException('Agent tool call is outside the capture limits.');
        }
        try {
            $result = $this->inner->execute($toolCall);
        } catch (ToolErrorException $denial) {
            // Only a tool's validated error result belongs in the conversation.
            $result = new ToolResult($toolCall, $denial->getToolCallResult());
        }
        $text = $this->converter->convert($result) ?? throw new \UnexpectedValueException('Missing MCP tool result.');
        $this->trace->toolPayloadBytes += \strlen($text);
        $this->trace->captureCall($toolCall->getName(), $toolCall->getArguments(), $text);

        return $result;
    }
}
