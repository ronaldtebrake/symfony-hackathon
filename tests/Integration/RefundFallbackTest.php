<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\Groups\PublishingGroups;
use App\Demo\GroupSelection\Discovery;
use App\Demo\Mcp\CaptureRequestFactory;
use App\Demo\Refund\Fixture;
use App\Demo\Refund\Gateway;
use App\Demo\Refund\Kernel;
use App\Demo\Refund\RecordingProfiler;
use App\Demo\Refund\Tools;
use CodeMode\Symfony\Profiler\CatalogTrace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;

final class RefundFallbackTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('modes')]
    public function testFlattenedAndNegotiatedClientsShareTheSameSymfonyAuthorizationAndBudget(string $mode, array $entryPoints): void
    {
        $fs = new Filesystem();
        $directory = sys_get_temp_dir().'/refund-fallback-'.bin2hex(random_bytes(8));
        $fs->mkdir($directory.'/sessions', 0700);
        $reader = bin2hex(random_bytes(32));
        $viewer = bin2hex(random_bytes(32));
        $fs->dumpFile($directory.'/reader.token', $reader);
        $fs->dumpFile($directory.'/viewer.token', $viewer);
        $fs->dumpFile($directory.'/permission', "granted\n");
        $kernel = new Kernel($directory, $mode);
        $id = 0;
        $sessions = [];
        $send = static function (string $method, array $params, string $user, ?array $extensions = null) use ($directory, $mode, $kernel, &$sessions, &$id): array {
            $headers = ['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer '.$user, 'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json, text/event-stream'];
            if (null === $extensions) {
                $message = ['jsonrpc' => '2.0', 'id' => ++$id, 'method' => $method, 'params' => (object) $params];
                $headers['HTTP_MCP_PROTOCOL_VERSION'] = '2025-06-18';
                if (isset($sessions[$user])) {
                    $headers['HTTP_MCP_SESSION_ID'] = $sessions[$user];
                }
            } else {
                $factory = new CaptureRequestFactory('refund-test', $extensions);
                $message = $factory->message(++$id, $method, $params);
                foreach ($factory->headers($message) as $name => $value) {
                    $headers['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
                }
            }
            $request = Request::create('/mcp', 'POST', server: $headers, content: json_encode($message, \JSON_THROW_ON_ERROR));
            $response = $kernel->handle($request);
            if ('bare' === $mode) {
                RecordingProfiler::capture($directory, $request, $response, $kernel->getContainer()->get(CatalogTrace::class), 0);
                self::assertFalse($response->headers->has('X-Debug-Token'));
            }
            if ($response->headers->has('Mcp-Session-Id')) {
                $sessions[$user] = $response->headers->get('Mcp-Session-Id');
            }
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            ob_start();
            $response->sendContent();
            $body = ob_get_clean();
            $kernel->terminate($request, $response);
            if (preg_match('/^data: (.+)$/m', $body, $match)) {
                $body = $match[1];
            }

            return json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        };
        try {
            $hello = ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'refund-test', 'version' => '1']];
            foreach ([$reader, $viewer] as $token) {
                $init = $send('initialize', $hello, $token)['result'];
                $this->assertSame('2025-06-18', $init['protocolVersion']);
                $this->assertSame('symfony_code_mode', $init['serverInfo']['name']);
                $this->assertSame('Symfony Code Mode', $init['serverInfo']['title']);
                $this->assertArrayHasKey('tools', $init['capabilities']);
                $this->assertArrayNotHasKey(PublishingGroups::ID, $init['capabilities']['extensions'] ?? []);
            }
            if ('catalog' === $mode) {
                $this->assertCatalogRecording($send, $reader, $viewer, $directory, $kernel);

                return;
            }
            $negotiated = [PublishingGroups::ID];
            $flat = $send('tools/list', [], $reader)['result']['tools'];
            $this->assertSame([...$entryPoints, ...Tools::NAMES], array_column($flat, 'name'));
            $this->assertSame($entryPoints, array_column($send('tools/list', [], $viewer)['result']['tools'], 'name'));
            $this->assertSame($entryPoints, array_column($send('tools/list', [], $reader, $negotiated)['result']['tools'], 'name'));
            $this->assertEquals(\array_slice($flat, \count($entryPoints)), $send('groups/get', ['name' => 'refund-review'], $reader, $negotiated)['result']['tools']);
            $this->assertSame(-32021, $send('groups/list', [], $reader, [])['error']['code']);
            $this->assertSame($flat, $send('tools/list', [], $reader, [])['result']['tools']);
            $order = ['name' => 'refund_get_order', 'arguments' => ['orderId' => 'R-1042']];
            $policy = ['name' => 'refund_get_policy', 'arguments' => ['policyId' => 'standard-eu']];
            $first = $send('tools/call', $order, $reader)['result']['structuredContent'];
            $this->assertSame($policy['arguments']['policyId'], $first['policyId']);
            $wrappedOrder = ['name' => 'execute_tools', 'arguments' => ['calls' => [$order]]];
            if ('selection' === $mode) {
                $this->assertSame('discovery_required', $send('tools/call', $wrappedOrder, $reader)['result']['structuredContent']['error']['reason']);
                $search = $send('tools/call', ['name' => 'search_tools', 'arguments' => ['query' => 'refund_get_order']], $reader)['result'];
                $this->assertSame(['refund_get_order'], array_column($search['structuredContent']['tools'], 'name'));
            }
            $this->assertSame('standard-eu', $send('tools/call', $policy, $reader, $negotiated)['result']['structuredContent']['policyId']);
            $used = 2;
            if ('selection' === $mode) {
                $this->assertSame($first, $send('tools/call', $wrappedOrder, $reader)['result']['structuredContent']['results'][0]['value']);
                ++$used;
            }
            $fs->dumpFile($directory.'/permission', "revoked\n");
            $this->assertSame($entryPoints, array_column($send('tools/list', [], $reader)['result']['tools'], 'name'));
            $this->assertSame([], $send('groups/list', [], $reader, $negotiated)['result']['groups']);
            $denied = $send('tools/call', $policy, $reader)['result'];
            $this->assertTrue($denied['isError']);
            $this->assertSame(['error' => ['reason' => 'tool_denied', 'tool' => 'refund_get_policy', 'index' => 0], 'completedCalls' => 0], $denied['structuredContent']);
            $gateway = $kernel->getContainer()->get(Gateway::class);
            $this->assertSame([], $gateway->trace()['operations'][0]['nativeEvents']);
            $fs->dumpFile($directory.'/permission', "granted\n");
            for (++$used; $used < Gateway::LIMIT; ++$used) {
                $this->assertFalse($send('tools/call', $order, $reader)['result']['isError']);
            }
            $this->assertSame('execution_limit', $send('tools/call', $policy, $reader, $negotiated)['result']['structuredContent']['error']['reason']);
            $this->assertSame('execution_limit', $send('tools/call', $policy, $reader)['result']['structuredContent']['error']['reason']);
            $this->assertSame([], $gateway->trace()['operations'][0]['nativeEvents']);
            if ('bare' === $mode) {
                $storage = new FileProfilerStorage('file:'.$directory.'/profiles');
                foreach ($storage->find(null, null, 30, 'POST') as $profile) {
                    $this->assertSame(['code_mode'], array_keys($storage->read($profile['token'])->getCollectors()));
                }
                $panels = RecordingProfiler::panels($directory);
                $this->assertNotEmpty($panels);
                $pages = implode('', array_map(file_get_contents(...), $panels));
                foreach (['Symfony Profiler', '2 tool definitions returned', 'groups/get', 'tool_denied', 'refund_get_order · succeeded', 'refund_get_policy · succeeded'] as $text) {
                    $this->assertStringContainsString($text, $pages);
                }
                foreach ([$reader, $viewer, 'R-1042', 'standard-eu'] as $private) {
                    $this->assertStringNotContainsString($private, $pages);
                }
                $this->assertNull($kernel->getContainer()->get('router')->getRouteCollection()->get('_profiler'));
            }
        } finally {
            $kernel->shutdown();
            $fs->remove($directory);
        }
    }

    public static function modes(): iterable
    {
        yield 'refund group' => ['bare', []];
        yield 'multiple groups and catalog' => ['selection', Discovery::CATALOG];
        yield 'two catalog tools for recording' => ['catalog', Discovery::CATALOG];
    }

    private function assertCatalogRecording(\Closure $send, string $reader, string $viewer, string $directory, Kernel $kernel): void
    {
        $negotiated = [PublishingGroups::ID];
        $order = ['name' => 'refund_get_order', 'arguments' => ['orderId' => 'R-1042']];
        $search = ['name' => 'search_tools', 'arguments' => ['query' => 'refund']];
        $open = static fn (string $group): array => ['name' => 'search_tools', 'arguments' => ['group' => $group]];
        $execute = static fn (array $read): array => ['name' => 'execute_tools', 'arguments' => ['calls' => [$read]]];
        foreach ([$reader, $viewer] as $token) {
            $listed = $send('tools/list', [], $token)['result']['tools'];
            $this->assertSame(Discovery::CATALOG, array_column($listed, 'name'));
            $this->assertStringNotContainsString('refund-review', json_encode($listed, \JSON_THROW_ON_ERROR));
            $this->assertEqualsCanonicalizing(['query', 'group'], array_keys($listed[0]['inputSchema']['properties']));
        }
        $this->assertSame('discovery_required', $send('tools/call', $execute($order), $reader)['result']['structuredContent']['error']['reason']);
        $hidden = $send('tools/call', $search, $viewer)['result']['structuredContent'];
        $this->assertSame([], $hidden['tools']);
        $this->assertSame([], $hidden['groups']);
        $unknown = $send('tools/call', $open('unknown-group'), $reader)['result'];
        $this->assertTrue($unknown['isError']);
        $this->assertArrayNotHasKey('tools', $unknown['structuredContent']);
        $this->assertArrayNotHasKey('instructions', $unknown['structuredContent']);
        $this->assertSame($unknown, $send('tools/call', $open('refund-review'), $viewer)['result']);
        $this->assertSame('discovery_required', $send('tools/call', $execute($order), $viewer)['result']['structuredContent']['error']['reason']);
        $this->assertTrue($send('tools/call', ['name' => 'search_tools', 'arguments' => ['group' => 'refund-review', 'query' => 'refund']], $reader)['result']['isError']);

        // Opening a group alone makes its member contracts usable by the normal catalog.
        $opened = $send('tools/call', $open('refund-review'), $reader)['result']['structuredContent'];
        $this->assertSame('refund-review', $opened['group']);
        $this->assertSame(Tools::NAMES, array_column($opened['tools'], 'name'));
        $this->assertSame(Fixture::instructions(), $opened['instructions']);
        $gateway = $kernel->getContainer()->get(Gateway::class);
        $this->assertSame(['search_tools'], array_column($gateway->trace()['operations'], 'operation'));
        $openTrace = $gateway->trace()['operations'][0];
        $this->assertSame([], $openTrace['nativeEvents']);
        $this->assertSame('refund-review', $openTrace['groupDiscovery']['selectedGroup']);
        $this->assertSame($opened['instructions'], $openTrace['groupDiscovery']['instructions']);
        $this->assertSame(Tools::NAMES, array_column($openTrace['discovery']['toolDefinitions'], 'name'));
        $first = $send('tools/call', $execute($order), $reader)['result']['structuredContent']['results'][0]['value'];
        $policy = ['name' => 'refund_get_policy', 'arguments' => ['policyId' => $first['policyId']]];
        $current = $send('tools/call', $execute($policy), $reader)['result']['structuredContent']['results'][0]['value'];
        $this->assertSame('standard-eu', $first['policyId']);
        $this->assertSame(5000, $current['manualReviewAboveMinor']);
        $matches = $send('tools/call', $search, $reader)['result']['structuredContent'];
        $this->assertEqualsCanonicalizing(Tools::NAMES, array_column($matches['tools'], 'name'));
        $this->assertContains('refund-review', array_column($matches['groups'], 'name'));
        $this->assertSame($matches['groups'], $gateway->trace()['operations'][0]['groupDiscovery']['groupSummaries']);
        $this->assertNull($gateway->trace()['operations'][0]['groupDiscovery']['selectedGroup']);
        foreach ($matches['groups'] as $summary) {
            $this->assertEqualsCanonicalizing(['name', 'description'], array_keys($summary));
        }
        $this->assertSame([], $send('tools/call', ['name' => 'search_tools', 'arguments' => ['query' => 'xylophone']], $reader)['result']['structuredContent']['groups']);
        $this->assertSame(array_keys(Discovery::GROUPS), array_column($send('groups/list', [], $reader, $negotiated)['result']['groups'], 'name'));
        $review = $send('groups/get', ['name' => 'refund-review'], $reader, $negotiated)['result'];
        $this->assertSame($review['tools'], $opened['tools']);
        $this->assertSame($review['instructions'], $opened['instructions']);
        $narrow = $send('tools/call', $open('order-lookup'), $reader)['result']['structuredContent'];
        $this->assertSame(['refund_get_order'], array_column($narrow['tools'], 'name'));
        $this->assertArrayNotHasKey('instructions', $narrow);
        $this->assertArrayNotHasKey('instructions', $send('groups/get', ['name' => 'order-lookup'], $reader, $negotiated)['result']);
        $this->assertArrayNotHasKey('result', $send('groups/get', ['name' => 'refund-review'], $viewer, $negotiated));
        $this->assertSame($current, $send('tools/call', $policy, $reader, $negotiated)['result']['structuredContent']);
        (new Filesystem())->dumpFile($directory.'/permission', "revoked\n");
        $this->assertSame([], $send('groups/list', [], $reader, $negotiated)['result']['groups']);
        $this->assertArrayHasKey('error', $send('groups/get', ['name' => 'refund-review'], $reader, $negotiated));
        $revoked = $send('tools/call', $search, $reader)['result']['structuredContent'];
        $this->assertSame([], $revoked['tools']);
        $this->assertSame([], $revoked['groups']);
        $this->assertSame($unknown, $send('tools/call', $open('refund-review'), $reader)['result']);
        $this->assertArrayNotHasKey('groupDiscovery', $gateway->trace()['operations'][0]);
        foreach ([[$execute($policy), null], [$policy, $negotiated]] as [$call, $extensions]) {
            $denied = $send('tools/call', $call, $reader, $extensions)['result'];
            $this->assertTrue($denied['isError']);
            $this->assertSame(['error' => ['reason' => 'tool_denied', 'tool' => 'refund_get_policy', 'index' => 0], 'completedCalls' => 0], $denied['structuredContent']);
            $this->assertSame([], $kernel->getContainer()->get(Gateway::class)->trace()['operations'][0]['nativeEvents']);
        }
        // A member omitted from tools/list is still checked by the same broker if its name is known.
        $hiddenRead = $send('tools/call', $policy, $reader)['result'];
        $this->assertSame('tool_denied', $hiddenRead['structuredContent']['error']['reason']);
        $this->assertSame(0, $hiddenRead['structuredContent']['completedCalls']);
        $this->assertSame('execution_limit', $send('tools/call', $policy, $reader, $negotiated)['result']['structuredContent']['error']['reason']);
    }
}
