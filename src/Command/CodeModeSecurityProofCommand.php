<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\ReportStore;
use App\Demo\SecurityProofRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'code-mode:security-proof', description: 'Record three real PHP security probes against the read-only Symfony MCP fixture and gVisor.')]
final class CodeModeSecurityProofCommand extends Command
{
    public function __construct(private readonly SecurityProofRunner $runner, private readonly ReportStore $store)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Symfony Code Mode · security proof');
        $io->text('Fixed PHP probes, real MCP and gVisor. No model is called.');
        $report = $this->runner->run(fn (array $report) => $this->store->saveSecurityProof($report));
        $io->table(['Probe', 'Observed', 'Container removed', 'Check'], array_map(static fn ($case) => [
            $case['title'], $case['reason'] ?? 'succeeded', true === $case['containerRemoved'] ? 'yes' : 'not confirmed', $case['passed'] ? 'PASS' : 'FAIL',
        ], $report['cases']));
        $io->text('View the host-recorded evidence at http://127.0.0.1:8000/demo#security-proof after composer start.');
        if ('complete' !== $report['status']) {
            $io->error('The security proof did not pass. Check the recorded observations and trusted runtime configuration.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
