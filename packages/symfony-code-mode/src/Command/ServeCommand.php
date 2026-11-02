<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Command;

use CodeMode\Symfony\IdentityProviderInterface;
use CodeMode\Symfony\McpServerFactory;
use CodeMode\Symfony\SessionFactory;
use Mcp\Server\Transport\StdioTransport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ServeCommand extends Command
{
    public function __construct(private readonly SessionFactory $sessions, private readonly IdentityProviderInterface $identities)
    {
        parent::__construct('code-mode:serve');
        $this->setDescription('Expose approved Symfony read services through Code Mode over STDIO.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new McpServerFactory())->create($this->sessions->create($this->identities->identity()))->run(new StdioTransport());
        return self::SUCCESS;
    }
}
