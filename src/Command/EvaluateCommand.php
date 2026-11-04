<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\{BackendFactory, EvaluationRunner, EvaluationScenario, ReportStore};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'code-mode:evaluate', description: 'Compare direct tools and isolated PHP on five fixed Symfony tasks; retain every planned attempt.')]
final class EvaluateCommand extends Command
{
    public function __construct(private readonly EvaluationRunner $runner, private readonly ReportStore $store, #[Autowire('%kernel.project_dir%')] private readonly string $projectDir) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('live', null, InputOption::VALUE_NONE, 'Use real Hugging Face inference credits; otherwise run fixed reference algorithms with real MCP and gVisor.')
            ->addOption('repetitions', null, InputOption::VALUE_REQUIRED, 'Predeclared repetitions per scenario and approach (1–3).', '2')
            ->addOption('scenario', null, InputOption::VALUE_REQUIRED, 'all, pagination, join, follow_up, boundary, or injection.', 'all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $live = $input->getOption('live'); $repeat = $input->getOption('repetitions'); $scenario = $input->getOption('scenario');
        if (!is_string($repeat) || !preg_match('/\A[1-3]\z/D', $repeat) || !in_array($scenario, ['all', ...EvaluationScenario::IDS], true)) {
            $io->error('Choose 1–3 repetitions and a documented scenario. No execution started.'); return self::INVALID;
        }
        if ($live && '' === trim(BackendFactory::env('HUGGINGFACE_API_KEY'))) {
            $io->error('Set HUGGINGFACE_API_KEY in ignored .env.local before using --live.'); return self::INVALID;
        }
        $directory = $this->projectDir.'/var/demo';
        if (!is_dir($directory)) { mkdir($directory, 0700, true); }
        $lock = fopen($directory.'/evaluation.lock', 'c');
        if (false === $lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) { fclose($lock); }
            $io->error('An evaluation is already running.'); return self::FAILURE;
        }
        try {
            $report = json_decode($this->store->readEvaluation() ?? '{"version":1,"kind":"evaluation","batches":[]}', true, flags: JSON_THROW_ON_ERROR);
            if (count($report['batches']) >= 4) { $io->error('Four batches are retained. Review and archive the local report before further captures.'); return self::INVALID; }
            $scenarios = 'all' === $scenario ? EvaluationScenario::IDS : [$scenario];
            $batch = count($report['batches']);
            $report['batches'][] = ['id' => bin2hex(random_bytes(8)), 'createdAt' => gmdate(DATE_ATOM), 'status' => 'running',
                'mode' => $live ? 'live' : 'reference', 'repetitions' => (int) $repeat, 'scenarios' => $scenarios,
                'manifest' => EvaluationRunner::manifest(), 'runs' => []];
            // Declare the full schedule before running anything. Interruptions leave visible pending rows.
            for ($i = 1; $i <= (int) $repeat; ++$i) {
                foreach ($scenarios as $id) {
                    foreach (1 === $i % 2 ? ['direct', 'code'] : ['code', 'direct'] as $approach) {
                        $report['batches'][$batch]['runs'][] = ['scenario' => $id, 'approach' => $approach, 'repeat' => $i, 'mode' => $live ? 'live' : 'reference', 'status' => 'pending'];
                    }
                }
            }
            $this->store->saveEvaluation($report);
            $io->text($live ? sprintf('Real Hugging Face run: at most %d requests. Every attempt is retained.', count($report['batches'][$batch]['runs']) * EvaluationScenario::REQUEST_LIMIT) : 'Reference algorithms; real MCP and gVisor. No model requests or model-performance claim.');
            $failed = false;
            foreach ($report['batches'][$batch]['runs'] as $index => $planned) {
                $io->text(sprintf('%s · repetition %d · %s', $planned['scenario'], $planned['repeat'], $planned['approach']));
                $result = $this->runner->run($planned['scenario'], $planned['approach'], $live, function (array $partial) use (&$report, $batch, $index, $planned): void {
                    $report['batches'][$batch]['runs'][$index] = $partial + ['repeat' => $planned['repeat']];
                    $this->store->saveEvaluation($report);
                });
                $failed = $failed || 'passed' !== $result['status'];
                $io->text(sprintf('  %s · %d requests · %s tokens · %.0f ms', strtoupper($result['status']), $result['trace']['modelRequests'], $result['trace']['providerTokens']['total'] ?? 'not measured', $result['elapsedMs']));
                if ('passed' !== $result['status']) { $io->text('  Checks requiring review: '.implode(', ', array_keys(array_filter($result['verification']['checks'], static fn ($passed) => !$passed)))); }
                // A runtime/provider outage must not trigger an entire batch of futile requests.
                if ([] === $result['trace']['brokerCalls'] && isset($result['error'])) { break; }
            }
            $report['batches'][$batch]['status'] = $failed ? 'failed' : 'complete';
            $report['batches'][$batch]['completedAt'] = gmdate(DATE_ATOM);
            $this->store->saveEvaluation($report);
            $io->text('Evidence and execution timeline: http://127.0.0.1:8000/demo#evaluation');
            return $failed ? self::FAILURE : self::SUCCESS;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
