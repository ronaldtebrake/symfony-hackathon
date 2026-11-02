<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\BackendFactory;
use App\Demo\HuggingFaceProofRunner;
use App\Demo\ReportStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'code-mode:huggingface-proof', description: 'Capture a real Symfony AI + Hugging Face audit with isolated PHP and verified host traces.')]
final class HuggingFaceProofCommand extends Command
{
    public function __construct(private readonly HuggingFaceProofRunner $runner, private readonly ReportStore $store) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('' === trim(BackendFactory::env('HUGGINGFACE_API_KEY'))) {
            $io->error('Set HUGGINGFACE_API_KEY in ignored .env.local. No provider request was made and no proof was replaced.');
            return self::INVALID;
        }
        $io->title('Symfony AI + Hugging Face · real Code Mode capture');
        $report = $this->runner->run(function (array $report): void { $this->store->saveHuggingFaceProof($report); });
        $result = $report['result'] ?? [];
        $io->table(['Model requests', 'PHP attempts', 'Broker calls', 'Tool-result bytes', 'Provider tokens'], [[
            $result['modelRequests'] ?? 0, count($result['programs'] ?? []), count($result['brokerCalls'] ?? []), $result['toolPayloadBytes'] ?? 0, $result['providerTokens']['total'] ?? 'not reported',
        ]]);
        $io->text($report['verification']['message']);
        if (isset($report['error'])) { $io->error($report['error']); }
        $io->text('Read-only evidence: http://127.0.0.1:8000/demo#huggingface-proof');
        return 'complete' === $report['status'] ? self::SUCCESS : self::FAILURE;
    }
}
