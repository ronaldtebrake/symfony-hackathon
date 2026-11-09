<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\CodeMode\{PublishingAuthorization, PublishingTools};
use CodeMode\{BatchSession, ExecutionIdentity, ExecutionProfile, DockerEngine};
use CodeMode\Provider\ProviderRegistry;
use CodeMode\Symfony\{CodeModeBundle, SessionFactory};
use CodeMode\Symfony\Ai\{ApprovedToolbox, BatchToolbox};
use CodeMode\Symfony\Provider\SymfonyAiProvider;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Process\Process;

final class BatchRuntimeTest extends TestCase
{
    public function testTwoToolCatalogWorksThroughRealMcpWithTheSameDenialAndRecovery(): void
    {
        $doctor = new Process([PHP_BINARY, 'tests/fixtures/batch-runtime.php', 'code-mode:doctor', '--json'], dirname(__DIR__, 2), ['BATCH_TEST_MODE' => 'catalog']);
        $doctor->mustRun(); self::assertTrue(json_decode($doctor->getOutput(), true)['ready']);
        $client = Client::builder()->setClientInfo('catalog-test', '1')->setInitTimeout(10)->setRequestTimeout(10)->setMaxRetries(0)->build();
        try {
            $client->connect(new StdioTransport(PHP_BINARY, ['tests/fixtures/batch-runtime.php', 'code-mode:serve'], dirname(__DIR__, 2), ['BATCH_TEST_MODE' => 'catalog'], maxBufferSize: 65536));
            $before = json_encode($client->listTools()->tools);
            self::assertSame(['search_tools', 'execute_tools'], array_column($client->listTools()->tools, 'name'));
            $read = static fn ($id) => ['calls' => [['name' => 'articles_read', 'arguments' => ['id' => $id]]]];
            $found = $client->callTool('search_tools', ['query' => 'articles_read'])->structuredContent;
            self::assertSame(['id'], $found['tools'][0]['inputSchema']['required']);
            self::assertSame('tool_denied', $client->callTool('execute_tools', $read(49))->structuredContent['error']['reason']);
            self::assertSame(1, $client->callTool('execute_tools', $read(1))->structuredContent['results'][0]['value']['id']);
            self::assertSame('execution_limit', $client->callTool('execute_tools', $read(1))->structuredContent['error']['reason']);
            self::assertSame($before, json_encode($client->listTools()->tools));
        } finally { $client->disconnect(); }
    }

    public function testCompiledBatchDoctorDoesNotNeedAnyRunnerConfiguration(): void
    {
        $process = new Process([PHP_BINARY, 'tests/fixtures/batch-runtime.php', 'code-mode:doctor', '--json'], dirname(__DIR__, 2));
        $process->mustRun();
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($report['ready']);
        self::assertSame('json-batch', $report['runtime']['executor']);
        self::assertFalse($report['runtime']['generatedCode']);
        self::assertCount(3, $report['checks']);
        self::assertNull($report['limits']['memoryMiB']);
    }

    public function testRealMcpBatchConversationDeniesThenRecoversWithoutAnExecutor(): void
    {
        $client = Client::builder()->setClientInfo('batch-test', '1')->setInitTimeout(10)->setRequestTimeout(10)->setMaxRetries(0)->build();
        try {
            $client->connect(new StdioTransport(PHP_BINARY, ['tests/fixtures/batch-runtime.php', 'code-mode:serve'], dirname(__DIR__, 2), maxBufferSize: 65536));
            self::assertSame(['tools_search', 'tools_describe', 'tools_execute'], array_column($client->listTools()->tools, 'name'));
            $call = static fn ($id) => ['calls' => [['name' => 'articles_read', 'arguments' => ['id' => $id]]]];
            $first = $client->callTool('tools_execute', $call(1));
            self::assertSame('discovery_required', $first->structuredContent['error']['reason']);
            $client->callTool('tools_describe', ['names' => ['articles_read']]);
            try {
                $client->callTool('tools_execute', $call(1) + ['code' => 'return 1;', 'identity' => 'admin']);
                self::fail('The agent supplied host configuration or PHP.');
            } catch (\Mcp\Exception\RequestException) {}
            $denied = $client->callTool('tools_execute', $call(49));
            self::assertTrue($denied->isError);
            self::assertSame('tool_denied', $denied->structuredContent['error']['reason']);
            self::assertSame(0, $denied->structuredContent['completedCalls']);
            $allowed = $client->callTool('tools_execute', $call(1));
            self::assertFalse($allowed->isError);
            self::assertSame(1, $allowed->structuredContent['results'][0]['value']['id']);
            self::assertSame(['results'], array_keys($allowed->structuredContent));
            self::assertSame('execution_limit', $client->callTool('tools_execute', $call(1))->structuredContent['error']['reason']);
        } finally { $client->disconnect(); }
    }

    public function testNativeSymfonyAiProviderUsesTheSameContractsAndPolicyThroughBatches(): void
    {
        $approved = new ApprovedToolbox(new Toolbox([new PublishingTools()]), ['publishing_read'], new PublishingAuthorization());
        $provider = new SymfonyAiProvider('articles', $approved, ['read' => 'publishing_read']);
        $factory = new SessionFactory([], docker: new DockerEngine(['/does-not-exist']), providers: [$provider]);
        $session = $factory->createBatch(new ExecutionIdentity('reader', ['articles.read_published']));
        $ai = new BatchToolbox($session);
        $invoke = static fn ($name, $args) => json_decode($ai->execute(new ToolCall('id', $name, $args))->getResult(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['tools_search', 'tools_describe', 'tools_execute'], array_map(static fn ($tool) => $tool->getName(), $ai->getTools()));
        $description = $invoke('tools_describe', ['names' => ['articles.read']]);
        self::assertSame(['id'], $description[0]['inputSchema']['required']);
        self::assertSame('invalid_arguments', $invoke('tools_execute', ['calls' => [], 'executor' => 'local-unsafe'])['error']['reason']);
        self::assertSame('tool_denied', $invoke('tools_execute', ['calls' => [['name' => 'articles.read', 'arguments' => ['id' => 49]]]])['error']['reason']);
        self::assertSame('denied', $ai->lastExecution()['trace']['toolCalls'][0]['status']);
        $result = $invoke('tools_execute', ['calls' => [['name' => 'articles.read', 'arguments' => ['id' => 1]]]]);
        self::assertSame(1, $result['results'][0]['value']['id']);
        self::assertSame('json-batch', $ai->lastExecution()['trace']['runtime']['executor']);
        $invoke('tools_search', ['query' => 'read']);
        self::assertNull($ai->lastExecution());
    }

    public function testUnsafePhpCannotBeConfiguredAndDefaultRemainsGvisor(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', dirname(__DIR__, 2).'/var/cache/test');
        $extension = (new CodeModeBundle())->getContainerExtension();
        $config = ['identity_provider' => 'identity', 'tools' => ['read']];
        $extension->load([$config], $container);
        self::assertSame(ExecutionProfile::Gvisor, $container->getDefinition(SessionFactory::class)->getArgument(5));
        self::assertFalse(class_exists('CodeMode\\LocalUnsafeExecutor'));
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $extension->load([$config + ['executor' => 'local-unsafe']], $container);
    }
}
