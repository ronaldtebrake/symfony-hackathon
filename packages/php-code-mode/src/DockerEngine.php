<?php

declare(strict_types=1);

namespace CodeMode;

use Symfony\Component\Process\Process;

/** Trusted deployment configuration; never accept this command from an agent. */
final readonly class DockerEngine
{
    /** @param non-empty-list<string> $command An argv prefix, not a shell string. */
    public function __construct(private array $command = ['docker'])
    {
        if ([] === $command || !array_is_list($command)) {
            throw new \InvalidArgumentException('A Docker command argv is required.');
        }
        foreach ($command as $argument) {
            if (!\is_string($argument) || '' === $argument || str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('Invalid Docker command argument.');
            }
        }
    }

    /** @param list<string> $arguments */
    public function process(array $arguments): Process
    {
        $environment = array_fill_keys(array_keys($_ENV + getenv()), false);

        return new Process([...$this->command, ...$arguments], env: array_replace($environment, $this->environment()), timeout: 15);
    }

    public function attach(string $name): DockerSession
    {
        return new DockerSession([...$this->command, 'start', '--attach', '--interactive', $name], $this->environment());
    }

    /** @param list<string> $arguments */
    public function control(array $arguments): string
    {
        return $this->runControl($arguments);
    }

    public function remove(string $name): void
    {
        if (!preg_match('/\Acode-mode-[a-f0-9]{32}\z/D', $name)) {
            throw new \InvalidArgumentException('Refusing to remove an unmanaged container name.');
        }
        // AutoRemove can win the race. Only an exact missing-container error is benign.
        $deadline = BrokerWorker::now() + 3;
        while (true) {
            try {
                $this->runControl(['rm', '--force', $name], $name);

                return;
            } catch (SandboxException $e) {
                if ('docker_failed' !== $e->reason) {
                    throw $e;
                }
                // Docker may report removal already in progress during AutoRemove.
                // Confirm absence through the daemon instead of ignoring a cleanup error.
                if ('' === $this->control(['ps', '-aq', '--filter', 'name=^/'.$name.'$'])) {
                    return;
                }
                if (BrokerWorker::now() >= $deadline) {
                    throw $e;
                }
                usleep(10000);
            }
        }
    }

    public function requireGvisor(): void
    {
        $runtime = json_decode($this->control(['info', '--format', '{{json .Runtimes.runsc}}']), true, 32, \JSON_THROW_ON_ERROR);
        if (!\is_array($runtime) || !preg_match('~(?:^|/)runsc$~D', $runtime['path'] ?? '')) {
            throw new SandboxException('isolation_unavailable', 'The Docker endpoint must provide the runsc gVisor runtime. No fallback is allowed.');
        }
        // Keep this proof's runtime profile narrow; a name alone is not sufficient.
        foreach ($runtime['runtimeArgs'] ?? [] as $argument) {
            if (!\in_array($argument, ['--platform=systrap'], true)) {
                throw new SandboxException('isolation_unavailable', 'The gVisor runtime has unreviewed configuration flags.');
            }
        }
    }

    /** Docker connection settings are trusted host configuration, never container environment. */
    private function environment(): array
    {
        $environment = [];
        foreach (['PATH', 'HOME', 'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_CONFIG', 'SSH_AUTH_SOCK', 'XDG_RUNTIME_DIR', 'TMPDIR'] as $name) {
            $value = getenv($name);
            if (false !== $value) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    private function runControl(array $arguments, ?string $missingContainer = null): string
    {
        $process = $this->process($arguments);
        $output = '';
        $errors = '';
        $bytes = 0;
        try {
            $process->run(static function (string $type, string $chunk) use (&$output, &$errors, &$bytes, $process): void {
                $bytes += \strlen($chunk);
                if ($bytes > 131072) {
                    throw new SandboxException('docker_output_limit', 'Docker control output exceeded its limit.');
                }
                if (Process::OUT === $type) {
                    $output .= $chunk;
                } else {
                    $errors .= $chunk;
                }
                $process->clearOutput();
                $process->clearErrorOutput();
            });
            if (!$process->isSuccessful()) {
                if (1 === $process->getExitCode() && null !== $missingContainer
                    && 'Error response from daemon: No such container: '.$missingContainer === trim($errors)) {
                    return '';
                }
                throw new SandboxException('docker_failed', 'Docker '.$arguments[0].' failed. Check the trusted Docker endpoint and image.');
            }

            return trim($output);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0, \SIGKILL);
            }
        }
    }
}
