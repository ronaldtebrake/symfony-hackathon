<?php

declare(strict_types=1);

use Adoption\{DemoReaderAccess, Kernel};
use CodeMode\Symfony\Http\CatalogStateStore;
use Symfony\AI\AiBundle\Test\AiAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Lock\{LockFactory, Store\InMemoryStore};

final class NativeIntegrationTest extends WebTestCase
{
    use AiAssertionsTrait;

    protected static function createKernel(array $options = []): KernelInterface { return new Kernel('test', true); }

    public function testSharedVoterNativeEventsBudgetsAndProfilerAcrossRevocation(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'native-access-');
        $access = new DemoReaderAccess($path);
        $saved = [];
        foreach (['APP_SECRET' => 'test-only', 'ADOPTION_MCP_TOKEN' => str_repeat('r', 32), 'ADOPTION_VIEWER_TOKEN' => str_repeat('v', 32), 'ADOPTION_READER_ACCESS_FILE' => $path] as $key => $value) {
            $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
        try {
            $access->setGranted(true);
            $client = self::createClient();
            $client->disableReboot();
            // Isolate this test from the recording app's persistent budgets.
            self::getContainer()->set(CatalogStateStore::class, new CatalogStateStore(new ArrayAdapter(), new LockFactory(new InMemoryStore())));
            $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('r', 32), 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'CONTENT_TYPE' => 'application/json'];
            $call = static function (string $name, array $arguments, ?array $override = null) use ($client, &$headers): array {
                $client->enableProfiler();
                $client->request('POST', '/mcp', server: $override ?? $headers, content: json_encode([
                    'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments],
                ], JSON_THROW_ON_ERROR));
                self::assertResponseIsSuccessful();
                $body = $client->getResponse()->getContent();
                if (preg_match('/^data: (.+)$/m', $body, $match)) { $body = $match[1]; }
                $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                self::assertArrayHasKey('result', $decoded);
                return $decoded['result']['structuredContent'];
            };
            $client->request('GET', '/articles/101', server: $headers);
            self::assertResponseIsSuccessful();
            $client->request('GET', '/articles/202', server: $headers);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/mcp', server: $headers, content: json_encode([
                'jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => [
                    'protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(),
                    'clientInfo' => ['name' => 'Integration test', 'version' => '1'],
                ],
            ], JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            $headers['HTTP_MCP_SESSION_ID'] = $client->getResponse()->headers->get('mcp-session-id');
            $search = $call('search_tools', ['query' => 'publication']);
            self::assertSame(['publication_read'], array_column($search['tools'], 'name'));
            $operation = $client->getProfile()->getCollector('code_mode')->getOperations()[0];
            self::assertSame(['matched' => 1, 'returned' => 1, 'truncated' => false], $operation['discovery']);
            $read = ['calls' => [['name' => 'publication_read', 'arguments' => ['id' => 101]]]];
            $allowed = $call('execute_tools', $read);
            self::assertSame(['id' => 101, 'title' => 'A Symfony application owns its tools'], $allowed['results'][0]['value']);
            self::assertArrayNotHasKey('toolCalls', $allowed);
            self::assertToolCalled('publication_read');
            self::assertToolCallCount(1);
            self::assertPlatformCallCount(0);
            $collector = $client->getProfile()->getCollector('code_mode');
            self::assertCount(1, $collector->getOperations(), 'Diagnostics must reset between HTTP requests.');
            $operation = $collector->getOperations()[0];
            self::assertSame(['requested', 'succeeded'], array_column($operation['nativeEvents'], 'phase'));
            self::assertSame('succeeded', $operation['calls'][0]['status']);
            self::assertStringNotContainsString('A Symfony application owns its tools', serialize($collector));
            $profileToken = $client->getProfile()->getToken();

            $access->setGranted(false);
            $denied = $call('execute_tools', $read);
            self::assertSame('tool_denied', $denied['error']['reason']);
            self::assertSame(0, $denied['completedCalls']);
            self::assertToolCallCount(0);
            $operation = $client->getProfile()->getCollector('code_mode')->getOperations()[0];
            self::assertSame([], $operation['nativeEvents'], 'Revocation must stop before the native toolbox.');
            self::assertSame('denied', $operation['calls'][0]['status']);
            self::assertSame(2, $operation['budget'][1]['attempts']);
            self::assertSame(2, $operation['budget'][2]['reservedCalls']);
            $client->request('GET', '/articles/101', server: $headers);
            self::assertResponseStatusCodeSame(403);
            self::assertSame([], $call('search_tools', ['query' => 'publication'])['tools']);
            // The native Symfony AI attribute also guards direct host-side toolbox usage.
            // This toolbox is not exposed as a separate MCP tool.
            try {
                self::getContainer()->get('code_mode.native_toolbox')->execute(new \Symfony\AI\Platform\Result\ToolCall('native-policy-check', 'publication_read', ['id' => 101]));
                self::fail('The native IsGrantedTool listener did not enforce revocation.');
            } catch (\Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException $error) {
                self::assertInstanceOf(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class, $error->getPrevious());
            }
            $access->setGranted(true);
            self::assertSame('execution_limit', $call('execute_tools', $read)['error']['reason']);
            $viewerHeaders = array_replace($headers, ['HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('v', 32)]);
            self::assertSame([], $call('search_tools', ['query' => 'publication'], $viewerHeaders)['tools']);
            self::assertToolCallCount(0);
            self::assertSame([], $client->getProfile()->getCollector('code_mode')->getOperations()[0]['calls']);
            $client->request('GET', '/_profiler/'.$profileToken.'?panel=code_mode');
            self::assertResponseIsSuccessful();
            self::assertSame(1, $client->getCrawler()->filterXPath('//h2[contains(., "Approved JSON catalog")]')->count());
        } finally {
            self::ensureKernelShutdown();
            unlink($path);
            foreach ($saved as $key => [$server, $env]) {
                if (null === $server) { unset($_SERVER[$key]); } else { $_SERVER[$key] = $server; }
                if (null === $env) { unset($_ENV[$key]); } else { $_ENV[$key] = $env; }
            }
        }
    }
}
