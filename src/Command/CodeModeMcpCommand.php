<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\DemoFactory;
use CodeMode\ExecutionTraceFile;
use CodeMode\Symfony\McpServerFactory;
use Mcp\Server\Transport\StdioTransport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'code-mode:mcp', description: 'Expose tools_search, tools_describe and php_execute to an MCP client over STDIO.')]
final class CodeModeMcpCommand extends Command
{
    public function __construct(private readonly DemoFactory $factory) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('backend', null, InputOption::VALUE_REQUIRED, 'fixture or drupal', 'fixture');
        $this->addOption('trace-file', null, InputOption::VALUE_REQUIRED, 'Optional new host-only execution evidence file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $session = $this->factory->create(backend: $input->getOption('backend'))->session;
        $record = null === $input->getOption('trace-file') ? null : new ExecutionTraceFile($input->getOption('trace-file'));
        // Preserve this endpoint's structured-only success results; do not add a duplicate text body.
        (new McpServerFactory())->create($session, null === $record ? null : $record(...), includeTextContent: false)->run(new StdioTransport());
        return Command::SUCCESS;
    }
}
