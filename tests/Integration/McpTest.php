<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Schema\Content\TextContent;
use PHPUnit\Framework\TestCase;

final class McpTest extends TestCase
{
    public function testSdkCanDiscoverAndCallToolOverStdio(): void
    {
        $client = Client::builder()
            ->setClientInfo('foundation-test', '1.0.0')
            ->setInitTimeout(10)
            ->setRequestTimeout(10)
            ->build();

        try {
            $client->connect(new StdioTransport(
                \PHP_BINARY,
                ['bin/console', 'mcp:server', 'default', '--env=test', '--no-debug'],
                \dirname(__DIR__, 2),
            ));

            $tools = $client->listTools()->tools;
            $this->assertCount(1, $tools);
            $this->assertSame('app_status', $tools[0]->name);

            $result = $client->callTool('app_status');
            $this->assertFalse($result->isError);
            $this->assertInstanceOf(TextContent::class, $result->content[0]);
            $this->assertSame('ok', $result->content[0]->text);
        } finally {
            $client->disconnect();
        }
    }
}
