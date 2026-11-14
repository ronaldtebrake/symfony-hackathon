<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Demo\BackendFactory;
use CodeMode\DockerEngine;
use CodeMode\ExecutionTraceFile;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use PHPUnit\Framework\TestCase;

final class SymfonyCodeModeTest extends TestCase
{
    public static function discoveryModes(): iterable
    {
        yield ['full'];
        yield ['default'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('discoveryModes')]
    public function testBundleDeniesDraftThenCompletesPublishedAlternativeInSameSession(string $mode): void
    {
        $client = Client::builder()->setClientInfo('bundle-proof-test', '1')->setInitTimeout(10)->setRequestTimeout(30)->setMaxRetries(0)->build();
        $docker = new DockerEngine(json_decode(BackendFactory::env('CODE_MODE_DOCKER_COMMAND', '["docker"]'), true, flags: \JSON_THROW_ON_ERROR));
        $traceDir = sys_get_temp_dir().'/code-mode-test-'.bin2hex(random_bytes(12));
        mkdir($traceDir, 0700);
        $traceFile = $traceDir.'/trace.jsonl';
        try {
            $client->connect(new StdioTransport(\PHP_BINARY, ['bin/console', 'code-mode:serve', '--no-debug', ...('default' === $mode ? [] : ['--discovery='.$mode]), '--trace-file='.$traceFile], \dirname(__DIR__, 2), maxBufferSize: 65536));
            $this->assertSame(['tools_search', 'tools_describe', 'php_execute'], array_column($client->listTools()->tools, 'name'));
            $found = $client->callTool('tools_search', ['query' => 'articles']);
            $this->assertArrayNotHasKey('inputSchema', $found->structuredContent['tools'][0]);
            $client->callTool('tools_describe', ['names' => ['articles_read', 'articles_list_published']]);
            $denied = $client->callTool('php_execute', ['code' => 'return $tools->call("articles_read", ["id" => 49]);']);
            $this->assertTrue($denied->isError);
            $this->assertSame('tool_denied', $denied->structuredContent['error']['reason']);
            $this->assertSame(['error' => ['reason' => 'tool_denied', 'tool' => 'articles_read']], $denied->structuredContent);
            $this->assertArrayNotHasKey('value', $denied->structuredContent);
            $allowed = $client->callTool('php_execute', ['code' => '$page = 1; $count = 0; $missing = []; do { $batch = $tools->call("articles_list_published", ["page" => $page]); foreach ($batch->items as $article) { ++$count; if (trim($article->meta_description) === "") { $missing[] = $article->id; } } $page = $batch->nextPage; } while ($page !== null); return ["audited" => $count, "missing" => $missing];']);
            $this->assertFalse($allowed->isError);
            $this->assertSame(['audited' => 48, 'missing' => [11, 22, 33, 44]], $allowed->structuredContent['value']);
            $this->assertSame(['value'], array_keys($allowed->structuredContent));
            $records = array_map(static fn ($line) => json_decode($line, true, flags: \JSON_THROW_ON_ERROR), file($traceFile, \FILE_IGNORE_NEW_LINES));
            $this->assertCount(2, $records);
            $this->assertSame('denied', $records[0]['trace']['toolCalls'][0]['status']);
            $this->assertSame(array_fill(0, 4, 'succeeded'), array_column($records[1]['trace']['toolCalls'], 'status'));
            $this->assertNotSame($records[0]['trace']['runId'], $records[1]['trace']['runId']);
            foreach ($records as $record) {
                $this->assertMatchesRegularExpression('/\Acode-mode-[a-f0-9]{32}\z/', $record['trace']['runId']);
                $this->assertSame('', $docker->control(['ps', '-aq', '--filter', 'name=^/'.$record['trace']['runId'].'$']));
            }
            $exhausted = $client->callTool('php_execute', ['code' => 'return 1;']);
            $this->assertTrue($exhausted->isError);
            $this->assertSame('execution_limit', $exhausted->structuredContent['error']['reason']);
            $this->assertSame(['error' => ['reason' => 'execution_limit']], $exhausted->structuredContent);
            $this->assertNull(ExecutionTraceFile::last($traceFile, 'return 1;')['runId']);
        } finally {
            $client->disconnect();
            if (is_file($traceFile)) {
                unlink($traceFile);
            }
            rmdir($traceDir);
        }
    }
}
