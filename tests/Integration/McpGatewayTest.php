<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\BackendFactory;
use CodeMode\BrokerWorker;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\SandboxException;
use PHPUnit\Framework\TestCase;

final class McpGatewayTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testRealDiscoveryAndCallsApplyLocalPolicyAndUpstreamAccess(): void
    {
        $gateway = (new BackendFactory(\dirname(__DIR__, 2)))->create('fixture');
        $catalog = $gateway->discover();
        $this->assertSame(BackendFactory::FIXTURE_TOOLS, array_map(static fn ($tool) => $tool->name, $catalog->all()));
        $this->assertCount(3, $catalog->search('metadata media'));
        $this->assertSame('object', $catalog->describe(['cms_get_articles'])[0]->inputSchema->type);
        $broker = $gateway->broker($catalog);
        $identity = new ExecutionIdentity('editor', ['content.audit']);
        // The advertised deletion tool claims to be read-only; that hint grants no permission.
        try {
            $broker->call('cms_delete_article', (object) ['id' => 1], $identity, ['cms_delete_article']);
            $this->fail('An upstream hint must not grant host access.');
        } catch (SandboxException $e) {
            $this->assertSame('tool_denied', $e->reason);
        }
        $worker = new BrokerWorker($broker, $identity, BackendFactory::FIXTURE_TOOLS, new ExecutionLimits(toolTimeMs: 3000));
        try {
            $page = $worker->call('cms_list_articles', (object) ['offset' => 0, 'limit' => 12], BrokerWorker::now() + 3);
            $this->assertSame(48, $page->total);
            $this->assertCount(12, $page->items);
            $this->assertSame(12, $page->next_offset);
            $article = $worker->call('cms_get_articles', (object) ['ids' => [11]], BrokerWorker::now() + 3);
            $this->assertSame('', $article->items[0]->meta_description);
            try {
                $worker->call('cms_get_articles', (object) ['ids' => [49]], BrokerWorker::now() + 3);
                $this->fail('A draft must remain inaccessible at the upstream service.');
            } catch (SandboxException $e) {
                $this->assertSame('tool_failed', $e->reason);
            }
        } finally {
            $worker->close();
        }
    }

    public function testUserCannotSelectAnInsecureRemoteMcpEndpoint(): void
    {
        $_SERVER['MCP_HTTP_URL'] = 'http://example.org/mcp';
        try {
            $this->expectException(\InvalidArgumentException::class);
            (new BackendFactory(\dirname(__DIR__, 2)))->create('http');
        } finally {
            unset($_SERVER['MCP_HTTP_URL']);
        }
    }
}
