<?php

declare(strict_types=1);

namespace CodeMode;

final readonly class GvisorSandbox implements ProfiledExecutor
{
    public function __construct(private string $image, private DockerEngine $docker = new DockerEngine(), private ?ExecutionTimeline $timeline = null)
    {
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/D', $image)) {
            throw new \InvalidArgumentException('Pin the locally built runner by its immutable sha256 image ID. Tags are not accepted.');
        }
    }

    public function profile(): ExecutionProfile { return ExecutionProfile::Gvisor; }

    public function execute(string $code, ToolBroker $broker, ExecutionIdentity $identity, array $allowedTools = [], ?ExecutionLimits $limits = null): ExecutionResult
    {
        if ('cli' !== PHP_SAPI) {
            throw new SandboxException('unsupported_host', 'Run the broker in a dedicated PHP CLI worker.');
        }
        $limits ??= new ExecutionLimits();
        if ('' === $code || strlen($code) > $limits->maxCodeBytes) {
            throw new SandboxException('code_limit', 'PHP source is empty or exceeds its byte limit.');
        }
        $this->docker->requireGvisor();
        $name = 'code-mode-'.bin2hex(random_bytes(16));
        $worker = new BrokerWorker($broker, $identity, $allowedTools, $limits);
        $process = null;
        $creationAttempted = false;
        $calls = [];
        $setupSpan = $executionSpan = null;
        try {
            $setupSpan = $this->timeline?->begin('sandbox_setup', 'Create and inspect isolated container', ['runId' => $name, 'runtime' => $this->profile()->metadata()]);
            // No source mounts, credentials, published ports, devices, or environment passthrough.
            $creationAttempted = true;
            $this->docker->control([
                'create', '--rm', '--name', $name, '--label', 'php-code-mode.managed=true',
                '--runtime', 'runsc', '--network', 'none', '--read-only', '--cap-drop', 'ALL',
                '--security-opt', 'no-new-privileges=true', '--user', '65532:65532',
                '--memory', $limits->memoryMiB.'m', '--memory-swap', $limits->memoryMiB.'m',
                // The cgroup includes gVisor's Go runtime threads, not only guest processes.
                '--cpus', '1', '--pids-limit', '128', '--ulimit', 'nofile=64:64', '--ulimit', 'core=0:0',
                '--tmpfs', '/tmp:rw,noexec,nosuid,nodev,size=4m,uid=65532,gid=65532,mode=700',
                '--ipc', 'none', '--log-driver', 'none', '--hostname', 'php-sandbox',
                '--workdir', '/runner', '--entrypoint', '/bin/busybox', '--interactive',
                // A second watchdog survives a disconnected/killed host coordinator.
                $this->image, 'timeout', '-s', 'KILL', (string) (intdiv($limits->wallTimeMs + 999, 1000) + 2),
                '/usr/local/bin/php', '-n', '-d', 'memory_limit='.$limits->phpMemoryMiB.'M',
                '-d', 'max_execution_time=30', '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0',
                '-d', 'display_errors=stderr', '-d', 'log_errors=0',
                '-d', 'disable_functions=exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork,pcntl_signal,pcntl_alarm,posix_kill,dl,putenv,ini_set,ini_alter,ini_restore,set_time_limit',
                '/runner/bootstrap.php',
            ]);
            $inspection = json_decode($this->docker->control(['inspect', $name]), true, 64, JSON_THROW_ON_ERROR)[0];
            $this->verifyContainer($inspection, $limits);
            $this->timeline?->end($setupSpan);

            $started = BrokerWorker::now();
            $executionSpan = $this->timeline?->begin('execution', 'PHP execution, including broker waits', ['runId' => $name, 'runtime' => $this->profile()->metadata()]);
            $process = $this->docker->attach($name);
            $result = GuestExecution::run($process, $worker, $code, $limits, $name, $started, $calls, $this->profile(), $this->timeline);
            $this->timeline?->end($executionSpan);
            return $result;
        } catch (\Throwable $error) {
            $this->timeline?->end($setupSpan, 'failed');
            $this->timeline?->end($executionSpan, 'failed', ['reason' => $error instanceof SandboxException ? $error->reason : 'execution_failed']);
            foreach ($calls as &$call) {
                if ('pending' === $call['status']) {
                    $call['status'] = 'incomplete';
                }
            }
            unset($call);
            throw new SandboxException($error instanceof SandboxException ? $error->reason : 'execution_failed', 'Sandbox execution failed.', $calls, $error, runId: $name, profile: $this->profile());
        } finally {
            $cleanupSpan = $this->timeline?->begin('cleanup', 'Close worker and remove container', ['runId' => $name, 'runtime' => $this->profile()->metadata()]);
            try {
                $worker->close();
                $process?->close();
                if ($creationAttempted) {
                // Stop the container itself, not just the attached Docker client.
                // Cleanup failure is an error, never reported as successful execution.
                    $this->docker->remove($name);
                }
                $this->timeline?->end($cleanupSpan);
            } catch (\Throwable $error) {
                $this->timeline?->end($cleanupSpan, 'failed');
                throw $error;
            }
        }
    }

    private function verifyContainer(array $container, ExecutionLimits $limits): void
    {
        $host = $container['HostConfig'];
        $config = $container['Config'];
        if ('runsc' !== $host['Runtime'] || 'none' !== $host['NetworkMode'] || !$host['ReadonlyRootfs']
            || $host['Privileged'] || ['ALL'] !== $host['CapDrop'] || !empty($host['CapAdd'])
            || !in_array('no-new-privileges=true', $host['SecurityOpt'], true)
            || $host['Memory'] !== $limits->memoryMiB * 1048576 || $host['MemorySwap'] !== $host['Memory']
            || 1000000000 !== $host['NanoCpus'] || 128 !== $host['PidsLimit'] || !$host['AutoRemove']
            || !empty($host['Binds']) || !empty($host['Devices']) || !empty($host['DeviceRequests'])
            || !empty($host['PortBindings']) || !empty($host['PidMode']) || 'none' !== $host['IpcMode']
            || !empty($container['Mounts']) || '65532:65532' !== $config['User']
            || $container['Image'] !== $this->image || ['/bin/busybox'] !== $config['Entrypoint']
            || ($host['Tmpfs'] ?? []) !== ['/tmp' => 'rw,noexec,nosuid,nodev,size=4m,uid=65532,gid=65532,mode=700']) {
            throw new SandboxException('unsafe_configuration', 'Created container does not match the required isolation profile.');
        }
    }
}
