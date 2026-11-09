<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\{BackendFactory, ThreeWayAuditPrompt, ThreeWayAuditRunner, ThreeWayAuditVerifier};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'code-mode:audit-three-way', description: 'Capture a fresh direct / JSON batch / Code Mode audit with identical data and model settings.')]
final class ThreeWayAuditCommand extends Command
{
    public function __construct(private readonly ThreeWayAuditRunner $runner, #[Autowire('%kernel.project_dir%')] private readonly string $projectDir) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('protocol', null, InputOption::VALUE_REQUIRED, 'audit-reviewed-v2 or catalog iterations v3–v10. v10 uses an explicit keep-or-replace answer review.', ThreeWayAuditPrompt::CURRENT);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $protocol = $input->getOption('protocol');
        if (!in_array($protocol, [ThreeWayAuditPrompt::CURRENT, ThreeWayAuditPrompt::CATALOG, ThreeWayAuditPrompt::TEXT_REVIEW, ThreeWayAuditPrompt::EXPLICIT_REVIEW, ThreeWayAuditPrompt::UNICODE_REVIEW, ThreeWayAuditPrompt::UTF8_RESULTS, ThreeWayAuditPrompt::PLAIN_REVIEW, ThreeWayAuditPrompt::PRESERVING_REVIEW, ThreeWayAuditPrompt::DECISION_REVIEW], true)) { $io->error('Choose a supported audit protocol.'); return self::INVALID; }
        if (BackendFactory::env('HUGGINGFACE_MODEL', ThreeWayAuditRunner::MODEL) !== ThreeWayAuditRunner::MODEL || '' === trim(BackendFactory::env('HUGGINGFACE_API_KEY'))) {
            $io->error('Keep the audit model and configure the inference key in ignored .env.local.'); return self::INVALID;
        }
        umask(0077);
        $directory = $this->projectDir.'/var/demo/audit-three-way';
        if (!is_dir($directory)) { mkdir($directory, 0700, true); }
        $lock = fopen($directory.'/capture.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('Another three-way capture is active.'); }
        $path = $directory.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        $attempt = ['version' => 1, 'kind' => ThreeWayAuditRunner::PROTOCOL, 'id' => basename($path, '.json'), 'status' => 'running',
            'protocol' => $protocol, 'createdAt' => gmdate(DATE_ATOM), 'order' => ['direct', 'batch', 'code'], 'runs' => []];
        $write = static function () use (&$attempt, $path): void {
            $bytes = json_encode($attempt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (strlen($bytes) > 8 * 1048576 || file_put_contents($path.'.tmp', $bytes, LOCK_EX) !== strlen($bytes) || !rename($path.'.tmp', $path)) { throw new \RuntimeException('Cannot retain the bounded comparison capture.'); }
        };
        $write();
        $io->title('Live three-way Symfony audit · '.$protocol);
        $io->text('Same question, model, fixture, identity and 14-request ceiling, including one shared answer review. Batch: 14 submissions/60 reads; PHP: 2 executions of at most 30 reads. No oracle feedback, history pruning or automatic retries.');
        try {
            foreach ($attempt['order'] as $mode) {
                $io->section($mode);
                $lastRequest = 0;
                $capture = $this->runner->run($mode, static function (array $capture) use (&$attempt, $mode, $write, $io, &$lastRequest): void {
                    $attempt['runs'][$mode] = $capture; $write();
                    $requests = $capture['result']['modelRequests'] ?? 0;
                    if ($requests > $lastRequest) { $io->text('Observed request '.$requests.'; '.count($capture['result']['brokerCalls'] ?? []).' underlying calls so far.'); $lastRequest = $requests; }
                }, $protocol);
                $io->table(['Status', 'Model requests', 'Provider tokens', 'Underlying calls'], [[
                    $capture['status'], $capture['result']['modelRequests'], $capture['verification']['knownProviderTokens']['total'].($capture['verification']['unreportedRequests'] ? ' known; usage incomplete' : ''), count($capture['result']['brokerCalls'])]]);
                foreach ($capture['verification']['checks'] as $name => $passed) { if (!$passed) { $io->text('Not verified: '.$name); } }
                // Do not hammer a provider that has explicitly rejected access or quota.
                if (in_array($capture['error'] ?? null, ['provider_rate_limited', 'provider_authentication_failed'], true)) { break; }
            }
            $attempt = json_decode(json_encode($attempt, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
            $attempt['verification'] = ThreeWayAuditVerifier::compare($attempt);
            $attempt['status'] = $attempt['verification']['passed'] ? 'complete' : 'failed';
            $attempt['completedAt'] = gmdate(DATE_ATOM); $write();
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        $io->text('Retained capture: '.$path);
        return 'complete' === $attempt['status'] ? self::SUCCESS : self::FAILURE;
    }
}
