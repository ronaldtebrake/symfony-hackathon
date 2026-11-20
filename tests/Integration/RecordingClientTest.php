<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\HttpConversation\RecordingClient;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class RecordingClientTest extends TestCase
{
    public function testResponseCanPrecedeItsProfilerWriteWithoutLosingEvidence(): void
    {
        $directory = sys_get_temp_dir().'/recorder-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $writer = new Process([\PHP_BINARY, '-r', <<<'PHP'
            require $argv[1];
            $trace = new CodeMode\Symfony\Profiler\CatalogTrace();
            $trace->begin('search_tools');
            $trace->finish(['tools' => []], false, 1);
            $collector = new CodeMode\Symfony\Profiler\CatalogDataCollector($trace);
            $collector->collect(new Symfony\Component\HttpFoundation\Request(), new Symfony\Component\HttpFoundation\Response());
            $profile = new Symfony\Component\HttpKernel\Profiler\Profile('abcdef');
            $profile->setMethod('POST');
            $profile->setUrl('http://localhost/mcp');
            $profile->setTime(time());
            $profile->setStatusCode(200);
            $profile->setCollectors(['code_mode' => $collector]);
            // Signal readiness before deliberately delaying kernel.terminate's write.
            file_put_contents($argv[2].'/ready', 'ready');
            $until = microtime(true) + 3;
            while (!is_file($argv[2].'/release') && microtime(true) < $until) {
                usleep(1000);
            }
            usleep(100000);
            (new Symfony\Component\HttpKernel\Profiler\FileProfilerStorage('file:'.$argv[2]))->write($profile);
            PHP, \dirname(__DIR__, 2).'/vendor/autoload.php', $directory]);
        try {
            $writer->start();
            $until = microtime(true) + 3;
            while (!is_file($directory.'/ready') && microtime(true) < $until) {
                usleep(1000);
            }
            $this->assertFileExists($directory.'/ready');
            $this->assertFileDoesNotExist($directory.'/ef/cd/abcdef');
            $client = $this->client($directory);
            file_put_contents($directory.'/release', 'release');
            $rows = $client->records();
            $this->assertSame('search_tools', $rows[0]['profiler']['operations'][0]['operation']);
            $this->assertSame('succeeded', $rows[0]['profiler']['operations'][0]['status']);
            $this->assertSame(0, $writer->wait(), $writer->getErrorOutput());
        } finally {
            $writer->stop();
            (new Filesystem())->remove($directory);
        }
    }

    public function testMissingProfileRemainsMissingAfterABoundedWait(): void
    {
        $directory = sys_get_temp_dir().'/recorder-missing-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $started = microtime(true);
            $rows = $this->client($directory)->records();
            $this->assertNull($rows[0]['profiler']);
            $this->assertLessThan(2, microtime(true) - $started);
        } finally {
            (new Filesystem())->remove($directory);
        }
    }

    #[DataProvider('unexpectedResults')]
    public function testRejectsAnUnexpectedResponseBeforeTheBridgeCanUseIt(string $method, array $result): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->client(sys_get_temp_dir(), $method, $result);
    }

    public static function unexpectedResults(): iterable
    {
        yield 'extra catalog page' => ['tools/list', ['tools' => [], 'nextCursor' => 'next']];
        yield 'different structured and text results' => ['tools/call', ['content' => [['type' => 'text', 'text' => '{"count":1}']], 'structuredContent' => ['count' => 2]]];
        yield 'extra unchecked content block' => ['tools/call', ['content' => [['type' => 'text', 'text' => '{}'], ['type' => 'text', 'text' => 'extra']], 'structuredContent' => (object) []]];
    }

    private function client(string $directory, string $method = 'tools/call', ?array $result = null): RecordingClient
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result ?? ['content' => [['type' => 'text', 'text' => '{}']], 'structuredContent' => (object) []]], \JSON_THROW_ON_ERROR);
        $inner = new class($body) implements ClientInterface {
            public function __construct(private readonly string $body)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['X-Debug-Token' => 'abcdef'], $this->body);
            }
        };
        $client = new RecordingClient($inner, $directory);
        $client->sendRequest(new Request('POST', 'http://localhost/mcp', body: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method], \JSON_THROW_ON_ERROR)));

        return $client;
    }
}
