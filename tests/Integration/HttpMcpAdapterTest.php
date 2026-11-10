<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\BackendFactory;
use CodeMode\BrokerWorker;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

final class HttpMcpAdapterTest extends TestCase
{
    public function testLocalServerServesScriptsAndMcpWithoutLeakingToken(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = new Process([PHP_BINARY, '-S', $address, '-t', 'public', 'public/index.php'], dirname(__DIR__, 2), ['APP_ENV' => 'test', 'APP_DEBUG' => '0']);
        $saved = [];
        foreach (['DRUPAL_MCP_URL', 'DRUPAL_MCP_ALLOWED_TOOLS', 'DRUPAL_MCP_TOKEN'] as $name) {
            $saved[$name] = $_SERVER[$name] ?? null;
        }
        try {
            $server->start();
            $http = HttpClient::create(['timeout' => 0.2]);
            $ready = false;
            $deadline = microtime(true) + 10;
            do {
                try {
                    $ready = 200 === $http->request('GET', 'http://'.$address.'/health')->getStatusCode();
                } catch (\Throwable) {
                    usleep(20000);
                }
            } while (!$ready && microtime(true) < $deadline && $server->isRunning());
            self::assertTrue($ready, 'The local HTTP MCP fixture did not start.');
            // Every script referenced by the workbench must bypass the PHP router intact.
            preg_match_all('~src="(/[^"?]+\.js)"~', file_get_contents(dirname(__DIR__, 2).'/templates/demo.html'), $scripts);
            foreach ($scripts[1] as $asset) {
                $response = $http->request('GET', 'http://'.$address.$asset);
                self::assertSame(200, $response->getStatusCode(), $asset);
                self::assertStringContainsString('javascript', $response->getHeaders()['content-type'][0]);
                self::assertSame(file_get_contents(dirname(__DIR__, 2).'/public'.$asset), $response->getContent());
            }
            $_SERVER['DRUPAL_MCP_URL'] = 'http://'.$address.'/mcp';
            $_SERVER['DRUPAL_MCP_ALLOWED_TOOLS'] = '["app_status"]';
            $_SERVER['DRUPAL_MCP_TOKEN'] = 'test-only-token';
            $gateway = (new BackendFactory(dirname(__DIR__, 2)))->create('drupal');
            $catalog = $gateway->discover();
            self::assertSame('app_status', $catalog->all()[0]->name);
            self::assertStringNotContainsString('test-only-token', json_encode($catalog->all()));
            $worker = new BrokerWorker($gateway->broker($catalog), new ExecutionIdentity('editor', ['content.audit']), ['app_status'], new ExecutionLimits(toolTimeMs: 3000));
            try {
                self::assertSame('ok', $worker->call('app_status', new \stdClass(), BrokerWorker::now() + 3)->text);
            } finally {
                $worker->close();
            }
        } finally {
            $server->stop(0, SIGKILL);
            foreach ($saved as $name => $value) {
                if (null === $value) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}
