<?php

declare(strict_types=1);

namespace App\Demo;

use CodeMode\BrokerWorker;
use CodeMode\CodeModeSession;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\SandboxException;
use CodeMode\ToolBroker;
use CodeMode\ToolCatalog;
use CodeMode\ToolDefinition;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\AI\Platform\Tool\ToolEffectHints;

final class AuditToolbox implements ToolboxInterface
{
    /** @var array<string, ToolDefinition> */
    private array $definitions = [];
    /** @var array<string, string> */
    private array $aliases = [];
    private array $tools = [];

    public function __construct(
        private readonly string $approach,
        private readonly ToolCatalog $catalog,
        private readonly ToolBroker $broker,
        private readonly CodeModeSession $session,
        private readonly RunTrace $trace,
    ) {
        if (!in_array($approach, ['direct', 'code'], true)) {
            throw new \InvalidArgumentException('Unknown demo approach.');
        }
        $definitions = 'code' === $approach ? CodeModeSession::definitions() : $catalog->all();
        foreach ($definitions as $index => $definition) {
            // Provider function names have stricter syntax than MCP names (e.g. Drupal's dots).
            $alias = 'direct' === $approach ? 'mcp_'.($index + 1) : $definition->name;
            $this->aliases[$alias] = $definition->name;
            $this->definitions[$definition->name] = $definition;
            $this->tools[] = new Tool(new ExecutionReference(self::class), $alias, $definition->description, get_object_vars($definition->inputSchema), effectHints: new ToolEffectHints(readOnlyHint: true));
        }
    }

    public function getTools(): array
    {
        return $this->tools;
    }

    public function aliasFor(string $name): string
    {
        return array_search($name, $this->aliases, true) ?: throw new \InvalidArgumentException('Unknown tool.');
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        ++$this->trace->agentToolCalls;
        $name = $this->aliases[$toolCall->getName()] ?? '';
        $arguments = $toolCall->getArguments();
        $brokerCallCount = count($this->trace->brokerCalls);
        $span = $this->trace->timeline?->begin('agent_tool', $toolCall->getName(), ['agentCallIndex' => count($this->trace->agentCalls)]);
        $status = 'succeeded';
        try {
            if ('' === $name || $this->trace->agentToolCalls > 60) {
                throw new SandboxException('tool_call_limit', 'Unknown tool or session call limit exceeded.');
            }
            if ('code' === $this->approach) {
                // These three schemas are local constants. Upstream schema validation stays in the supervised worker.
                $validator = new Validator(new SchemaLoader());
                if (!$validator->validate((object) $arguments, $this->definitions[$name]->inputSchema)->isValid()) {
                    throw new SandboxException('invalid_arguments', 'The Code Mode call does not match its schema.');
                }
                $value = match ($name) {
                    'tools_search' => $this->session->search($arguments['query']),
                    'tools_describe' => $this->session->describe($arguments['names']),
                    'php_execute' => $this->executePhp($arguments['code']),
                };
            } else {
                $value = $this->invokeDirect($name, $arguments);
            }
            // Returning the exact encoded string makes the measured payload match the model message.
            $encoded = json_encode($value, JSON_THROW_ON_ERROR, 32);
            $this->trace->toolPayloadBytes += strlen($encoded);
            $this->trace->captureCall($toolCall->getName(), $arguments, $encoded);
            $this->trace->record('tool_result', $name, ['bytesToModel' => strlen($encoded)]);
            return new ToolResult($toolCall, $encoded);
        } catch (\Throwable $error) {
            $status = 'failed';
            $reason = $error instanceof SandboxException ? $error->reason : 'tool_failed';
            $this->trace->record('rejected', $name, ['reason' => $reason]);
            $encoded = json_encode(['error' => ['reason' => $reason, 'message' => 'The host rejected or could not complete this tool call. Check the described schema and available execution budget.']], JSON_THROW_ON_ERROR);
            if ('direct' === $this->approach && count($this->trace->brokerCalls) === $brokerCallCount + 1) {
                $last = array_key_last($this->trace->brokerCalls);
                if ('incomplete' === $this->trace->brokerCalls[$last]['status']) {
                    $this->trace->brokerCalls[$last]['reason'] = $reason;
                    $this->trace->brokerCalls[$last]['resultBytes'] = strlen($encoded);
                }
            }
            $this->trace->toolPayloadBytes += strlen($encoded);
            $this->trace->captureCall($toolCall->getName(), $arguments, $encoded);
            return new ToolResult($toolCall, $encoded);
        } finally {
            $this->trace->timeline?->end($span, $status);
        }
    }

    private function executePhp(string $code): array
    {
        $this->trace->report = null;
        $this->trace->programs[] = $code;
        $this->trace->record('execution', 'PHP submitted to the isolated runner');
        try {
            $result = $this->session->execute($code);
        } catch (SandboxException $error) {
            array_push($this->trace->brokerCalls, ...$error->toolCalls);
            $this->trace->executions[] = ['programIndex' => count($this->trace->programs) - 1, 'runId' => $error->runId, 'status' => 'failed', 'reason' => $error->reason, 'toolCalls' => $error->toolCalls];
            throw $error;
        }
        array_push($this->trace->brokerCalls, ...$result->toolCalls);
        $this->trace->executions[] = ['programIndex' => count($this->trace->programs) - 1, 'runId' => $result->runId, 'status' => 'succeeded', 'durationMs' => $result->durationMs, 'outputBytes' => $result->outputBytes, 'toolCalls' => $result->toolCalls];
        $this->trace->report = $result->value;
        return ['result' => $result->value, 'toolCalls' => count($result->toolCalls)];
    }

    private function invokeDirect(string $name, array $arguments): mixed
    {
        $limits = new ExecutionLimits(wallTimeMs: 15000, toolTimeMs: 3000, maxToolCalls: 30);
        $worker = new BrokerWorker($this->broker, new ExecutionIdentity('demo-editor', ['content.audit']), array_keys($this->definitions), $limits);
        $started = BrokerWorker::now();
        $span = $this->trace->timeline?->begin('broker', $name, ['callIndex' => count($this->trace->brokerCalls), 'arguments' => (object) $arguments]);
        try {
            $value = $worker->call($name, (object) $arguments, $started + 3);
            $this->trace->brokerCalls[] = ['name' => $name, 'status' => 'succeeded', 'durationMs' => (BrokerWorker::now() - $started) * 1000, 'resultBytes' => strlen(json_encode($value, JSON_THROW_ON_ERROR, 32))];
            $this->trace->timeline?->end($span, 'succeeded', ['resultBytes' => strlen(json_encode($value, JSON_THROW_ON_ERROR, 32)), 'resultSha256' => hash('sha256', json_encode($value, JSON_THROW_ON_ERROR, 32))]);
            return $value;
        } catch (\Throwable $error) {
            $denied = $error instanceof SandboxException && 'tool_denied' === $error->reason;
            $this->trace->brokerCalls[] = ['name' => $name, 'status' => $denied ? 'denied' : 'incomplete', 'reason' => $error instanceof SandboxException ? $error->reason : 'tool_failed', 'durationMs' => (BrokerWorker::now() - $started) * 1000];
            $this->trace->timeline?->end($span, $denied ? 'denied' : 'failed', ['reason' => $error instanceof SandboxException ? $error->reason : 'tool_failed']);
            throw $error;
        } finally {
            $worker->close();
        }
    }
}
