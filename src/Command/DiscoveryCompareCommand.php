<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\{AdoptionProofRunner, BackendFactory, DiscoveryCatalog, DiscoveryComparison, ReportStore};
use CodeMode\DiscoveryMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'code-mode:discovery-compare', description: 'Compare full and compact discovery on the same native Symfony AI task, retaining every attempt.')]
final class DiscoveryCompareCommand extends Command
{
    public function __construct(private readonly AdoptionProofRunner $runner, private readonly ReportStore $store) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('live', null, InputOption::VALUE_NONE, 'Use Hugging Face credits: at most twelve model requests per arm and repetition.')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Run only pending conversations in the current live batch after checking unchanged conditions.')
            ->addOption('catalog', null, InputOption::VALUE_REQUIRED, 'Catalog: small (2 tools) or large (16 tools with complex fallback).', 'small')
            ->addOption('repetitions', null, InputOption::VALUE_REQUIRED, 'One or two paired runs; reverse order on the second.', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $live = (bool) $input->getOption('live');
        $repetitions = $input->getOption('repetitions');
        $catalog = $input->getOption('catalog');
        if (!in_array($catalog, ['small', 'large'], true) || !in_array($repetitions, ['1', '2'], true) || ($live && '' === trim(BackendFactory::env('HUGGINGFACE_API_KEY')))) {
            $io->error('Choose a small or large catalog and one or two repetitions. Live capture also requires HUGGINGFACE_API_KEY in ignored .env.local. No capture was replaced.');
            return self::INVALID;
        }
        $previous = json_decode($this->store->readDiscoveryComparison() ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        if ($input->getOption('resume')) {
            if (!$live || ($previous['catalogProfile'] ?? null) !== ('large' === $catalog ? DiscoveryCatalog::PROFILE : 'publishing-2-v1')
                || ($previous['searchProtocol'] ?? null) !== \CodeMode\ToolCatalog::SEARCH_PROTOCOL
                || count($previous['attempts'] ?? []) !== 2 * (int) $repetitions
                || !array_filter($previous['attempts'] ?? [], static fn ($attempt) => 'pending' === $attempt['status'])) {
                $io->error('Resume requires the same live catalog, protocol and schedule, with pending conversations. No capture was changed.'); return self::INVALID;
            }
            foreach ($previous['attempts'] as $attempt) {
                if ('running' === $attempt['status']) { $io->error('A conversation was already started. It cannot be replayed by resume.'); return self::INVALID; }
                if (null !== $attempt['proof']) {
                    try { $this->runner->assertResumeConditions($attempt['proof']); }
                    catch (\RuntimeException|\InvalidArgumentException $error) { $io->error($error->getMessage()); return self::INVALID; }
                }
            }
            $report = $previous;
            $report['status'] = 'running'; $report['resumedAt'] = gmdate(DATE_ATOM);
            unset($report['completedAt']);
        } else {
            $history = $previous['previousAttempts'] ?? [];
            if ([] !== $previous) { unset($previous['previousAttempts']); $history[] = $previous; }
            if (count($history) >= 8) { $io->error('Eight batches are retained. Review the evidence before further captures.'); return self::INVALID; }
            $report = ['version' => 1, 'kind' => 'discovery-comparison', 'mode' => $live ? 'live' : 'local', 'status' => 'running',
                'catalogProfile' => 'large' === $catalog ? DiscoveryCatalog::PROFILE : 'publishing-2-v1',
                'searchProtocol' => \CodeMode\ToolCatalog::SEARCH_PROTOCOL, 'createdAt' => gmdate(DATE_ATOM), 'attempts' => [], 'previousAttempts' => $history];
            for ($i = 1; $i <= (int) $repetitions; ++$i) {
                foreach (1 === $i ? ['full', 'compact'] : ['compact', 'full'] as $mode) {
                    $report['attempts'][] = ['repetition' => $i, 'discovery' => $mode, 'status' => 'pending', 'proof' => null];
                }
            }
        }
        $save = function () use (&$report): void {
            $report['summary'] = (new DiscoveryComparison())->summarize($report);
            $this->store->saveDiscoveryComparison($report);
        };
        $save(); // Persist the entire schedule before any provider request.
        foreach ($report['attempts'] as $index => $attempt) {
            if ('pending' !== $attempt['status']) { continue; }
            $io->text(sprintf('Repetition %d: %s discovery (%s).', $attempt['repetition'], $attempt['discovery'], $report['mode']));
            $this->runner->run($live, function (array $proof) use (&$report, $index, $save): void {
                $report['attempts'][$index]['proof'] = $proof;
                $report['attempts'][$index]['status'] = $proof['status'];
                $save();
            }, discovery: DiscoveryMode::from($attempt['discovery']), catalog: $report['catalogProfile']);
            // Malformed model output counts as a failed attempt; continue the planned schedule.
            // Runtime/transport failures retain the pending schedule for an explicit resume.
            if (isset($report['attempts'][$index]['proof']['error'])
                && \Symfony\AI\Platform\Exception\MalformedToolCallException::class !== ($report['attempts'][$index]['proof']['errorType'] ?? null)) { break; }
        }
        $report['summary'] = (new DiscoveryComparison())->summarize($report);
        $report['status'] = $report['summary']['allCorrect'] ? 'complete' : 'failed';
        $report['completedAt'] = gmdate(DATE_ATOM);
        $save();
        $io->table(['Discovery', 'Correct / planned conversations', 'Correct answers', 'Model requests', 'Provider tokens', 'Discovery requests', 'Discovery bytes'], array_map(
            static fn ($mode, $row) => [$mode, $row['correct'].' / '.$row['planned'], $row['questions'] ? $row['correctAnswers'].' / '.$row['questions'] : 'no model', $row['modelRequests'], $row['providerTokens'] ?? ($row['modelRequests'] ? $row['observedTokens'].' observed; '.$row['meteredResponses'].'/'.$row['modelRequests'].' metered' : 'not measured'), $row['discoveryRequests'], $row['discoveryBytes']],
            array_keys($report['summary']['totals']), $report['summary']['totals']));
        $io->text('Read-only evidence: http://127.0.0.1:8000/demo#compact-discovery');
        return 'complete' === $report['status'] ? self::SUCCESS : self::FAILURE;
    }
}
