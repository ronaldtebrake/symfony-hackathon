<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\{CodeModeSession, DiscoveryMode, ExecutionIdentity, ExecutionLimits, ExecutionResult, SandboxException, SandboxExecutor, ToolBroker, ToolCatalog, ToolDefinition};
use PHPUnit\Framework\TestCase;

final class SearchRefinementTest extends TestCase
{
    private function catalog(int $count = 12): ToolCatalog
    {
        $tools = [];
        for ($i = 0; $i < $count; ++$i) {
            $tools[] = new ToolDefinition('reader_'.$i, $i === 11 ? 'Read complete article body' : 'Read article metadata', (object) ['type' => 'object']);
        }
        return new ToolCatalog($tools);
    }

    public function testTruncationEmptyResultsAndRefinementHaveHonestCounts(): void
    {
        $catalog = $this->catalog();
        $broad = $catalog->searchResult('article read');
        self::assertSame(12, $broad['matched']);
        self::assertSame(8, $broad['returned']);
        self::assertCount(8, $broad['tools']);
        self::assertTrue($broad['truncated']);
        self::assertStringContainsString('More matching tools exist', $broad['hint']);
        $narrow = $catalog->searchResult('article body');
        self::assertSame(['reader_11'], array_column($narrow['tools'], 'name'));
        self::assertSame(1, $narrow['matched']);
        self::assertFalse($narrow['truncated']);
        self::assertArrayNotHasKey('hint', $narrow);
        self::assertSame(['reader_1'], array_column($catalog->searchResult('reader_1')['tools'], 'name'), 'Exact names must not also match reader_10 or reader_11.');
        $none = $catalog->searchResult('unavailable capability');
        self::assertSame([], $none['tools']);
        self::assertSame(0, $none['matched']);
        self::assertFalse($none['truncated']);
        self::assertStringContainsString('fewer terms', $none['hint']);
        self::assertFalse($this->catalog(8)->searchResult('')['truncated']);
    }

    public function testMetadataCannotActivateOmittedToolsOrResetExecutionBudget(): void
    {
        $sandbox = new class implements SandboxExecutor {
            public array $allowed = [];
            public function execute(string $code, ToolBroker $broker, ExecutionIdentity $identity, array $allowedTools = [], ?ExecutionLimits $limits = null): ExecutionResult
            { $this->allowed = $allowedTools; return new ExecutionResult(1, [], 1, 0, 'unit'); }
        };
        $session = new CodeModeSession($this->catalog(), new ToolBroker([]), $sandbox, new ExecutionIdentity('reader'));
        $session->describe(['reader_0']);
        $session->searchResult('article');
        $session->execute('return 1;');
        self::assertNotContains('reader_11', $sandbox->allowed);
        $session->searchResult('article body');
        $session->describe(['reader_11']);
        $session->execute('return 1;');
        self::assertContains('reader_11', $sandbox->allowed);
        $session->searchResult('reader_11');
        try { $session->execute('return 1;'); self::fail('Refinement reset execution attempts.'); }
        catch (SandboxException $error) { self::assertSame('execution_limit', $error->reason); }
        $full = new CodeModeSession($this->catalog(), new ToolBroker([]), $sandbox, new ExecutionIdentity('reader'), discovery: DiscoveryMode::Full);
        $full->searchResult('reader_11');
        try { $full->execute('return 1;'); self::fail('Full search skipped description.'); }
        catch (SandboxException $error) { self::assertSame('discovery_required', $error->reason); }
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
        $sandbox = $this->createMock(SandboxExecutor::class); $sandbox->expects(self::never())->method('execute');
        $session = new CodeModeSession(new ToolCatalog($tools), new ToolBroker([]), $sandbox, new ExecutionIdentity('reader'));
        $result = $session->searchResult('read');
        self::assertTrue($result['truncated']);
        foreach ($result['tools'] as $tool) { self::assertArrayNotHasKey('inputSchema', $tool); }
        try { $session->execute('return 1;'); self::fail('Search summaries activated tools.'); }
        catch (SandboxException $error) { self::assertSame('discovery_required', $error->reason); }
    }
}
