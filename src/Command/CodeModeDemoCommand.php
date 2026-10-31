<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\AuditDataset;
use App\Demo\BackendFactory;
use App\Demo\DemoRunner;
use App\Demo\ReportStore;
use App\Demo\RunTrace;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'code-mode:demo', description: 'Compare direct MCP tool calling and isolated PHP Code Mode on the same content audit.')]
final class CodeModeDemoCommand extends Command
{
    public function __construct(private readonly DemoRunner $runner, private readonly ReportStore $store) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('mode', null, InputOption::VALUE_REQUIRED, 'reference (no AI) or live (Hugging Face)', 'reference')
            ->addOption('approach', null, InputOption::VALUE_REQUIRED, 'both, code or direct', 'both')
            ->addOption('backend', null, InputOption::VALUE_REQUIRED, 'fixture or drupal', 'fixture');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mode = $input->getOption('mode');
        $approach = $input->getOption('approach');
        $backend = $input->getOption('backend');
        if (!in_array($mode, ['reference', 'live'], true) || !in_array($approach, ['both', 'code', 'direct'], true) || !in_array($backend, ['fixture', 'drupal'], true)
            || ('reference' === $mode && 'drupal' === $backend)) {
            $io->error('Choose reference/live, both/code/direct, and fixture/drupal. Drupal requires live mode.');
            return Command::INVALID;
        }
        if ('live' === $mode && '' === BackendFactory::env('HUGGINGFACE_API_KEY')) {
            $io->error('Set HUGGINGFACE_API_KEY in .env.local for live inference. Reference mode makes no model requests.');
            return Command::INVALID;
        }
        $report = ['version' => 1, 'createdAt' => gmdate(DATE_ATOM), 'status' => 'running', 'mode' => $mode, 'backend' => $backend, 'task' => AuditDataset::TASK, 'results' => []];
        $this->store->save($report);
        $io->title('PHP Code Mode · '.('live' === $mode ? 'live inference' : 'reference run — no model'));
        $failed = false;
        foreach ('both' === $approach ? ['direct', 'code'] : [$approach] as $method) {
            $io->section('code' === $method ? 'Code Mode' : 'Direct MCP tools');
            try {
                $result = $this->runner->run($mode, $method, $backend, progress: static function (RunTrace $trace) use ($io): void {
                    $io->write('.');
                });
                $report['results'][] = $result;
                $failed = $failed || false === $result['verification']['passed'];
                $io->newLine();
                $io->table(['Broker calls', 'Tool payload bytes', 'Model requests', 'Provider tokens'], [[count($result['brokerCalls']), $result['toolPayloadBytes'], $result['modelRequests'], $result['providerTokens']['total'] ?? 'not reported']]);
                $io->text($result['verification']['message']);
            } catch (\Throwable $e) {
                $failed = true;
                // Do not copy backend/provider exception messages into browser reports.
                $report['results'][] = ['approach' => $method, 'mode' => $mode, 'error' => 'Execution failed. Check the trusted runner, MCP backend and provider configuration.', 'verification' => ['passed' => false, 'message' => 'No successful audit was recorded.']];
                $io->error('Execution failed ('.$e::class.'). Check the trusted runner, backend and provider configuration.');
            }
            $this->store->save($report);
        }
        $report['status'] = $failed ? 'failed' : 'complete';
        $this->store->save($report);
        $io->text('Open http://127.0.0.1:8000/demo after composer start. Reports stay in ignored var/demo/.');
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
