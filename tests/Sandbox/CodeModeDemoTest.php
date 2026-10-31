<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Demo\DemoFactory;
use App\Demo\DemoRunner;
use App\Demo\AuditDataset;
use App\Demo\BackendFactory;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class CodeModeDemoTest extends KernelTestCase
{
    public function testSymfonyAiPipelineRunsModelSuppliedPhpThroughRealMcpAndGvisor(): void
    {
        self::bootKernel();
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/demo/audit.php.txt');
        $plan = [
            ['tools_search', ['query' => 'article metadata media']],
            ['tools_describe', ['names' => BackendFactory::FIXTURE_TOOLS]],
            ['php_execute', ['code' => $source]],
        ];
        $http = new MockHttpClient(static function () use (&$plan): JsonMockResponse {
            $step = array_shift($plan);
            $message = null === $step
                ? ['role' => 'assistant', 'content' => json_encode((new AuditDataset())->expected())]
                : ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_'.$step[0], 'type' => 'function', 'function' => ['name' => $step[0], 'arguments' => json_encode($step[1])]]]];
            return new JsonMockResponse(['choices' => [['index' => 0, 'message' => $message, 'finish_reason' => null === $step ? 'stop' : 'tool_calls']]]);
        });
        self::getContainer()->set('http_client', $http);
        $result = self::getContainer()->get(DemoRunner::class)->run('live', 'code');
        self::assertTrue($result['verification']['passed']);
        self::assertSame([$source], $result['programs']);
        self::assertCount(10, $result['brokerCalls']);
        self::assertSame(4, $http->getRequestsCount());
        self::assertNull($result['providerTokens'], 'Missing provider usage must never be replaced by an estimate.');
    }

    public function testReferenceComparisonUsesRealMcpAndGvisorWithEqualBatchedWork(): void
    {
        self::bootKernel();
        $runner = self::getContainer()->get(DemoRunner::class);
        $direct = $runner->run('reference', 'direct');
        $code = $runner->run('reference', 'code');
        self::assertTrue($direct['verification']['passed']);
        self::assertTrue($code['verification']['passed']);
        self::assertEquals($direct['report'], json_decode(json_encode($code['report']), true));
        self::assertCount(10, $direct['brokerCalls']);
        self::assertSame(array_column($direct['brokerCalls'], 'name'), array_column($code['brokerCalls'], 'name'));
        self::assertGreaterThan(0, $code['toolPayloadBytes']);
        self::assertLessThan($direct['toolPayloadBytes'], $code['toolPayloadBytes']);
        self::assertSame(0, $code['modelRequests']);
        self::assertNull($code['providerTokens']);
    }

    public function testExternalMcpClientCanDiscoverAndExecutePhpCodeMode(): void
    {
        $client = Client::builder()->setClientInfo('code-mode-test', '1')->setInitTimeout(10)->setRequestTimeout(20)->setMaxRetries(0)->build();
        try {
            $client->connect(new StdioTransport(PHP_BINARY, ['bin/console', 'code-mode:mcp', '--no-debug'], dirname(__DIR__, 2), maxBufferSize: 65536));
            self::assertSame(['tools_search', 'tools_describe', 'php_execute'], array_map(static fn ($tool) => $tool->name, $client->listTools()->tools));
            $search = $client->callTool('tools_search', ['query' => 'published articles']);
            self::assertFalse($search->isError);
            $description = $client->callTool('tools_describe', ['names' => ['cms_list_articles']]);
            self::assertFalse($description->isError);
            $run = $client->callTool('php_execute', ['code' => 'return $tools->call("cms_list_articles", ["offset" => 0, "limit" => 1])->total;']);
            self::assertFalse($run->isError);
            self::assertSame(48, $run->structuredContent['value']);
            self::assertSame('cms_list_articles', $run->structuredContent['toolCalls'][0]['name']);
        } finally {
            $client->disconnect();
        }
    }
}
