<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Command;

use CodeMode\DockerEngine;
use CodeMode\ExecutionLimits;
use CodeMode\ExecutionProfile;
use CodeMode\LocalUnsafeExecutor;
use CodeMode\GvisorSandbox;
use CodeMode\Symfony\SessionFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class DoctorCommand extends Command
{
    public function __construct(private readonly string $image, private readonly DockerEngine $docker, private readonly SessionFactory $sessions, private readonly ExecutionLimits $limits)
    {
        parent::__construct('code-mode:doctor');
        $this->setDescription('Check prerequisites for the configured executor; never execute agent code.');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Return a machine-readable report.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $checks = [];
        $check = static function (string $name, \Closure $probe, string $pass, string $fail) use (&$checks): bool {
            try { $ok = true === $probe(); } catch (\Throwable) { $ok = false; }
            $checks[] = ['name' => $name, 'passed' => $ok, 'detail' => $ok ? $pass : $fail];
            return $ok;
        };
        $check('PHP CLI', static fn () => 'cli' === PHP_SAPI && PHP_VERSION_ID >= 80200, 'PHP '.PHP_VERSION.' CLI', 'Use PHP CLI 8.2 or newer.');
        $check('Host extensions', static fn () => extension_loaded('json') && extension_loaded('pcntl') && extension_loaded('posix'), 'json, pcntl and posix loaded', 'Install json, pcntl and posix on a Unix CLI host.');
        $check('Approved tools', fn () => count($this->sessions->catalog()->all()) > 0, 'Explicit tool services form a valid, bounded catalog', 'Configure approved read services or a Symfony AI toolbox with explicit names and authorization.');
        $local = ExecutionProfile::LocalUnsafe === $this->sessions->profile();
        if ($local) {
            $check('Local PHP supervisor', LocalUnsafeExecutor::available(...), 'Host PHP supports process supervision; generated PHP uses -n. No Docker or image required', 'Use a Unix PHP CLI with proc_open and posix available to the supervisor.');
        } else {
            $pinned = $check('Runner image pin', function (): bool { new GvisorSandbox($this->image, $this->docker); return true; }, 'Immutable sha256 image ID configured', 'Set CODE_MODE_IMAGE to the sha256 ID built on the gVisor Docker endpoint.');
            $check('gVisor runtime', function (): bool { $this->docker->requireGvisor(); return true; }, 'Docker endpoint supplies the required runsc profile', 'Start the dedicated Linux Docker endpoint with runsc; check CODE_MODE_DOCKER_COMMAND. No fallback.');
            $check('Runner image available', fn () => $pinned && $this->image === $this->docker->control(['image', 'inspect', '--format', '{{.Id}}', $this->image]), 'Pinned image exists on that Docker endpoint', 'Build the runner on the configured Docker endpoint and pin its image ID.');
        }
        $ready = !in_array(false, array_column($checks, 'passed'), true);
        $report = ['ready' => $ready, 'runtime' => $this->sessions->profile()->metadata(), 'checks' => $checks, 'limits' => ['wallTimeMs' => $this->limits->wallTimeMs, 'toolTimeMs' => $this->limits->toolTimeMs, 'memoryMiB' => $local ? null : $this->limits->memoryMiB, 'phpMemoryMiB' => $this->limits->phpMemoryMiB, 'maxToolCalls' => $this->limits->maxToolCalls, 'executionAttempts' => 2], 'scope' => 'Configuration checks only; no guest execution, application tool invocation or model request. Run the execution proofs separately.'];
        if ($input->getOption('json')) {
            $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->title('Symfony Code Mode · local prerequisites');
            $io->table(['Check', 'Status', 'What runs locally'], array_map(static fn (array $c) => [$c['name'], $c['passed'] ? 'OK' : 'FAIL', $c['detail']], $checks));
            $io->text(sprintf('Limits: %d ms execution · %d ms/tool · %s · %d calls · 2 attempts', $this->limits->wallTimeMs, $this->limits->toolTimeMs, $local ? $this->limits->phpMemoryMiB.' MiB PHP heap (no OS memory limit)' : $this->limits->memoryMiB.' MiB container memory', $this->limits->maxToolCalls));
            $io->note($report['scope']);
            if ($local) {
                $io->warning($report['runtime']['label'].'. '.$report['runtime']['warning'].' Timeouts and cleanup are best effort; readiness is not a security proof.');
            } else {
                $io->text('A normal Docker Desktop/OrbStack daemon is insufficient without runsc. A local Linux VM may provide the dedicated endpoint. Codex supplies the conversation; Hugging Face is optional for the separate Symfony AI demo.');
            }
        }
        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
