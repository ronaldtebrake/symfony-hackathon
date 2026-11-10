<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Command;

use CodeMode\Symfony\Http\CatalogStateStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

final class ResetBudgetCommand extends Command
{
    public function __construct(private readonly CatalogStateStore $states)
    {
        parent::__construct('code-mode:reset-budget');
        $this->setDescription('Operator-only reset of one authenticated user\'s catalog discovery and execution budget.');
        $this->addArgument('subject', InputArgument::REQUIRED, 'The authenticated Symfony user identifier.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $subject = $input->getArgument('subject');
        if ('' === $subject) { throw new \InvalidArgumentException('A user identifier is required.'); }
        $this->states->reset($subject);
        $output->writeln('Catalog budget reset.');
        return self::SUCCESS;
    }
}
