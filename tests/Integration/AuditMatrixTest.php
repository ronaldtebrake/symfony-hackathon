<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\AuditMatrix\Gateway;
use App\Demo\AuditMatrix\Kernel;
use App\Demo\AuditMatrix\Tools;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

final class AuditMatrixTest extends TestCase
{
    // Disposable kernels must not inflate the heap inherited by later 64 MiB broker tests.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBothHttpSurfacesUseTheSameContractsAndDenyRestrictedReads(): void
    {
        $contracts = [];
        foreach (['direct', 'catalog'] as $surface) {
            $directory = sys_get_temp_dir().'/matrix-test-'.bin2hex(random_bytes(8));
            $fs = new Filesystem();
            $fs->mkdir($directory, 0700);
            $token = bin2hex(random_bytes(32));
            file_put_contents($directory.'/token', $token);
            file_put_contents($directory.'/permission', "granted\n");
            $kernel = new Kernel($directory, $surface);
            $id = 0;
            $session = '';
            $send = static function (string $method, array $params, string $bearer) use ($kernel, &$id, &$session): array {
                $request = Request::create('/mcp', 'POST', server: ['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$bearer, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream',
                    'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18', 'HTTP_MCP_SESSION_ID' => $session], content: json_encode(['jsonrpc' => '2.0', 'id' => ++$id, 'method' => $method, 'params' => (object) $params]));
                $response = $kernel->handle($request);
                $session = $response->headers->get('Mcp-Session-Id') ?? $session;
                ob_start();
                $response->sendContent();
                $raw = ob_get_clean();
                $kernel->terminate($request, $response);
                if (preg_match('/^data: (.+)$/m', $raw, $match)) {
                    $raw = $match[1];
                }

                return ['status' => $response->getStatusCode(), 'body' => json_decode($raw, true)];
            };
            try {
                $this->assertSame(401, $send('tools/list', [], 'invalid')['status']);
                $this->assertSame(200, $send('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'operator-test', 'version' => '1']], $token)['status']);
                $list = $send('tools/list', [], $token);
                $this->assertSame(200, $list['status'], json_encode($list));
                $this->assertSame('direct' === $surface ? Tools::NAMES : ['search_tools', 'execute_tools'], array_column($list['body']['result']['tools'], 'name'));
                $gateway = $kernel->getContainer()->get(Gateway::class);
                $contracts[$surface] = json_decode(json_encode($gateway->contracts()), true);
                if ('catalog' === $surface) {
                    $discovery = $send('tools/call', ['name' => 'search_tools', 'arguments' => ['query' => '']], $token);
                    $this->assertCount(3, $discovery['body']['result']['structuredContent']['tools']);
                }
                $call = static fn (array $ids) => 'direct' === $surface ? ['name' => 'cms_get_articles', 'arguments' => ['ids' => $ids]]
                    : ['name' => 'execute_tools', 'arguments' => ['calls' => [['name' => 'cms_get_articles', 'arguments' => ['ids' => $ids]]]]];
                $allowed = $send('tools/call', $call([1]), $token)['body']['result'];
                $this->assertFalse($allowed['isError'] ?? false);
                $denied = $send('tools/call', $call([49]), $token)['body']['result'];
                $this->assertTrue($denied['isError']);
                $this->assertSame('tool_denied', $denied['structuredContent']['error']['reason']);
                $trace = $gateway->trace()['operations'][0];
                $this->assertSame([], $trace['nativeEvents']);
                $this->assertSame(2, $trace['budget'][array_key_last($trace['budget'])]['reservedCalls']);
                $fs->dumpFile($directory.'/permission', "revoked\n");
                $revoked = $send('tools/call', $call([1]), $token)['body'];
                $this->assertTrue(isset($revoked['error']) || ($revoked['result']['isError'] ?? false));
                $this->assertStringNotContainsString('Designing accessible navigation', json_encode($revoked));
                $hidden = $send('tools/list', [], $token)['body']['result']['tools'];
                $this->assertSame('direct' === $surface ? [] : ['search_tools', 'execute_tools'], array_column($hidden, 'name'));
                if ('catalog' === $surface) {
                    $this->assertSame([], $send('tools/call', ['name' => 'search_tools', 'arguments' => ['query' => '']], $token)['body']['result']['structuredContent']['tools']);
                }
                $fs->dumpFile($directory.'/permission', "granted\n");
                // Revoking, regranting and rebuilding the kernel must not refund attempts.
                $kernel->shutdown();
                $session = '';
                $send('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'operator-test', 'version' => '1']], $token);
                $allowedAfterRestart = $send('tools/call', $call([1]), $token)['body']['result'];
                $this->assertFalse($allowedAfterRestart['isError']);
                $gateway = $kernel->getContainer()->get(Gateway::class);
                $this->assertGreaterThanOrEqual(2, $gateway->trace()['operations'][0]['budget'][0]['attempts']);
                $last = $gateway->trace()['operations'][0]['budget'];
                $used = $last[array_key_last($last)]['attempts'];
                for ($i = $used; $i < Gateway::LIMIT; ++$i) {
                    $this->assertFalse($send('tools/call', $call([1]), $token)['body']['result']['isError']);
                }
                $exhausted = $send('tools/call', $call([1]), $token)['body']['result'];
                $this->assertSame('execution_limit', $exhausted['structuredContent']['error']['reason']);
                $this->assertSame([], $gateway->trace()['operations'][0]['nativeEvents']);
            } finally {
                $kernel->shutdown();
                $fs->remove($directory);
            }
        }
        $this->assertSame($contracts['direct'], $contracts['catalog']);
    }
}
