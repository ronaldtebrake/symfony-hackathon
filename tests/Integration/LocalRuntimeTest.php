<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\CodeMode\ReadArticle;
use CodeMode\{DockerEngine, ExecutionIdentity, ExecutionLimits, ExecutionProfile};
use CodeMode\Symfony\{CodeModeBundle, SessionFactory};
use CodeMode\Symfony\Ai\CodeModeToolbox;
use CodeMode\Symfony\Command\DoctorCommand;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Process\Process;

final class LocalRuntimeTest extends TestCase
{
    public function testCompiledBundleRunsDoctorWithoutDockerOrImageConfiguration(): void
    {
        $process = new Process([PHP_BINARY, 'tests/fixtures/local-runtime.php', 'code-mode:doctor', '--json'], dirname(__DIR__, 2));
        $process->mustRun();
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($report['ready']);
        self::assertSame('local-unsafe', $report['runtime']['executor']);
        self::assertSame('none', $report['runtime']['isolation']);
        self::assertNull($report['limits']['memoryMiB']);
        self::assertSame(32, $report['limits']['phpMemoryMiB']);
        self::assertCount(4, $report['checks']);
    }

    public function testHumanDoctorOutputMakesUnsandboxedStatusUnmistakable(): void
    {
        $docker = new DockerEngine(['/nonexistent/docker-must-not-run']);
        $sessions = new SessionFactory([new ReadArticle()], docker: $docker, executor: ExecutionProfile::LocalUnsafe);
        $tester = new CommandTester(new DoctorCommand('', $docker, $sessions, new ExecutionLimits()));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Unsandboxed local execution', $tester->getDisplay());
        self::assertStringContainsString('no OS memory limit', $tester->getDisplay());
        self::assertStringNotContainsString('/nonexistent', $tester->getDisplay());
    }

    public function testMcpConversationDeniesPrivateReadThenRecoversWithUnsafeLabels(): void
    {
        $client = Client::builder()->setClientInfo('local-runtime-test', '1')->setInitTimeout(10)->setRequestTimeout(10)->setMaxRetries(0)->build();
        try {
            $client->connect(new StdioTransport(PHP_BINARY, ['tests/fixtures/local-runtime.php', 'code-mode:serve'], dirname(__DIR__, 2), maxBufferSize: 65536));
            $tools = $client->listTools()->tools;
            self::assertSame(['tools_search', 'tools_describe', 'php_execute'], array_column($tools, 'name'));
            $execute = $tools[2];
            self::assertStringContainsString('UNSAFE LOCAL DEVELOPMENT', $execute->description);
            self::assertStringContainsString('PHP '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, $execute->description);
            self::assertFalse($execute->annotations->readOnlyHint);
            self::assertTrue($execute->annotations->destructiveHint);
            self::assertSame(['code'], array_keys($execute->inputSchema['properties']));

            $client->callTool('tools_describe', ['names' => ['articles_read']]);
            // Runtime selection and limits are not accepted as agent arguments.
            try {
                $client->callTool('php_execute', ['code' => 'return "must not execute";', 'executor' => 'gvisor', 'wallTimeMs' => 30000]);
                self::fail('The agent supplied host configuration.');
            } catch (\Mcp\Exception\RequestException $error) {
                self::assertStringContainsString('unexpected additional properties', $error->getMessage());
            }
            $denied = $client->callTool('php_execute', ['code' => 'return $tools->call("articles_read", ["id" => 49]);']);
            self::assertTrue($denied->isError);
            self::assertSame('tool_denied', $denied->structuredContent['error']['reason']);
            self::assertSame('denied', $denied->structuredContent['toolCalls'][0]['status']);
            self::assertSame('none', $denied->structuredContent['runtime']['isolation']);
            self::assertArrayNotHasKey('value', $denied->structuredContent);
            $allowed = $client->callTool('php_execute', ['code' => 'return $tools->call("articles_read", ["id" => 1]);']);
            self::assertFalse($allowed->isError);
            self::assertSame(1, $allowed->structuredContent['value']['id']);
            self::assertSame('succeeded', $allowed->structuredContent['toolCalls'][0]['status']);
            self::assertSame('local-unsafe', $allowed->structuredContent['runtime']['executor']);
            self::assertStringStartsWith('code-mode-local-', $allowed->structuredContent['runId']);
            $exhausted = $client->callTool('php_execute', ['code' => 'return 1;']);
            self::assertSame('execution_limit', $exhausted->structuredContent['error']['reason']);
            self::assertSame('none', $exhausted->structuredContent['runtime']['isolation']);
        } finally {
            $client->disconnect();
        }
    }

    public function testSymfonyAiUsesTheSameProfileAndCannotAcceptRuntimeArguments(): void
    {
        $session = (new SessionFactory([new ReadArticle()], executor: ExecutionProfile::LocalUnsafe))->create(new ExecutionIdentity('reader', ['articles.read_published']));
        $toolbox = new CodeModeToolbox($session);
        $tools = $toolbox->getTools();
        self::assertStringContainsString('UNSAFE LOCAL DEVELOPMENT', $tools[2]->getDescription());
        $call = static fn (string $name, array $args): array => json_decode($toolbox->execute(new ToolCall('test', $name, $args))->getResult(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('invalid_arguments', $call('php_execute', ['code' => 'return true;', 'executor' => 'gvisor'])['error']['reason']);
        $call('tools_describe', ['names' => ['articles_read']]);
        $result = $call('php_execute', ['code' => 'return $tools->call("articles_read", ["id" => 1]);']);
        self::assertSame('none', $result['runtime']['isolation']);
        self::assertSame(1, $result['value']['id']);
    }

    public function testBundleDefaultsToGvisorAndRejectsAnUnknownExecutor(): void
    {
        $bundle = new CodeModeBundle();
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', dirname(__DIR__, 2).'/var/cache/local-runtime-test');
        $config = ['identity_provider' => 'test.identity', 'tools' => ['test.read']];
        $bundle->getContainerExtension()->load([$config], $container);
        self::assertSame(ExecutionProfile::Gvisor, $container->getDefinition(SessionFactory::class)->getArgument(5));
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $bundle->getContainerExtension()->load([$config + ['executor' => 'automatic']], $container);
    }
}
