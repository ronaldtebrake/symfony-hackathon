<?php

declare(strict_types=1);

namespace App\Demo\Performance;

use App\Demo\RunTrace;
use CodeMode\BrokerWorker;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\SandboxException;
use CodeMode\Symfony\Ai\ApprovedToolbox;
use CodeMode\Tool;
use CodeMode\ToolBroker;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool as AiTool;
use Symfony\AI\Platform\Tool\ToolEffectHints;

/** Direct calls use the same native tools, policy, schema validation and supervised broker. */
final class DirectToolbox implements ToolboxInterface
{
    private ToolBroker $broker;
    private array $definitions;
    private int $turn = -1;
    private int $calls = 0;
    private float $activeMs = 0;

    public function __construct(ReadDataset $data, private readonly RunTrace $trace, private readonly ExecutionIdentity $identity, private readonly ExecutionLimits $limits = new ExecutionLimits())
    {
        $approved = (new ApprovedToolbox(new Toolbox([new ReadTools($data)]), ReadTools::NAMES, new ReadPolicy($data)))->tools();
        $this->definitions = array_map(static fn ($t) => $t->definition(), $approved);
        $this->broker = new ToolBroker(array_map(static fn ($t) => new Tool($t->definition()->name, $t->definition()->inputSchema, $t->authorize(...), $t->invoke(...)), $approved));
    }

    /** Only the host advances turns. The model has no budget-reset tool. */
    public function beginTurn(int $turn): void
    {
        if ($turn !== $this->turn + 1 || $turn > 1) {
            throw new \LogicException('The baseline has two host-owned turns.');
        }
        $this->turn = $turn;
        $this->calls = 0;
        $this->activeMs = 0;
    }

    public function definitions(): array
    {
        return $this->definitions;
    }

    public function getTools(): array
    {
        return array_map(static fn ($d) => new AiTool(new ExecutionReference(self::class), $d->name, $d->description, get_object_vars($d->inputSchema), effectHints: new ToolEffectHints(readOnlyHint: true)), $this->definitions);
    }

    public function execute(ToolCall $call): ToolResult
    {
        $start = BrokerWorker::now();
        $worker = null;
        $record = ['name' => $call->getName(), 'arguments' => $call->getArguments(), 'turn' => $this->turn, 'status' => 'incomplete', 'workerClosed' => false];
        try {
            if ($this->turn < 0 || ++$this->calls > $this->limits->maxToolCalls) {
                throw new SandboxException('tool_call_limit', 'Turn call limit reached.');
            }
            if ($this->activeMs >= $this->limits->wallTimeMs) {
                throw new SandboxException('wall_timeout', 'Active reader budget reached.');
            }
            if (\strlen(json_encode($call->getArguments(), \JSON_THROW_ON_ERROR)) > $this->limits->maxFrameBytes) {
                throw new SandboxException('frame_limit', 'Arguments too large.');
            }
            $worker = new BrokerWorker($this->broker, $this->identity, ReadTools::NAMES, $this->limits);
            $value = $worker->call($call->getName(), (object) $call->getArguments(), $start + ($this->limits->wallTimeMs - $this->activeMs) / 1000);
            $record['status'] = 'succeeded';
            $encoded = json_encode($value, \JSON_THROW_ON_ERROR, 32);
            $record['resultBytes'] = \strlen($encoded);
        } catch (\Throwable $error) {
            $reason = $error instanceof SandboxException ? $error->reason : 'tool_failed';
            $record['status'] = 'tool_denied' === $reason ? 'denied' : 'incomplete';
            $record['reason'] = $reason;
            // A direct tool result must not imply that PHP ran in a sandbox.
            $encoded = json_encode(['error' => ['reason' => $reason]], \JSON_THROW_ON_ERROR);
        } finally {
            if (null !== $worker) {
                $worker->close();
                $record['workerClosed'] = true;
            }
        }
        $record['durationMs'] = (BrokerWorker::now() - $start) * 1000;
        $this->activeMs += $record['durationMs'];
        $record['responseSha256'] = hash('sha256', $encoded);
        $this->trace->brokerCalls[] = $record;
        ++$this->trace->agentToolCalls;
        $this->trace->toolPayloadBytes += \strlen($encoded);
        $this->trace->captureCall($call->getName(), $call->getArguments(), $encoded);

        return new ToolResult($call, $encoded);
    }
}
