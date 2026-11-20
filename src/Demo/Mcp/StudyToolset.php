<?php

declare(strict_types=1);

namespace App\Demo\Mcp;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ResultType;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Symfony\AI\Agent\Bridge\Mcp\McpToolbox;
use Symfony\AI\Agent\Bridge\Mcp\ToolsetInterface;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;

/** The reviewed listing and the HTTP receipts are the inputs to the discovery studies. */
final class StudyToolset implements ToolsetInterface
{
    /** @var list<array> Verified HTTP receipts, in call order */
    public array $calls = [];
    /** @var list<Tool> */
    private readonly array $tools;
    private int $attempts = 0;

    /**
     * @param list<string>                   $allowed
     * @param \Closure(string, array): array $request Sends one authenticated tools/call and checks its response ID
     */
    public function __construct(array $listing, private readonly array $allowed, private readonly int $limit, private readonly \Closure $request)
    {
        $definitions = $listing['tools'] ?? null;
        if (!\is_array($definitions) || !array_is_list($definitions) || isset($listing['nextCursor'])) {
            throw new \UnexpectedValueException('Expected the complete tool listing for this study.');
        }
        $this->tools = array_map(Tool::fromArray(...), $definitions);
        $names = array_column($this->tools, 'name');
        sort($names);
        sort($allowed);
        if ($names !== $allowed) {
            throw new \UnexpectedValueException('The remote listing does not match the study tools.');
        }
    }

    public function toolbox(): FaultTolerantToolbox
    {
        return new FaultTolerantToolbox(new McpToolbox($this));
    }

    public function getName(): string
    {
        return 'symfony';
    }

    public function getTools(): array
    {
        return $this->tools;
    }

    public function callTool(string $name, array $arguments = []): CallToolResult
    {
        if (!\in_array($name, $this->allowed, true) || $this->attempts >= $this->limit) {
            throw new \RuntimeException('Call outside the study toolset or its remaining budget.');
        }
        ++$this->attempts;
        $receipt = ($this->request)($name, $arguments);
        $wire = $receipt['response'] ?? null;
        if (!\is_array($wire) || !isset($wire['id']) || \array_key_exists('error', $wire) === \array_key_exists('result', $wire)) {
            throw new \UnexpectedValueException('Invalid MCP response.');
        }
        if (\array_key_exists('error', $wire)) {
            if (!\in_array($receipt['httpStatus'] ?? null, [200, 400, 404], true)) {
                throw new \UnexpectedValueException('Unexpected HTTP status for an MCP error.');
            }
            $error = Error::fromArray($wire);
            $result = CallToolResult::error([new TextContent(json_encode(['error' => ['jsonRpcCode' => $error->code]], \JSON_THROW_ON_ERROR))]);
        } else {
            if (200 !== ($receipt['httpStatus'] ?? null)) {
                throw new \UnexpectedValueException('Unexpected HTTP status for a tool result.');
            }
            $payload = Response::fromArray($wire)->result;
            if (\array_key_exists('resultType', $payload) && ResultType::Complete->value !== $payload['resultType']) {
                throw new \UnexpectedValueException('The study requires a completed tool response.');
            }
            $result = CallToolResult::fromArray($payload);
            $text = $result->content[0] ?? null;
            if (1 !== \count($result->content) || !$text instanceof TextContent || !\is_string($text->text)
                || !\is_array($result->structuredContent) || $result->structuredContent !== json_decode($text->text, true, flags: \JSON_THROW_ON_ERROR)) {
                throw new \UnexpectedValueException('The study requires matching text and structured MCP results.');
            }
        }
        $this->calls[] = $receipt;

        return $result;
    }
}
