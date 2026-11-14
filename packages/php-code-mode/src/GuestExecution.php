<?php

declare(strict_types=1);

namespace CodeMode;

/** Shared bounded protocol handling; does not provide process isolation. @internal */
final class GuestExecution
{
    public static function run(ProcessSession $process, BrokerWorker $worker, string $code, ExecutionLimits $limits, string $name, float $started, array &$calls, ExecutionProfile $profile, ?ExecutionTimeline $timeline = null): ExecutionResult
    {
        $deadline = $started + $limits->wallTimeMs / 1000;
        $process->write(json_encode(['code' => $code], \JSON_THROW_ON_ERROR)."\n");
        $outputBytes = 0;
        $buffer = '';
        $finished = false;
        $value = null;
        $receive = static function (string $type, string $chunk) use ($name, $process, $worker, $limits, $deadline, $timeline, &$outputBytes, &$buffer, &$calls, &$finished, &$value): void {
            $outputBytes += \strlen($chunk);
            if ($outputBytes > $limits->maxOutputBytes) {
                throw new SandboxException('output_limit', 'Guest output exceeded its byte limit.');
            }
            if ('err' === $type) {
                return; // Diagnostic bytes are counted but never reflected or persisted.
            }
            $buffer .= $chunk;
            while (false !== ($newline = strpos($buffer, "\n"))) {
                if ($newline > $limits->maxFrameBytes) {
                    throw new SandboxException('frame_limit', 'Guest frame exceeded its byte limit.');
                }
                if ($finished) {
                    throw new SandboxException('invalid_protocol', 'Guest emitted data after its result.');
                }
                if (BrokerWorker::now() >= $deadline) {
                    throw new SandboxException('wall_timeout', 'Execution exceeded its wall-clock deadline.');
                }
                $frame = Protocol::decode(substr($buffer, 0, $newline), \count($calls) + 1);
                $buffer = substr($buffer, $newline + 1);
                if ('result' === $frame->type) {
                    $finished = true;
                    $value = $frame->value;
                    $process->closeInput();
                    continue;
                }
                if (\count($calls) >= $limits->maxToolCalls) {
                    throw new SandboxException('tool_call_limit', 'Execution exceeded its tool-call limit.');
                }
                $calls[] = ['name' => $frame->name, 'status' => 'pending'];
                // Authorization and validation run before invocation, for every call.
                $callStarted = BrokerWorker::now();
                $span = $timeline?->begin('broker', $frame->name, ['runId' => $name, 'callIndex' => \count($calls) - 1, 'arguments' => $frame->arguments]);
                try {
                    $result = $worker->call($frame->name, $frame->arguments, $deadline);
                } catch (SandboxException $error) {
                    $timeline?->end($span, 'tool_denied' === $error->reason ? 'denied' : 'failed', ['reason' => $error->reason]);
                    $calls[array_key_last($calls)]['status'] = 'tool_denied' === $error->reason ? 'denied' : 'incomplete';
                    $calls[array_key_last($calls)]['reason'] = $error->reason;
                    $calls[array_key_last($calls)]['durationMs'] = (BrokerWorker::now() - $callStarted) * 1000;
                    throw $error;
                }
                $calls[array_key_last($calls)]['status'] = 'succeeded';
                $calls[array_key_last($calls)]['durationMs'] = (BrokerWorker::now() - $callStarted) * 1000;
                $calls[array_key_last($calls)]['resultBytes'] = \strlen(json_encode($result, \JSON_THROW_ON_ERROR, 32));
                $timeline?->end($span, 'succeeded', ['resultBytes' => $calls[array_key_last($calls)]['resultBytes'], 'resultSha256' => hash('sha256', json_encode($result, \JSON_THROW_ON_ERROR, 32))]);
                $process->write(json_encode(['id' => $frame->id, 'value' => $result], \JSON_THROW_ON_ERROR, 32)."\n");
            }
            if (\strlen($buffer) > $limits->maxFrameBytes) {
                throw new SandboxException('frame_limit', 'Unterminated guest frame exceeded its byte limit.');
            }
        };
        do {
            if (BrokerWorker::now() >= $deadline) {
                throw new SandboxException('wall_timeout', 'Execution exceeded its wall-clock deadline.');
            }
            $running = $process->tick($receive);
            if ($running) {
                usleep(1000);
            }
        } while ($running);
        if (!$process->succeeded() || !$finished || '' !== $buffer) {
            throw new SandboxException('guest_failed', 'The PHP program exited without a complete successful result.');
        }

        return new ExecutionResult($value, $calls, (BrokerWorker::now() - $started) * 1000, $outputBytes, $name, $profile);
    }
}
