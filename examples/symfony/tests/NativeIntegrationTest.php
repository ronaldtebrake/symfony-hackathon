<?php

declare(strict_types=1);

use Adoption\DemoReaderAccess;
use Adoption\Kernel;
use CodeMode\Symfony\Http\CatalogStateStore;
use Symfony\AI\AiBundle\Test\AiAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class NativeIntegrationTest extends WebTestCase
{
    use AiAssertionsTrait;

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
            $viewerHeaders = array_replace($headers, ['HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('v', 32)]);
            $hint = static function (array $headers) use ($client): array {
                $client->request('GET', '/articles/capability-hint', server: $headers);
                self::assertResponseIsSuccessful();
                self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
                self::assertContains('Authorization', $client->getResponse()->getVary());

                return json_decode($client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['hints'];
            };
            $client->request('GET', '/articles/capability-hint', server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(401);
            $this->assertSame(['You can look up published articles.'], $hint($headers));
            $this->assertSame([], $hint($viewerHeaders));
            $call = static function (string $name, array $arguments, ?array $override = null) use ($client, &$headers): array {
                $client->enableProfiler();
                $client->request('POST', '/mcp', server: $override ?? $headers, content: json_encode([
                    'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments],
                ], \JSON_THROW_ON_ERROR));
                self::assertResponseIsSuccessful();
                $body = $client->getResponse()->getContent();
                if (preg_match('/^data: (.+)$/m', $body, $match)) {
                    $body = $match[1];
                }
                $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
                self::assertArrayHasKey('result', $decoded);

                return $decoded['result']['structuredContent'];
            };
            $client->request('GET', '/articles/101', server: $headers);
            self::assertResponseIsSuccessful();
            $client->request('GET', '/articles/202', server: $headers);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/mcp', server: $headers, content: json_encode([
                'jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => [
                    'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(),
                    'clientInfo' => ['name' => 'Integration test', 'version' => '1'],
                ],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            $headers['HTTP_MCP_SESSION_ID'] = $client->getResponse()->headers->get('mcp-session-id');
            $read = ['calls' => [['name' => 'publication_read', 'arguments' => ['id' => 101]]]];
            $this->assertSame('discovery_required', $call('execute_tools', $read)['error']['reason']);
            $search = $call('search_tools', ['query' => 'publication']);
            $this->assertSame(['publication_read'], array_column($search['tools'], 'name'));
            $operation = $this->operations($client)[0];
            $this->assertSame(['matched' => 1, 'returned' => 1, 'truncated' => false], array_diff_key($operation['discovery'], ['toolDefinitions' => true]));
            $this->assertJsonStringEqualsJsonString(json_encode($search['tools'], \JSON_THROW_ON_ERROR), json_encode($operation['discovery']['toolDefinitions'], \JSON_THROW_ON_ERROR));
            $searchCollector = $client->getProfile()->getCollector('code_mode');
            $allowed = $call('execute_tools', $read);
            $this->assertSame(['id' => 101, 'title' => 'A Symfony application owns its tools'], $allowed['results'][0]['value']);
            $this->assertArrayNotHasKey('toolCalls', $allowed);
            self::assertToolCalled('publication_read');
            self::assertToolCallCount(1);
            self::assertPlatformCallCount(0);
            $collector = $client->getProfile()->getCollector('code_mode');
            $this->assertInstanceOf(CodeMode\Symfony\Profiler\CatalogDataCollector::class, $collector);
            $this->assertCount(1, $collector->getOperations(), 'Diagnostics must reset between HTTP requests.');
            $operation = $collector->getOperations()[0];
            $this->assertSame(['requested', 'succeeded'], array_column($operation['nativeEvents'], 'phase'));
            $this->assertSame('succeeded', $operation['calls'][0]['status']);
            $this->assertStringNotContainsString('A Symfony application owns its tools', serialize($collector));
            $fullProfile = $client->getProfile();
            $profileToken = $fullProfile->getToken();

            $access->setGranted(false);
            $this->assertSame([], $hint($headers));
            $denied = $call('execute_tools', $read);
            $this->assertSame('tool_denied', $denied['error']['reason']);
            $this->assertSame(0, $denied['completedCalls']);
            self::assertToolCallCount(0);
            $operation = $this->operations($client)[0];
            $this->assertSame([], $operation['nativeEvents'], 'Revocation must stop before the native toolbox.');
            $this->assertSame('denied', $operation['calls'][0]['status']);
            $deniedCollector = $client->getProfile()->getCollector('code_mode');
            $this->assertSame(2, $operation['budget'][1]['attempts']);
            $this->assertSame(2, $operation['budget'][2]['reservedCalls']);
            $client->request('GET', '/articles/101', server: $headers);
            self::assertResponseStatusCodeSame(403);
            $this->assertSame([], $call('search_tools', ['query' => 'publication'])['tools']);
            $this->assertSame([], $this->operations($client)[0]['discovery']['toolDefinitions']);
            // The native Symfony AI attribute also guards direct host-side toolbox usage.
            // This toolbox is not exposed as a separate MCP tool.
            try {
                self::getContainer()->get('code_mode.native_toolbox')->execute(new Symfony\AI\Platform\Result\ToolCall('native-policy-check', 'publication_read', ['id' => 101]));
                $this->fail('The native IsGrantedTool listener did not enforce revocation.');
            } catch (Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException $error) {
                $this->assertInstanceOf(Symfony\Component\Security\Core\Exception\AccessDeniedException::class, $error->getPrevious());
            }
            $access->setGranted(true);
            $this->assertSame(['You can look up published articles.'], $hint($headers));
            $this->assertSame('execution_limit', $call('execute_tools', $read)['error']['reason']);
            $viewerHeaders = array_replace($headers, ['HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('v', 32)]);
            $this->assertSame([], $call('search_tools', ['query' => 'publication'], $viewerHeaders)['tools']);
            self::assertToolCallCount(0);
            $this->assertSame([], $this->operations($client)[0]['calls']);
            $client->request('GET', '/_profiler/'.$profileToken.'?panel=mcp');
            self::assertResponseIsSuccessful();
            $panel = $client->getCrawler()->filterXPath('//div[@id="collector-content"]')->text();
            $this->assertStringContainsString('MCP Capabilities', $panel);
            $this->assertStringContainsString('MCP discovery and tool calls', $panel);
            $this->assertStringContainsString('publication_read', $panel);
            $client->request('GET', '/_profiler/'.$profileToken.'?panel=code_mode');
            self::assertResponseIsSuccessful();
            $this->assertSame(1, $client->getCrawler()->filterXPath('//h2[contains(., "MCP discovery and tool calls")]')->count());
            $client->request('GET', '/_profiler/'.$profileToken);
            self::assertResponseIsSuccessful();
            $this->assertCount(1, $client->getCrawler()->filterXPath('//ul[@id="menu-profiler"]/li[@class="request selected"]'));

            // Refund recordings store only the Code Mode collector, under a normal request token.
            $recording = clone $fullProfile;
            $recording->setToken(bin2hex(random_bytes(3)));
            $recording->setCollectors(['code_mode' => $collector]);
            $this->assertTrue(self::getContainer()->get('profiler')->saveProfile($recording));
            $recordingUrl = '/_profiler/'.$recording->getToken();
            foreach (['/_profiler/latest', $recordingUrl] as $url) {
                $client->request('GET', $url);
                self::assertResponseIsSuccessful();
                $crawler = $client->getCrawler();
                $this->assertCount(1, $crawler->filterXPath('//ul[@id="menu-profiler"]/li[@class="code_mode selected"]'));
                $this->assertStringContainsString('publication_read', $crawler->filterXPath('//div[@id="collector-content"]')->text());
                $this->assertCount(0, $crawler->filterXPath('//ul[@id="menu-profiler"]/li[contains(@class, "request")]'));
            }
            $client->request('GET', $recordingUrl.'?panel=request');
            self::assertResponseStatusCodeSame(404);

            // Mix private recordings with a full HTTP profile for the same endpoint.
            $endpoint = 'http://127.0.0.1:'.random_int(49152, 65535).'/mcp';
            $since = time();
            $expectedRows = [];
            foreach ([
                [['code_mode' => $searchCollector], 'search_tools', 'succeeded'],
                [$fullProfile->getCollectors(), null, null],
                [['code_mode' => $collector], 'execute_tools', 'succeeded'],
                [['code_mode' => $deniedCollector], 'execute_tools', 'failed'],
            ] as [$collectors, $tool, $status]) {
                $row = clone $fullProfile;
                $row->setToken(bin2hex(random_bytes(3)));
                $row->setTime($since);
                $row->setUrl($endpoint);
                $row->setCollectors($collectors);
                $this->assertTrue(self::getContainer()->get('profiler.storage')->write($row));
                $expectedRows[] = [$row->getToken(), $tool, $status];
            }
            $results = '/_profiler/empty/search/results';
            $query = ['url' => $endpoint, 'method' => 'POST', 'start' => $since, 'limit' => 20];
            $client->request('GET', $results, $query);
            self::assertResponseIsSuccessful();
            $crawler = $client->getCrawler();
            $this->assertCount(0, $crawler->filterXPath('//ol[@id="mcp-request-order"]'));
            $table = $crawler->filterXPath('//table[@id="search-results"]');
            $columns = $table->filterXPath('//th')->each(static fn ($header): string => $header->text());
            $this->assertContains('MCP call', $columns);
            $this->assertSame(['Status', 'IP', 'Method', 'URL', 'Time', 'Token'], array_values(array_diff($columns, ['MCP call'])));
            $rows = $table->filterXPath('//tbody/tr');
            $this->assertCount(count($expectedRows), $rows);
            foreach ($expectedRows as $index => [$token, $tool, $status]) {
                $row = $rows->eq($index);
                $this->assertSame($token, $row->filterXPath('//a[@href="/_profiler/'.$token.'"]')->text());
                $this->assertSame('200', $row->filterXPath('//td')->eq(array_search('Status', $columns, true))->text());
                $this->assertSame('POST', $row->filterXPath('//td')->eq(array_search('Method', $columns, true))->text());
                $this->assertStringContainsString($endpoint, $row->text());
                $operationCell = $row->filterXPath('//td[contains(concat(" ", normalize-space(@class), " "), " mcp-operation ")]');
                $this->assertCount(1, $operationCell);
                if (null === $tool) {
                    $this->assertSame('', $operationCell->text());
                    continue;
                }
                $link = $operationCell->filterXPath('//a[@href="/_profiler/'.$token.'?panel=code_mode"]');
                $this->assertCount(1, $link);
                $this->assertStringContainsString('tools/call', $operationCell->text());
                $this->assertStringContainsString($tool, $operationCell->text());
                $this->assertStringContainsString($status, $operationCell->text());
            }
            $this->assertStringNotContainsString('A Symfony application owns its tools', $table->text());
            $client->click($rows->first()->filterXPath('//td[contains(@class, "mcp-operation")]//a')->link());
            self::assertResponseIsSuccessful();
            $this->assertStringContainsString('publication_read', $client->getCrawler()->filterXPath('//div[@id="collector-content"]')->text());
            $client->request('GET', $results, array_replace($query, ['url' => '/mcp']));
            self::assertResponseIsSuccessful();
            $table = $client->getCrawler()->filterXPath('//table[@id="search-results"]');
            $this->assertCount(1, $table);
            $this->assertCount(0, $table->filterXPath('//th[normalize-space(.)="MCP call"] | //td[contains(@class, "mcp-operation")]'));
        } finally {
            self::ensureKernelShutdown();
            unlink($path);
            foreach ($saved as $key => [$server, $env]) {
                if (null === $server) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
                if (null === $env) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
            }
        }
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new Kernel('test', true);
    }

    private function operations(Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        $profile = $client->getProfile();
        $this->assertNotNull($profile);
        $collector = $profile->getCollector('code_mode');
        $this->assertInstanceOf(CodeMode\Symfony\Profiler\CatalogDataCollector::class, $collector);

        return $collector->getOperations();
    }
}
