<?php

declare(strict_types=1);

namespace CodeMode;

/** Bounded, nonblocking transport for guest-controlled output. @internal */
class ProcessSession
{
    /** @var resource|null */
    private $process;
    /** @var array<int, resource> */
    private array $pipes = [];
    private string $pending = '';
    private bool $inputClosed = false;
    private ?int $exitCode = null;

    public function __construct(array $command, array $environment, ?string $cwd = null, private readonly string $failureReason = 'process_failed')
    {
        // Array argv bypasses a shell. Neither the command nor its arguments contain guest PHP.
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
        if (false === $process) {
            throw new SandboxException($this->failureReason, 'Cannot start the execution transport.');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function pid(): int
    {
        return proc_get_status($this->process)['pid'];
    }

    public function write(string $data): void
    {
        if ($this->inputClosed || \strlen($this->pending) + \strlen($data) > 524288) {
            throw new SandboxException('input_limit', 'Guest input queue is closed or exceeds its byte limit.');
        }
        $this->pending .= $data;
    }

    public function closeInput(): void
    {
        $this->inputClosed = true;
    }

    /** Reads at most 8 KiB from each channel per tick, then returns to the watchdog. */
    public function tick(\Closure $receive): bool
    {
        foreach ([1 => 'out', 2 => 'err'] as $index => $type) {
            $chunk = fread($this->pipes[$index], 8192);
            if (false === $chunk) {
                throw new SandboxException($this->failureReason, 'Cannot read the guest channel.');
            }
            if ('' !== $chunk) {
                $receive($type, $chunk);
            }
        }
        if (isset($this->pipes[0])) {
            if ('' !== $this->pending) {
                $written = @fwrite($this->pipes[0], substr($this->pending, 0, 8192));
                if (false === $written) {
                    throw new SandboxException('guest_failed', 'Guest closed its input channel.');
                }
                $this->pending = substr($this->pending, $written);
            }
            if ($this->inputClosed && '' === $this->pending) {
                fclose($this->pipes[0]);
                unset($this->pipes[0]);
            }
        }
        $status = proc_get_status($this->process);
        if (!$status['running'] && null === $this->exitCode && $status['exitcode'] >= 0) {
            $this->exitCode = $status['exitcode'];
        }

        return $status['running'] || !feof($this->pipes[1]) || !feof($this->pipes[2]);
    }

    public function succeeded(): bool
    {
        return 0 === $this->exitCode;
    }

    public function close(): void
    {
        if (null === $this->process) {
            return;
        }
        // Never drain guest output or dispatch callbacks while cancelling.
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $this->pipes = [];
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process, \SIGKILL);
        }
        proc_close($this->process);
        $this->process = null;
    }
}
