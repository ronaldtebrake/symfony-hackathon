<?php

declare(strict_types=1);

namespace App\Demo;

use CodeMode\Symfony\Ai\BatchToolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;

/** Observe the reusable batch adapter without changing its results or policies. */
final readonly class RecordingBatchToolbox implements ToolboxInterface
{
    public function __construct(private BatchToolbox $inner, private RunTrace $trace)
    {
    }

    public function getTools(): array
    {
        return $this->inner->getTools();
    }

    public function execute(ToolCall $call): ToolResult
    {
        $result = $this->inner->execute($call);
        $encoded = $result->getResult();
        ++$this->trace->agentToolCalls;
        $this->trace->toolPayloadBytes += \strlen($encoded);
        $this->trace->captureCall($call->getName(), $call->getArguments(), $encoded);
        if (null !== $record = $this->inner->lastExecution()) {
            $trace = json_decode(json_encode($record['trace'], \JSON_THROW_ON_ERROR), true, 32, \JSON_THROW_ON_ERROR);
            array_push($this->trace->brokerCalls, ...$trace['toolCalls']);
            $this->trace->executions[] = ['batchIndex' => \count($this->trace->executions), 'submittedCalls' => $record['calls'],
                'status' => isset($trace['error']) ? 'failed' : 'succeeded', 'reason' => $trace['error']['reason'] ?? null] + $trace;
        }

        return $result;
    }
}
