<?php

declare(strict_types=1);

namespace CodeMode;

/** Supervises trusted handlers separately so a blocking handler cannot stop the watchdog. @internal */
final class BrokerWorker
{
    private int $pid;
    /** @var resource */
    private $socket;
    private bool $closed = false;

    /** @param list<string> $allowedTools */
    public function __construct(ToolBroker $broker, ExecutionIdentity $identity, array $allowedTools, private ExecutionLimits $limits)
    {
        if ('cli' !== PHP_SAPI) {
            throw new SandboxException('unsupported_host', 'Run the broker in a dedicated CLI worker.');
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if (false === $pair) {
            throw new SandboxException('broker_start', 'Cannot create broker channel.');
        }
        $pid = pcntl_fork();
        if (-1 === $pid) {
            fclose($pair[0]);
            fclose($pair[1]);
            throw new SandboxException('broker_start', 'Cannot start broker supervisor.');
        }
        if (0 === $pid) {
            fclose($pair[0]);
            if (-1 === posix_setsid()) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            // Never run inherited application shutdown functions/destructors in this child.
            try {
                if (false === @ini_set('memory_limit', $limits->brokerMemoryMiB.'M')) {
                    return;
                }
                // Default SIGALRM termination also works when the parent has disappeared.
                pcntl_signal(SIGALRM, SIG_DFL);
                while (false !== ($line = fgets($pair[1], $limits->maxFrameBytes + 2))) {
                    try {
                        pcntl_alarm(intdiv($limits->toolTimeMs + 999, 1000));
                        $request = json_decode($line, false, 32, JSON_THROW_ON_ERROR);
                        $value = $broker->call($request->name, $request->arguments, $identity, $allowedTools);
                        $response = json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR, 32);
                        if (strlen($response) > $limits->maxToolResultBytes) {
                            throw new SandboxException('tool_result_limit', 'Tool result exceeded its byte limit.');
                        }
                    } catch (\Throwable $e) {
                        $response = json_encode(['ok' => false, 'reason' => $e instanceof SandboxException ? $e->reason : 'tool_failed']);
                    }
                    self::writeBlocking($pair[1], $response."\n");
                    pcntl_alarm(0);
                }
            } finally {
                fclose($pair[1]);
                posix_kill(posix_getpid(), SIGKILL);
                exit(1);
            }
        }
        fclose($pair[1]);
        $this->pid = $pid;
        $this->socket = $pair[0];
        stream_set_blocking($this->socket, false);
    }

    public function call(string $name, \stdClass $arguments, float $runDeadline): mixed
    {
        $deadline = min($runDeadline, self::now() + $this->limits->toolTimeMs / 1000);
        $pending = json_encode(['name' => $name, 'arguments' => $arguments], JSON_THROW_ON_ERROR, 32)."\n";
        $buffer = '';
        while (true) {
            if (self::now() >= $deadline) {
                throw new SandboxException('tool_timeout', 'Tool execution exceeded its deadline.');
            }
            if ('' !== $pending) {
                $written = @fwrite($this->socket, $pending);
                if (false === $written) {
                    throw new SandboxException('broker_failed', 'Broker channel closed.');
                }
                $pending = substr($pending, $written);
            }
            $chunk = fread($this->socket, 8192);
            if (false === $chunk) {
                throw new SandboxException('broker_failed', 'Cannot read broker response.');
            }
            $buffer .= $chunk;
            if (strlen($buffer) > $this->limits->maxToolResultBytes + 1) {
                throw new SandboxException('tool_result_limit', 'Tool result exceeded its byte limit.');
            }
            if (str_ends_with($buffer, "\n")) {
                $response = json_decode($buffer, false, 32, JSON_THROW_ON_ERROR);
                if (!$response->ok) {
                    throw new SandboxException($response->reason, 'Broker rejected the tool call.');
                }
                return $response->value;
            }
            if (feof($this->socket)) {
                throw new SandboxException('broker_failed', 'Broker exited without a response.');
            }
            usleep(1000);
        }
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            fclose($this->socket);
            // The group includes any ordinary subprocesses created by a trusted handler.
            @posix_kill(-$this->pid, SIGKILL);
            @posix_kill($this->pid, SIGKILL);
            pcntl_waitpid($this->pid, $status);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    /** @param resource $stream */
    private static function writeBlocking($stream, string $data): void
    {
        while ('' !== $data) {
            $written = @fwrite($stream, $data);
            if (false === $written || 0 === $written) {
                return;
            }
            $data = substr($data, $written);
        }
    }
}
