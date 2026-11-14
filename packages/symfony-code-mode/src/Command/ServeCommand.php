<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Command;

use CodeMode\DiscoveryMode;
use CodeMode\ExecutionTraceFile;
use CodeMode\Symfony\BatchMcpServerFactory;
use CodeMode\Symfony\IdentityProviderInterface;
use CodeMode\Symfony\McpServerFactory;
use CodeMode\Symfony\SessionFactory;
use Mcp\Server\Transport\StdioTransport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ServeCommand extends Command
{
    public function __construct(private readonly SessionFactory $sessions, private readonly IdentityProviderInterface $identities, private readonly string $mode = 'php')
    {
        parent::__construct('code-mode:serve');
        $this->setDescription('Expose approved Symfony tools over STDIO using the configured PHP or JSON batch path.');
        $this->addOption('trace-file', null, InputOption::VALUE_REQUIRED, 'Optional new host-only JSONL file for execution evidence (never returned to the agent).');
        $this->addOption('discovery', null, InputOption::VALUE_REQUIRED, 'Discovery mode: full (the supported schema flow).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (\in_array($this->mode, ['batch', 'catalog'], true)) {
            if (null !== $input->getOption('trace-file') || null !== $input->getOption('discovery')) {
                throw new \InvalidArgumentException('PHP trace/discovery options do not apply to the batch server. Use a host batch observer for traces.');
            }
            (new BatchMcpServerFactory())->create($this->sessions->createBatch($this->identities->identity()), catalog: 'catalog' === $this->mode)->run(new StdioTransport());

            return self::SUCCESS;
        }
        $record = null === $input->getOption('trace-file') ? null : new ExecutionTraceFile($input->getOption('trace-file'));
        $discovery = null === $input->getOption('discovery') ? null : DiscoveryMode::from($input->getOption('discovery'));
        (new McpServerFactory())->create($this->sessions->create($this->identities->identity(), $discovery), null === $record ? null : $record(...))->run(new StdioTransport());

        return self::SUCCESS;
    }
}
