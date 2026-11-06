<?php

declare(strict_types=1);

namespace CodeMode;

/** Explicit development opt-in. A subprocess and PHP restrictions are NOT a sandbox. */
final readonly class LocalUnsafeExecutor implements ProfiledExecutor
{
    public function __construct(private ?ExecutionTimeline $timeline = null) {}

    public function profile(): ExecutionProfile { return ExecutionProfile::LocalUnsafe; }

    /** The trusted supervisor uses installed extensions; only generated PHP uses -n. */
    private static function supervisorCommand(): array
    {
        return [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', '-d', 'display_errors=stderr', '-d', 'log_errors=0'];
    }

    /** Probe the actual CLI interpreter, including distributions with posix loaded from php.ini. */
    public static function available(): bool
    {
        if ('cli' !== PHP_SAPI || !function_exists('proc_open') || !function_exists('posix_getpgid') || !function_exists('pcntl_fork')) {
            return false;
        }
        $process = new ProcessSession([...self::supervisorCommand(), '-r', 'exit(PHP_VERSION_ID >= 80200 && function_exists("posix_setsid") && function_exists("posix_kill") && function_exists("proc_open") ? 0 : 1);'], []);
        try {
            $deadline = BrokerWorker::now() + 2;
            do {
                if (BrokerWorker::now() >= $deadline) { return false; }
                $running = $process->tick(static function (): void {});
                if ($running) { usleep(1000); }
            } while ($running);
            return $process->succeeded();
        } finally {
            $process->close();
        }
    }

    public function execute(string $code, ToolBroker $broker, ExecutionIdentity $identity, array $allowedTools = [], ?ExecutionLimits $limits = null): ExecutionResult
    {
        $limits ??= new ExecutionLimits();
        $name = 'code-mode-local-'.bin2hex(random_bytes(16));
        $runtime = $this->profile()->metadata();
        $directory = null;
        $process = $worker = null;
        $pid = null;
        $calls = [];
        $setupSpan = $executionSpan = null;
        try {
            if ('cli' !== PHP_SAPI || !function_exists('posix_getpgid') || !function_exists('pcntl_fork')) {
                throw new SandboxException('unsupported_host', 'Local execution requires a Unix PHP CLI host.');
            }
            if ('' === $code || strlen($code) > $limits->maxCodeBytes) {
                throw new SandboxException('code_limit', 'PHP source is empty or exceeds its byte limit.');
            }
            $setupSpan = $this->timeline?->begin('local_setup', $runtime['label'], ['runId' => $name, 'runtime' => $runtime]);
            $candidate = sys_get_temp_dir().'/'.$name;
            if (!@mkdir($candidate, 0700)) {
                throw new SandboxException('local_start', 'Cannot allocate local working directory.');
            }
            $directory = $candidate;
            // Fork trusted handlers before opening the generated program's pipes.
            $worker = new BrokerWorker($broker, $identity, $allowedTools, $limits);
            $process = new ProcessSession([...self::supervisorCommand(),
                dirname(__DIR__).'/runner/local-supervisor.php', (string) $limits->wallTimeMs, (string) $limits->phpMemoryMiB], [], $directory);
            $pid = $process->pid();
            $startupDeadline = BrokerWorker::now() + 2;
            // The supervisor creates a new process group before launching PHP. Never signal the host group.
            while ($pid !== posix_getpgid($pid)) {
                if (BrokerWorker::now() >= $startupDeadline || !posix_kill($pid, 0)) {
                    throw new SandboxException('local_start', 'Cannot start the local PHP supervisor.');
                }
                usleep(1000);
            }
            $this->timeline?->end($setupSpan);
            $started = BrokerWorker::now();
            $executionSpan = $this->timeline?->begin('execution', $runtime['label'], ['runId' => $name, 'runtime' => $runtime]);
            $result = GuestExecution::run($process, $worker, $code, $limits, $name, $started, $calls, $this->profile(), $this->timeline);
            $this->timeline?->end($executionSpan);
            return $result;
        } catch (\Throwable $error) {
            $reason = $error instanceof SandboxException ? $error->reason : 'execution_failed';
            $this->timeline?->end($setupSpan, 'failed', ['reason' => $reason]);
            $this->timeline?->end($executionSpan, 'failed', ['reason' => $reason]);
            throw new SandboxException($reason, 'Unsandboxed local execution failed.', $calls, $error, $name, $this->profile());
        } finally {
            $cleanup = $this->timeline?->begin('cleanup', 'Stop local process group and broker', ['runId' => $name, 'runtime' => $runtime]);
            try {
                if (null !== $pid) {
                    // Best effort: same-user hostile code can escape or attack this supervisor.
                    @posix_kill(-$pid, SIGKILL);
                }
                $process?->close();
                $worker?->close();
                // Never recursively delete a tree that unsandboxed PHP could replace with host paths.
                if (null !== $directory && !@rmdir($directory)) {
                    throw new SandboxException('cleanup_failed', 'Local working directory was not empty or could not be removed.', $calls, runId: $name, profile: $this->profile());
                }
                $this->timeline?->end($cleanup);
            } catch (\Throwable $error) {
                $this->timeline?->end($cleanup, 'failed');
                throw $error;
            }
        }
    }
}
