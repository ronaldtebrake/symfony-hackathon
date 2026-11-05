<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\BackendFactory;
use App\Demo\Performance\Capture;
use App\Demo\Performance\ReadPolicy;
use App\Demo\Performance\ReadTools;
use CodeMode\DockerEngine;
use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\Ai\ApprovedToolbox;
use CodeMode\Symfony\McpServerFactory;
use CodeMode\Symfony\SessionFactory;
use Mcp\Server\Transport\StdioTransport;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'code-mode:performance-mcp', description: 'Expose approved synthetic Symfony profiler/log readers through Code Mode over STDIO.')]
final class PerformanceMcpCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $data = (new Capture())->run();
        $tools = new ApprovedToolbox(new Toolbox([new ReadTools($data)]), ReadTools::NAMES, new ReadPolicy($data));
        $sessions = new SessionFactory([], BackendFactory::env('CODE_MODE_IMAGE'),
            new DockerEngine(json_decode(BackendFactory::env('CODE_MODE_DOCKER_COMMAND', '["docker"]'), true, flags: JSON_THROW_ON_ERROR)), aiToolbox: $tools);
        (new McpServerFactory())->create($sessions->create(new ExecutionIdentity('local-performance-reader', ['performance.read_public'])))->run(new StdioTransport());
        return self::SUCCESS;
    }
}
