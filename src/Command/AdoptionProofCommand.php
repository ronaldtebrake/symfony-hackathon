<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\AdoptionProofRunner;
use App\Demo\BackendFactory;
use App\Demo\ReportStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'code-mode:adoption-proof', description: 'Prove native Symfony AI tool adoption, injected-content denial and optional live follow-up.')]
final class AdoptionProofCommand extends Command
{
    public function __construct(private readonly AdoptionProofRunner $runner, private readonly ReportStore $store) { parent::__construct(); }
    protected function configure(): void { $this->addOption('live', null, InputOption::VALUE_NONE, 'Also capture two real Hugging Face turns (uses inference credits, at most twelve model requests).'); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $live = $input->getOption('live');
        if ($live && '' === trim(BackendFactory::env('HUGGINGFACE_API_KEY'))) {
            $io->error('Set HUGGINGFACE_API_KEY in ignored .env.local. No model request was made or proof replaced.');
            return self::INVALID;
        }
        $previous = json_decode($this->store->readAdoptionProof() ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        $history = $previous['previousAttempts'] ?? [];
        if ([] !== $previous) { unset($previous['previousAttempts']); $history[] = $previous; }
        if (count($history) >= 8) {
            $io->error('Eight attempts are retained. Review the evidence before starting further captures.');
            return self::INVALID;
        }
        $report = $this->runner->run($live, function (array $report) use ($history): void {
            if ([] !== $history) { $report['previousAttempts'] = $history; }
            $this->store->saveAdoptionProof($report);
        });
        $io->table(['Check', 'Passed'], array_map(static fn ($key, $value) => [$key, $value ? 'yes' : 'no'], array_keys($report['verification']['checks']), $report['verification']['checks']));
        if ($live) {
            $trace = $report['liveTrace'] ?? [];
            $io->text(sprintf('Model requests: %d; provider tokens: %s; tool-result bytes: %d.', $trace['modelRequests'] ?? 0, $trace['providerTokens']['total'] ?? 'not reported', $trace['toolPayloadBytes'] ?? 0));
        }
        $io->text('Read-only evidence: http://127.0.0.1:8000/demo#adoption-proof');
        return 'complete' === $report['status'] ? self::SUCCESS : self::FAILURE;
    }
}
