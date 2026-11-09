<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\Performance\BaselineRunner;
use App\Demo\ReportStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'code-mode:performance-baseline', description: 'Capture direct-tool Hugging Face turns against the exact performance conversation snapshot. Uses inference credits.')]
final class PerformanceBaselineCommand extends Command
{
    public function __construct(private readonly BaselineRunner $runner, private readonly ReportStore $store) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $this->store->readPerformanceConversation();
        if (null === $source) { $io->error('Capture the Code Mode performance conversation first.'); return self::INVALID; }
        $previous = json_decode($this->store->readPerformanceBaseline() ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        $history = $previous['previousAttempts'] ?? [];
        if ([] !== $previous) { unset($previous['previousAttempts']); $history[] = $previous; }
        if (count($history) >= 4) { $io->error('Four attempts retained. Review the evidence before another capture.'); return self::INVALID; }
        $report = $this->runner->run($source, function (array $report) use ($history, $io): void {
            if ([] !== $history) { $report['previousAttempts'] = $history; }
            $this->store->savePerformanceBaseline($report);
            $io->text('Capture '.$report['status'].'; completed turns: '.count(array_filter($report['turns'], static fn ($t) => isset($t['answer']))).'; model requests: '.($report['trace']['modelRequests'] ?? 0));
        });
        $io->table(['Check', 'Passed'], array_map(static fn ($k, $v) => [$k, $v ? 'yes' : 'no'], array_keys($report['verification']['checks']), $report['verification']['checks']));
        $io->text('Saved locally: var/demo/performance-baseline.json. No publication or commit.');
        return 'complete' === $report['status'] ? self::SUCCESS : self::FAILURE;
    }
}
