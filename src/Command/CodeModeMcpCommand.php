<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\DemoFactory;
use CodeMode\CodeModeSession;
use CodeMode\SandboxException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $session = $this->factory->create(backend: $input->getOption('backend'))->session;
        $wrap = static function (\Closure $call): CallToolResult {
            try {
                return new CallToolResult([], structuredContent: $call());
            } catch (\Throwable $error) {
                $reason = $error instanceof SandboxException ? $error->reason : 'tool_failed';
                return CallToolResult::error([new TextContent('Code Mode rejected the request: '.$reason)]);
            }
        };
        $handlers = [
            'tools_search' => static fn (string $query): CallToolResult => $wrap(static fn (): array => $session->search($query)),
            'tools_describe' => static fn (array $names): CallToolResult => $wrap(static fn (): array => $session->describe($names)),
            'php_execute' => static fn (string $code): CallToolResult => $wrap(static function () use ($session, $code): array {
                $result = $session->execute($code);
                return ['value' => $result->value, 'toolCalls' => $result->toolCalls, 'durationMs' => $result->durationMs];
            }),
        ];
        $builder = Server::builder()->setServerInfo('Symfony Code Mode', '0.2.0');
        foreach (CodeModeSession::definitions() as $definition) {
            $builder->addTool($handlers[$definition->name], name: $definition->name, description: $definition->description, inputSchema: get_object_vars($definition->inputSchema), annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false));
        }
        $builder->build()->run(new StdioTransport());
        return Command::SUCCESS;
    }
}
