<?php

declare(strict_types=1);

namespace App\Demo;

use CodeMode\Symfony\Ai\CodeModeToolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;

/** Demo-only observation around the reusable adapter. Does not execute or rewrite PHP. */
final readonly class RecordingCodeModeToolbox implements ToolboxInterface
{
    public function __construct(private CodeModeToolbox $inner, private RunTrace $trace) {}
    public function getTools(): array { return $this->inner->getTools(); }

    public function execute(ToolCall $call): ToolResult
    {
        $result = $this->inner->execute($call);
        $encoded = $result->getResult();
        $value = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        ++$this->trace->agentToolCalls;
        $this->trace->toolPayloadBytes += strlen($encoded);
        $this->trace->captureCall($call->getName(), $call->getArguments(), $encoded);
        if ('php_execute' === $call->getName() && is_string($call->getArguments()['code'] ?? null)) {
            $this->trace->programs[] = $call->getArguments()['code'];
            $calls = $value['toolCalls'] ?? [];
            array_push($this->trace->brokerCalls, ...$calls);
            $this->trace->executions[] = ['programIndex' => count($this->trace->programs) - 1, 'runId' => $value['runId'] ?? null,
                'status' => isset($value['error']) ? 'failed' : 'succeeded', 'reason' => $value['error']['reason'] ?? null,
                'durationMs' => $value['durationMs'] ?? null, 'toolCalls' => $calls, 'value' => $value['value'] ?? null];
            $this->trace->report = $value['value'] ?? null;
        }
        return $result;
    }
}
