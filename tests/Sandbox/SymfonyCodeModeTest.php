<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Demo\BackendFactory;
use CodeMode\DockerEngine;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use PHPUnit\Framework\TestCase;

final class SymfonyCodeModeTest extends TestCase
{
    public function testBundleDeniesDraftThenCompletesPublishedAlternativeInSameSession(): void
    {
        $client = Client::builder()->setClientInfo('bundle-proof-test', '1')->setInitTimeout(10)->setRequestTimeout(30)->setMaxRetries(0)->build();
        $docker = new DockerEngine(json_decode(BackendFactory::env('CODE_MODE_DOCKER_COMMAND', '["docker"]'), true, flags: JSON_THROW_ON_ERROR));
        try {
            $client->connect(new StdioTransport(PHP_BINARY, ['bin/console', 'code-mode:serve', '--no-debug'], dirname(__DIR__, 2), maxBufferSize: 65536));
            self::assertSame(['tools_search', 'tools_describe', 'php_execute'], array_column($client->listTools()->tools, 'name'));
            $client->callTool('tools_describe', ['names' => ['articles_read', 'articles_list_published']]);
            $denied = $client->callTool('php_execute', ['code' => 'return $tools->call("articles_read", ["id" => 49]);']);
            self::assertTrue($denied->isError);
            self::assertSame('tool_denied', $denied->structuredContent['error']['reason']);
            self::assertSame('denied', $denied->structuredContent['toolCalls'][0]['status']);
            self::assertArrayNotHasKey('value', $denied->structuredContent);
            $allowed = $client->callTool('php_execute', ['code' => '$page = 1; $count = 0; $missing = []; do { $batch = $tools->call("articles_list_published", ["page" => $page]); foreach ($batch->items as $article) { ++$count; if (trim($article->meta_description) === "") { $missing[] = $article->id; } } $page = $batch->nextPage; } while ($page !== null); return ["audited" => $count, "missing" => $missing];']);
            self::assertFalse($allowed->isError);
            self::assertSame(['audited' => 48, 'missing' => [11, 22, 33, 44]], $allowed->structuredContent['value']);
            self::assertSame(array_fill(0, 4, 'succeeded'), array_column($allowed->structuredContent['toolCalls'], 'status'));
            self::assertNotSame($denied->structuredContent['runId'], $allowed->structuredContent['runId']);
            foreach ([$denied, $allowed] as $result) {
                self::assertMatchesRegularExpression('/\Acode-mode-[a-f0-9]{32}\z/', $result->structuredContent['runId']);
                self::assertSame('', $docker->control(['ps', '-aq', '--filter', 'name=^/'.$result->structuredContent['runId'].'$']));
            }
            $exhausted = $client->callTool('php_execute', ['code' => 'return 1;']);
            self::assertTrue($exhausted->isError);
            self::assertSame('execution_limit', $exhausted->structuredContent['error']['reason']);
            self::assertNull($exhausted->structuredContent['runId']);
        } finally {
            $client->disconnect();
        }
    }
}
