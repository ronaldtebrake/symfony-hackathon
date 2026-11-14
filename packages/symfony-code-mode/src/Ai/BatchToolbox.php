<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Ai;

use CodeMode\BatchResult;
use CodeMode\BatchSession;
use CodeMode\SandboxException;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/** One toolbox per conversation. Reuses existing Symfony AI provider contracts. */
final class BatchToolbox implements ToolboxInterface
{
    private int $calls = 0;
    private ?array $lastExecution = null;

    public function __construct(private readonly BatchSession $session, private readonly bool $catalog = false, private readonly bool $literalUnicode = false)
    {
    }

    public function getTools(): array
    {
        return array_map(static fn ($definition) => new Tool(new ExecutionReference(self::class), $definition->name,
            $definition->description, get_object_vars($definition->inputSchema)), $this->definitions());
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $this->lastExecution = null;
        try {
            if (++$this->calls > 60) {
                throw new SandboxException('tool_call_limit', 'Conversation call budget exhausted.');
            }
            $definitions = array_column($this->definitions(), null, 'name');
            $name = $toolCall->getName();
            $arguments = $toolCall->getArguments();
            $data = json_decode(json_encode((object) $arguments, \JSON_THROW_ON_ERROR, 32), false, 32, \JSON_THROW_ON_ERROR);
            // ToolCall uses associative PHP arrays, including [] for an empty JSON map.
            if (\in_array($name, ['tools_execute', 'execute_tools'], true) && isset($data->calls) && \is_array($data->calls)) {
                foreach ($data->calls as $call) {
                    if ($call instanceof \stdClass && [] === ($call->arguments ?? null)) {
                        $call->arguments = new \stdClass();
                    }
                }
            }
            if (!isset($definitions[$name]) || !(new Validator(new SchemaLoader()))->validate($data, $definitions[$name]->inputSchema)->isValid()) {
                throw new SandboxException('invalid_arguments', 'Unknown tool or invalid arguments.');
            }
            $value = match ($name) {
                'tools_search' => $this->session->searchResult($arguments['query']),
                'search_tools' => $this->session->searchContracts($arguments['query']),
                'tools_describe' => $this->session->describe($arguments['names']),
                'tools_execute', 'execute_tools' => $this->session->respond($arguments['calls'], function (array $calls, array $trace): void {
                    $this->lastExecution = ['calls' => $calls, 'trace' => $trace];
                }),
            };
        } catch (\Throwable $error) {
            $value = BatchResult::failure($error);
        }

        return new ToolResult($toolCall, json_encode($value, \JSON_THROW_ON_ERROR | ($this->literalUnicode ? \JSON_UNESCAPED_UNICODE : 0)));
    }

    public function lastExecution(): ?array
    {
        return $this->lastExecution;
    }

    private function definitions(): array
    {
        return $this->catalog ? $this->session->catalogToolDefinitions() : $this->session->toolDefinitions();
    }
}
