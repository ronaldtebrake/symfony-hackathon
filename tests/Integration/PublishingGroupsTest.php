<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\AuditMatrix\Gateway;
use App\Demo\AuditMatrix\Kernel;
use App\Demo\AuditMatrix\Tools;
use App\Demo\Groups\PublishingGroups;
use Mcp\Schema\Wire\McpHeader;
use Mcp\Server\Stateless\RequestMeta;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

final class PublishingGroupsTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[TestDox('A fresh Symfony app serves the Publishing catalog and group, then denies a known reader after revocation')]
    public function testScopedReadsUseTheSameIdentityDiscoveryPolicyAndBudget(): void
    {
        $fs = new Filesystem();
        $directory = sys_get_temp_dir().'/publishing-groups-'.bin2hex(random_bytes(8));
        $fs->mkdir($directory, 0700);
        $reader = bin2hex(random_bytes(32));
        $viewer = bin2hex(random_bytes(32));
        file_put_contents($directory.'/token', $reader);
        file_put_contents($directory.'/viewer-token', $viewer);
        file_put_contents($directory.'/permission', "granted\n");
        $kernel = new Kernel($directory, 'groups');
        try {
            $send = static function (string $method, array $params = [], bool $negotiated = true, ?string $user = null) use ($reader, $kernel): array {
                $params['_meta'] = [RequestMeta::PROTOCOL_VERSION => '2026-07-28',
                    RequestMeta::CLIENT_CAPABILITIES => $negotiated ? ['extensions' => [PublishingGroups::ID => new \stdClass()]] : new \stdClass()];
                $headers = ['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer '.($user ?? $reader),
                    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream',
                    'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28', 'HTTP_MCP_METHOD' => $method];
                if (null !== $name = McpHeader::nameFor($method, $params)) {
                    $headers['HTTP_MCP_NAME'] = $name;
                }
                $request = Request::create('/mcp', 'POST', server: $headers, content: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR));
                $response = $kernel->handle($request);
                self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
                ob_start();
                $response->sendContent();
                $body = ob_get_clean();
                $kernel->terminate($request, $response);

                return json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
            };
            $group = ['name' => 'publishing'];
            $read = ['name' => 'cms_get_articles', 'arguments' => ['ids' => [11], 'fields' => ['id', 'meta_description']]];
            $wrapper = ['name' => 'execute_tools', 'arguments' => ['calls' => [$read]]];
            $discover = $send('server/discover');
            $this->assertArrayHasKey(PublishingGroups::ID, $discover['result']['capabilities']['extensions']);
            foreach ([true, false] as $negotiated) {
                $this->assertSame(['search_tools', 'execute_tools'], array_column($send('tools/list', negotiated: $negotiated)['result']['tools'], 'name'));
            }
            // A fresh client that already knows a name can call it before opening any group.
            $this->assertSame([['id' => 11, 'meta_description' => '']], $send('tools/call', $read)['result']['structuredContent']['items']);
            $this->assertSame('discovery_required', $send('tools/call', $wrapper, false)['result']['structuredContent']['error']['reason']);
            $visible = $send('groups/list')['result'];
            $this->assertSame([['name' => 'publishing', 'title' => 'Publishing',
                'description' => 'Check published articles and their images for editorial and accessibility issues.']], $visible['groups']);
            $this->assertSame('private', $visible['cacheScope']);
            $this->assertSame(0, $visible['ttlMs']);
            $this->assertSame(-32021, $send('groups/list', negotiated: false)['error']['code']);
            $this->assertSame(-32021, $send('groups/get', $group, false)['error']['code']);
            $this->assertSame(-32602, $send('groups/list', ['cursor' => 'bogus'])['error']['code']);

            $fallback = $send('tools/call', ['name' => 'search_tools', 'arguments' => ['query' => '']], false)['result']['structuredContent']['tools'];
            $bundle = $send('groups/get', $group)['result'];
            $this->assertSame('private', $bundle['cacheScope']);
            $this->assertSame(0, $bundle['ttlMs']);
            $this->assertEqualsCanonicalizing(Tools::NAMES, array_column($bundle['tools'], 'name'));
            $this->assertEqualsCanonicalizing($fallback, $bundle['tools']);
            $this->assertSame([], $send('groups/list', user: $viewer)['result']['groups']);
            $absent = $send('groups/get', ['name' => 'absent'])['error'];
            $this->assertSame(-32002, $absent['code']);
            $this->assertSame($absent, $send('groups/get', $group, user: $viewer)['error']);
            $this->assertSame('tool_denied', $send('tools/call', $read, user: $viewer)['result']['structuredContent']['error']['reason']);
            $this->assertSame('tool_denied', $send('tools/call', ['name' => 'cms_get_articles', 'arguments' => ['ids' => [49]]])['result']['structuredContent']['error']['reason']);
            $this->assertSame('invalid_arguments', $send('tools/call', ['name' => 'cms_get_articles', 'arguments' => ['ids' => [11], 'fields' => ['secret']]])['result']['structuredContent']['error']['reason']);
            $this->assertFalse($send('tools/call', $wrapper, false)['result']['isError']);

            file_put_contents($directory.'/permission', "revoked\n");
            $this->assertSame([], $send('groups/list')['result']['groups']);
            $this->assertSame($absent, $send('groups/get', $group)['error']);
            $denied = $send('tools/call', $read)['result']['structuredContent'];
            $this->assertSame(['error' => ['reason' => 'tool_denied', 'tool' => $read['name'], 'index' => 0], 'completedCalls' => 0], $denied);
            $trace = $kernel->getContainer()->get(Gateway::class)->trace()['operations'][0];
            $this->assertSame([], $trace['nativeEvents']);
            $this->assertSame(5, $trace['budget'][array_key_last($trace['budget'])]['attempts']);
            file_put_contents($directory.'/permission', "granted\n");
            $kernel->shutdown();
            for ($i = 5; $i < Gateway::LIMIT; ++$i) {
                $this->assertFalse($send('tools/call', $read)['result']['isError']);
            }
            $this->assertSame('execution_limit', $send('tools/call', $wrapper, false)['result']['structuredContent']['error']['reason']);
            $this->assertSame('execution_limit', $send('tools/call', $read)['result']['structuredContent']['error']['reason']);
        } finally {
            $kernel->shutdown();
            $fs->remove($directory);
        }
    }
}
