<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\Performance\ProofRunner;
use App\Demo\ReportStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'code-mode:performance-proof', description: 'Capture a read-only Symfony profiler/log investigation and restricted-read denials in gVisor. No model call.')]
final class PerformanceProofCommand extends Command
{
    public function __construct(private readonly ProofRunner $runner, private readonly ReportStore $store) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!extension_loaded('pdo_sqlite')) {
            (new SymfonyStyle($input, $output))->error('Install pdo_sqlite for the synthetic performance fixture. The existing proof viewer does not require it.');
            return self::INVALID;
        }
        $previous = json_decode($this->store->readLocalPerformanceProof() ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        $history = $previous['previousAttempts'] ?? [];
        if ([] !== $previous) { unset($previous['previousAttempts']); $history[] = $previous; }
        if (count($history) >= 4) {
            (new SymfonyStyle($input, $output))->error('Four attempts are retained. Review and archive the evidence before another capture.');
            return self::INVALID;
        }
        $report = $this->runner->run();
        if ([] !== $history) { $report['previousAttempts'] = $history; }
        $this->store->savePerformanceProof($report);
        $io = new SymfonyStyle($input, $output);
        $io->table(['Check', 'Passed'], array_map(static fn ($key, $value) => [$key, $value ? 'yes' : 'no'], array_keys($report['verification']['checks']), $report['verification']['checks']));
        $io->text('Synthetic traffic; real PHP, MCP and profiler records. Two ms simulated latency per query. No model called.');
        $io->text('Read-only evidence: http://127.0.0.1:8000/demo#performance');
        return 'complete' === $report['status'] ? self::SUCCESS : self::FAILURE;
    }
}
