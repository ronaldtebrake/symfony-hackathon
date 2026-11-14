<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\AuditPrompt;
use App\Demo\BackendFactory;
use App\Demo\HuggingFaceComparison;
use App\Demo\HuggingFaceProofRunner;
use App\Demo\ReportStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'code-mode:huggingface-compare', description: 'Capture both approaches with the shared coverage protocol and equal request budgets.')]
final class HuggingFaceCompareCommand extends Command
{
    public function __construct(private readonly HuggingFaceProofRunner $runner, private readonly ReportStore $store, #[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('' === trim(BackendFactory::env('HUGGINGFACE_API_KEY'))) {
            $io->error('Set HUGGINGFACE_API_KEY in ignored .env.local. No capture was replaced.');

            return self::INVALID;
        }
        $originalCode = json_decode(file_get_contents($this->projectDir.'/docs/evidence/huggingface-proof.json'), true, flags: \JSON_THROW_ON_ERROR);
        if ($originalCode['model'] !== BackendFactory::env('HUGGINGFACE_MODEL', 'openai/gpt-oss-120b')) {
            $io->error('Keep the reviewed model unchanged for this comparison.');

            return self::INVALID;
        }
        $previous = $this->store->readHuggingFacePair();
        $originalDirect = json_decode(file_get_contents($this->projectDir.'/docs/evidence/huggingface-baseline.json'), true, flags: \JSON_THROW_ON_ERROR);
        $history = [];
        foreach (['code' => $originalCode, 'direct' => $originalDirect] as $approach => $original) {
            if (null === ($previous[$approach] ?? null) && isset($previous['retainedAttempts'][$approach])) {
                $history[$approach] = $previous['retainedAttempts'][$approach];
                continue;
            }
            $prior = $previous[$approach] ?? $original;
            $history[$approach] = $prior['previousAttempts'] ?? [];
            unset($prior['previousAttempts']);
            $history[$approach][] = $prior;
        }
        $pair = ['version' => 2, 'kind' => 'huggingface-pair', 'protocol' => AuditPrompt::COVERAGE_PROTOCOL,
            'status' => 'running', 'createdAt' => gmdate(\DATE_ATOM), 'code' => null, 'direct' => null, 'retainedAttempts' => $history];
        // Persist both histories before either run starts, including if the process is interrupted.
        $this->store->saveHuggingFacePair($pair);
        $io->title('Matched live comparison · '.AuditPrompt::COVERAGE_PROTOCOL.' · 14 requests per approach');
        foreach (['code', 'direct'] as $approach) {
            $io->section($approach);
            $reference = 'direct' === $approach ? HuggingFaceComparison::encodeCapture($pair['code']) : null;
            $capture = $this->runner->run(function (array $capture) use (&$pair, $approach, $history): void {
                $capture['previousAttempts'] = $history[$approach];
                $pair[$approach] = $capture;
                unset($pair['retainedAttempts'][$approach]);
                $this->store->saveHuggingFacePair($pair);
            }, $approach, AuditPrompt::COVERAGE_PROTOCOL, $reference);
            $result = $capture['result'] ?? [];
            $io->table(['Status', 'Requests', 'Tokens', 'MCP calls'], [[$capture['status'], $result['modelRequests'] ?? 0, $result['providerTokens']['total'] ?? 'not reported', \count($result['brokerCalls'] ?? [])]]);
            $io->text($capture['verification']['message']);
        }
        $comparison = null !== $pair['code'] && null !== $pair['direct']
            ? (new HuggingFaceComparison())->build(HuggingFaceComparison::encodeCapture($pair['code']), $pair['direct']) : null;
        $pair['status'] = true === ($comparison['verifiedComparison'] ?? false) ? 'complete' : 'failed';
        $pair['completedAt'] = gmdate(\DATE_ATOM);
        $this->store->saveHuggingFacePair($pair);
        $io->text('Read-only evidence: http://127.0.0.1:8000/demo#live-comparison');

        return 'complete' === $pair['status'] ? self::SUCCESS : self::FAILURE;
    }
}
