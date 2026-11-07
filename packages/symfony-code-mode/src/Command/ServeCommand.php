<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Command;

use CodeMode\ExecutionTraceFile;
use CodeMode\DiscoveryMode;
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
    public function __construct(private readonly SessionFactory $sessions, private readonly IdentityProviderInterface $identities)
    {
        parent::__construct('code-mode:serve');
        $this->setDescription('Expose approved Symfony read services through Code Mode over STDIO.');
        $this->addOption('trace-file', null, InputOption::VALUE_REQUIRED, 'Optional new host-only JSONL file for execution evidence (never returned to the agent).');
        $this->addOption('discovery', null, InputOption::VALUE_REQUIRED, 'Discovery mode: full (the supported schema flow).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $record = null === $input->getOption('trace-file') ? null : new ExecutionTraceFile($input->getOption('trace-file'));
        $discovery = null === $input->getOption('discovery') ? null : DiscoveryMode::from($input->getOption('discovery'));
        (new McpServerFactory())->create($this->sessions->create($this->identities->identity(), $discovery), null === $record ? null : $record(...))->run(new StdioTransport());
        return self::SUCCESS;
    }
}
