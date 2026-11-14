<?php

declare(strict_types=1);

namespace Adoption;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Operator command only; never register this service as an AI or MCP tool. */
final class ReaderAccessCommand extends Command
{
    public function __construct(private readonly DemoReaderAccess $access)
    {
        parent::__construct('demo:reader-access');
        $this->setDescription('Grant, revoke or inspect the local demo reader permission. Does not reset catalog state.');
        $this->addArgument('action', InputArgument::REQUIRED, 'grant, revoke or status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        if (!\in_array($action, ['grant', 'revoke', 'status'], true)) {
            throw new \InvalidArgumentException('Choose grant, revoke or status.');
        }
        if ('status' !== $action) {
            $this->access->setGranted('grant' === $action);
        }
        $output->writeln('demo-reader: publication.read_published '.($this->access->isGranted() ? 'granted' : 'revoked'));

        return self::SUCCESS;
    }
}
