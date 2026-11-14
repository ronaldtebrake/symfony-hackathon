<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\Mcp\McpGateway;
use CodeMode\SandboxException;
use Mcp\Client;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;

final class McpGatewayTest extends TestCase
{
    public function testDiscoveryPreservesOutputSchemaAndDropsUnapprovedTools(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('listTools')->willReturn(new ListToolsResult([
            new Tool('read', null, ['type' => 'object'], 'Read content', null, outputSchema: ['type' => 'object', 'properties' => ['value' => ['type' => 'integer']]]),
            new Tool('delete', null, ['type' => 'object'], 'Delete content', null),
        ]));
        $gateway = new McpGateway(static fn (): Client => $client, ['read' => static fn (): bool => true]);
        $catalog = $gateway->discover();
        $this->assertCount(1, $catalog->all());
        $this->assertSame('integer', $catalog->describe(['read'])[0]->outputSchema->properties->value->type);
    }

    public function testRepeatedUpstreamPagesAreRejected(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('listTools')->willReturn(new ListToolsResult([], 'same-cursor'));
        $this->expectException(SandboxException::class);
        (new McpGateway(static fn (): Client => $client, []))->discover();
    }
}
