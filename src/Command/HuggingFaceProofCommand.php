<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\BackendFactory;
use App\Demo\HuggingFaceProofRunner;
use App\Demo\ReportStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'code-mode:huggingface-proof', description: 'Capture a real Symfony AI + Hugging Face audit with isolated PHP and verified host traces.')]
final class HuggingFaceProofCommand extends Command
{
    public function __construct(private readonly HuggingFaceProofRunner $runner, private readonly ReportStore $store) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('approach', null, InputOption::VALUE_REQUIRED, 'code or direct (capture the live baseline separately)', 'code');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $approach = $input->getOption('approach');
        if (!in_array($approach, ['code', 'direct'], true)) {
            $io->error('Choose code or direct.');
            return self::INVALID;
        }
        if ('' === trim(BackendFactory::env('HUGGINGFACE_API_KEY'))) {
            $io->error('Set HUGGINGFACE_API_KEY in ignored .env.local. No provider request was made and no proof was replaced.');
            return self::INVALID;
        }
        $previous = 'direct' === $approach ? json_decode($this->store->readHuggingFaceBaseline() ?? '{}', true, flags: JSON_THROW_ON_ERROR) : [];
        $history = $previous['previousAttempts'] ?? [];
        if ('failed' === ($previous['status'] ?? null)) {
            unset($previous['previousAttempts']);
            $history[] = $previous;
        }
        $io->title('Symfony AI + Hugging Face · real '.$approach.' capture');
        $report = $this->runner->run(function (array $report) use ($approach, $history): void {
            if ('direct' === $approach) {
                if ([] !== $history) { $report['previousAttempts'] = $history; }
                $this->store->saveHuggingFaceBaseline($report);
            } else {
                $this->store->saveHuggingFaceProof($report);
            }
        }, $approach);
        $result = $report['result'] ?? [];
        $io->table(['Model requests', 'PHP attempts', 'Broker calls', 'Tool-result bytes', 'Provider tokens'], [[
            $result['modelRequests'] ?? 0, count($result['programs'] ?? []), count($result['brokerCalls'] ?? []), $result['toolPayloadBytes'] ?? 0, $result['providerTokens']['total'] ?? 'not reported',
        ]]);
        $io->text($report['verification']['message']);
        if (isset($report['error'])) { $io->error($report['error']); }
        $io->text('Read-only evidence: http://127.0.0.1:8000/demo#'.('direct' === $approach ? 'live-comparison' : 'huggingface-proof'));
        return 'complete' === $report['status'] ? self::SUCCESS : self::FAILURE;
    }
}
