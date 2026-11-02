<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Ai;

use CodeMode\CodeModeSession;
use CodeMode\SandboxException;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\AI\Platform\Tool\ToolEffectHints;

/** Optional Symfony AI bridge. Construct once per host-owned conversation, not as a shared toolbox. */
final class CodeModeToolbox implements ToolboxInterface
{
    private int $calls = 0;

    public function __construct(private readonly CodeModeSession $session) {}

    public function getTools(): array
    {
        return array_map(static fn ($definition) => new Tool(new ExecutionReference(self::class), $definition->name, $definition->description, get_object_vars($definition->inputSchema), effectHints: new ToolEffectHints(readOnlyHint: true)), CodeModeSession::definitions());
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        try {
            if (++$this->calls > 60) {
                throw new SandboxException('tool_call_limit', 'Conversation call budget exhausted.');
            }
            $definitions = array_column(CodeModeSession::definitions(), null, 'name');
            $name = $toolCall->getName();
            $arguments = $toolCall->getArguments();
            if (!isset($definitions[$name]) || !(new Validator(new SchemaLoader()))->validate((object) $arguments, $definitions[$name]->inputSchema)->isValid()) {
                throw new SandboxException('invalid_arguments', 'Unknown tool or invalid arguments.');
            }
            $value = match ($name) {
                'tools_search' => $this->session->search($arguments['query']),
                'tools_describe' => $this->session->describe($arguments['names']),
                'php_execute' => $this->executePhp($arguments['code']),
            };
        } catch (\Throwable $error) {
            // A sanitized, model-visible result permits recovery without leaking exception details.
            $value = ['error' => ['reason' => $error instanceof SandboxException ? $error->reason : 'tool_failed']];
            if ($error instanceof SandboxException) {
                $value['toolCalls'] = $error->toolCalls;
                $value['runId'] = $error->runId;
            }
        }
        return new ToolResult($toolCall, json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function executePhp(string $code): array
    {
        $result = $this->session->execute($code);
        return ['value' => $result->value, 'toolCalls' => $result->toolCalls, 'durationMs' => $result->durationMs, 'runId' => $result->runId];
    }
}
