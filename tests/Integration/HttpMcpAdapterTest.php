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
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = new Process([\PHP_BINARY, '-S', $address, '-t', 'public', 'public/index.php'], \dirname(__DIR__, 2), ['APP_ENV' => 'test', 'APP_DEBUG' => '0']);
        $saved = [];
        foreach (['MCP_HTTP_URL', 'MCP_HTTP_ALLOWED_TOOLS', 'MCP_HTTP_TOKEN'] as $name) {
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
            $this->assertTrue($ready, 'The local HTTP MCP fixture did not start.');
            // Every script referenced by the workbench must bypass the PHP router intact.
            preg_match_all('~src="(/[^"?]+\.js)"~', file_get_contents(\dirname(__DIR__, 2).'/templates/demo.html'), $scripts);
            foreach ($scripts[1] as $asset) {
                $response = $http->request('GET', 'http://'.$address.$asset);
                $this->assertSame(200, $response->getStatusCode(), $asset);
                $this->assertStringContainsString('javascript', $response->getHeaders()['content-type'][0]);
                $this->assertSame(file_get_contents(\dirname(__DIR__, 2).'/public'.$asset), $response->getContent());
            }
            $movie = '/media/symfony-native-tools-demo-90s.mp4';
            $response = $http->request('GET', 'http://'.$address.$movie, ['headers' => ['Range' => 'bytes=0-99']]);
            $this->assertSame(206, $response->getStatusCode());
            $this->assertSame('video/mp4', $response->getHeaders()['content-type'][0]);
            $this->assertSame(file_get_contents(\dirname(__DIR__, 2).'/public'.$movie, length: 100), $response->getContent());
            $this->assertSame(404, $http->request('GET', 'http://'.$address.'/media/private-recording.mov')->getStatusCode());
            $this->assertSame(403, $http->request('GET', 'http://'.$address.$movie, ['headers' => ['Host' => 'untrusted.example']])->getStatusCode());
            $_SERVER['MCP_HTTP_URL'] = 'http://'.$address.'/mcp';
            $_SERVER['MCP_HTTP_ALLOWED_TOOLS'] = '["app_status"]';
            $_SERVER['MCP_HTTP_TOKEN'] = 'test-only-token';
            $gateway = (new BackendFactory(\dirname(__DIR__, 2)))->create('http');
            $catalog = $gateway->discover();
            $this->assertSame('app_status', $catalog->all()[0]->name);
            $this->assertStringNotContainsString('test-only-token', json_encode($catalog->all()));
            $worker = new BrokerWorker($gateway->broker($catalog), new ExecutionIdentity('editor', ['content.audit']), ['app_status'], new ExecutionLimits(toolTimeMs: 3000));
            try {
                $this->assertSame('ok', $worker->call('app_status', new \stdClass(), BrokerWorker::now() + 3)->text);
            } finally {
                $worker->close();
            }
        } finally {
            $server->stop(0, \SIGKILL);
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
