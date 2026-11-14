<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\CodeModeSession;
use CodeMode\DiscoveryMode;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\ExecutionResult;
use CodeMode\SandboxException;
use CodeMode\SandboxExecutor;
use CodeMode\ToolBroker;
use CodeMode\ToolCatalog;
use CodeMode\ToolDefinition;
use PHPUnit\Framework\TestCase;

final class SearchRefinementTest extends TestCase
{
    public function testTruncationEmptyResultsAndRefinementHaveHonestCounts(): void
    {
        $catalog = $this->catalog();
        $broad = $catalog->searchResult('article read');
        $this->assertSame(12, $broad['matched']);
        $this->assertSame(8, $broad['returned']);
        $this->assertCount(8, $broad['tools']);
        $this->assertTrue($broad['truncated']);
        $this->assertStringContainsString('More matching tools exist', $broad['hint']);
        $narrow = $catalog->searchResult('article body');
        $this->assertSame(['reader_11'], array_column($narrow['tools'], 'name'));
        $this->assertSame(1, $narrow['matched']);
        $this->assertFalse($narrow['truncated']);
        $this->assertArrayNotHasKey('hint', $narrow);
        $this->assertSame(['reader_1'], array_column($catalog->searchResult('reader_1')['tools'], 'name'), 'Exact names must not also match reader_10 or reader_11.');
        $none = $catalog->searchResult('unavailable capability');
        $this->assertSame([], $none['tools']);
        $this->assertSame(0, $none['matched']);
        $this->assertFalse($none['truncated']);
        $this->assertStringContainsString('fewer terms', $none['hint']);
        $this->assertFalse($this->catalog(8)->searchResult('')['truncated']);
    }

    public function testMetadataCannotActivateOmittedToolsOrResetExecutionBudget(): void
    {
        $sandbox = new class implements SandboxExecutor {
            public array $allowed = [];

            public function execute(string $code, ToolBroker $broker, ExecutionIdentity $identity, array $allowedTools = [], ?ExecutionLimits $limits = null): ExecutionResult
            {
                $this->allowed = $allowedTools;

                return new ExecutionResult(1, [], 1, 0, 'unit');
            }
        };
        $session = new CodeModeSession($this->catalog(), new ToolBroker([]), $sandbox, new ExecutionIdentity('reader'));
        $session->describe(['reader_0']);
        $session->searchResult('article');
        $session->execute('return 1;');
        $this->assertNotContains('reader_11', $sandbox->allowed);
        $session->searchResult('article body');
        $session->describe(['reader_11']);
        $session->execute('return 1;');
        $this->assertContains('reader_11', $sandbox->allowed);
        $session->searchResult('reader_11');
        try {
            $session->execute('return 1;');
            $this->fail('Refinement reset execution attempts.');
        } catch (SandboxException $error) {
            $this->assertSame('execution_limit', $error->reason);
        }
        $full = new CodeModeSession($this->catalog(), new ToolBroker([]), $sandbox, new ExecutionIdentity('reader'), discovery: DiscoveryMode::Full);
        $full->searchResult('reader_11');
        try {
            $full->execute('return 1;');
            $this->fail('Full search skipped description.');
        } catch (SandboxException $error) {
            $this->assertSame('discovery_required', $error->reason);
        }
    }

    public function testBroadSearchDefersLargeContractsWithoutDisclosingThem(): void
    {
        $tools = [];
        for ($i = 0; $i < 8; ++$i) {
            $input = (object) ['type' => 'object', 'description' => ''];
            $tool = new ToolDefinition('reader_'.$i, 'Read', $input);
            $input->description = str_repeat('x', 4000);
            $tools[] = $tool;
        }
        $tools[] = new ToolDefinition('omitted', 'Read', (object) ['type' => 'object']);
        $sandbox = $this->createMock(SandboxExecutor::class);
        $sandbox->expects($this->never())->method('execute');
        $session = new CodeModeSession(new ToolCatalog($tools), new ToolBroker([]), $sandbox, new ExecutionIdentity('reader'));
        $result = $session->searchResult('read');
        $this->assertTrue($result['truncated']);
        foreach ($result['tools'] as $tool) {
            $this->assertArrayNotHasKey('inputSchema', $tool);
        }
        try {
            $session->execute('return 1;');
            $this->fail('Search summaries activated tools.');
        } catch (SandboxException $error) {
            $this->assertSame('discovery_required', $error->reason);
        }
    }

    private function catalog(int $count = 12): ToolCatalog
    {
        $tools = [];
        for ($i = 0; $i < $count; ++$i) {
            $tools[] = new ToolDefinition('reader_'.$i, 11 === $i ? 'Read complete article body' : 'Read article metadata', (object) ['type' => 'object']);
        }

        return new ToolCatalog($tools);
    }
}
