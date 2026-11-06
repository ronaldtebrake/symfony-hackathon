<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\{CodeModeSession, CompactToolGuide, DiscoveryMode, ExecutionIdentity, ExecutionLimits, ExecutionResult, SandboxException, SandboxExecutor, ToolBroker, ToolCatalog, ToolDefinition};
use PHPUnit\Framework\TestCase;

final class CompactDiscoveryTest extends TestCase
{
    public function testExactInputConstraintsAndExistingReturnShapeAreReused(): void
    {
        $input = json_decode('{"type":"object","properties":{"id":{"type":"integer","minimum":1,"maximum":48},"kind":{"enum":["public"]}},"required":["id"],"additionalProperties":false}');
        $output = json_decode('{"type":"object","properties":{"title":{"type":"string"}},"required":["title"]}');
        $definition = new ToolDefinition('read', 'Read published content', $input, $output);
        $result = (new CompactToolGuide())->describe($definition);
        self::assertFalse($result['requiresDescribe']);
        self::assertSame($input, $result['inputSchema']);
        self::assertSame((new \CodeMode\PhpToolGuide())->describe($definition)['returns'], $result['returns']);
        self::assertSame(['name', 'description', 'requiresDescribe', 'inputSchema', 'returns'], array_keys($result));
    }

    public function testComplexOversizedAndReferencedContractsRequireFullDescription(): void
    {
        $schema = (object) ['type' => 'object'];
        $cases = [
            [(object) ['$ref' => 'https://invalid.example/private'], null],
            [$schema, (object) ['$ref' => 'file:///etc/passwd']],
            [$schema, (object) ['type' => 'object', 'properties' => (object) array_fill_keys(range('a', 'i'), (object) ['type' => 'string'])]],
            [$schema, json_decode('{"type":"array","items":{"type":"array","items":{"type":"array","items":{"type":"array","items":{"type":"string"}}}}}')],
            [$schema, (object) ['oneOf' => array_fill(0, 7, (object) ['type' => 'integer'])]],
            [$schema, (object) ['type' => 'object', 'patternProperties' => (object) ['.*' => (object) ['type' => 'string']]]],
            [(object) ['type' => 'object', 'description' => str_repeat('large', 900)], null],
        ];
        foreach ($cases as [$input, $output]) {
            self::assertSame(['name' => 'read', 'description' => 'Read', 'requiresDescribe' => true], (new CompactToolGuide())->describe(new ToolDefinition('read', 'Read', $input, $output)));
        }
    }

    public function testOnlyReturnedReadyToolsActivateAndSearchCannotResetBudget(): void
    {
        $sandbox = new class implements SandboxExecutor {
            public array $allowed = [];
            public function execute(string $code, ToolBroker $broker, ExecutionIdentity $identity, array $allowedTools = [], ?ExecutionLimits $limits = null): ExecutionResult
            { $this->allowed = $allowedTools; return new ExecutionResult(42, [], 1, 0, 'unit'); }
        };
        $catalog = new ToolCatalog([
            new ToolDefinition('read', 'published content', (object) ['type' => 'object']),
            new ToolDefinition('complex', 'published content', (object) ['type' => 'object'], (object) ['$ref' => '#/unknown']),
            new ToolDefinition('unreturned', 'other', (object) ['type' => 'object']),
        ]);
        $session = new CodeModeSession($catalog, new ToolBroker([]), $sandbox, new ExecutionIdentity('reader'), discovery: DiscoveryMode::Compact);
        self::assertSame([], $session->search('no-match'));
        try { $session->execute('return 1;'); self::fail('Empty discovery allowed execution.'); }
        catch (SandboxException $error) { self::assertSame('discovery_required', $error->reason); }
        $session->search('published');
        $session->execute('return 42;');
        self::assertSame(['read'], $sandbox->allowed);
        $session->describe(['complex']);
        $session->execute('return 42;');
        self::assertSame(['read', 'complex'], $sandbox->allowed);
        $session->search('other');
        try { $session->execute('return 42;'); self::fail('Search reset the budget.'); }
        catch (SandboxException $error) { self::assertSame('execution_limit', $error->reason); }
        try { $session->describe(['admin_delete']); self::fail('Unknown tool accepted.'); }
        catch (SandboxException $error) { self::assertSame('tool_denied', $error->reason); }
    }

    public function testFailedSearchDoesNotActivateEarlierResults(): void
    {
        $sandbox = $this->createMock(SandboxExecutor::class);
        $sandbox->expects(self::never())->method('execute');
        $catalog = new ToolCatalog([
            new ToolDefinition('good', 'Read', (object) ['type' => 'object']),
            new ToolDefinition('bad', 'Read', (object) ['type' => 'object', 'required' => 'malformed']),
        ]);
        $session = new CodeModeSession($catalog, new ToolBroker([]), $sandbox, new ExecutionIdentity('reader'), discovery: DiscoveryMode::Compact);
        try { $session->search('read'); self::fail('Malformed guide accepted.'); } catch (\TypeError) {}
        try { $session->execute('return 1;'); self::fail('Failed disclosure activated tools.'); }
        catch (SandboxException $error) { self::assertSame('discovery_required', $error->reason); }
    }

    public function testCombinedResponseBudgetIsAtomicEvenWhenEveryEntryFits(): void
    {
        $tools = [];
        for ($i = 0; $i < 8; ++$i) {
            $input = (object) ['type' => 'object', 'description' => ''];
            $tool = new ToolDefinition('tool_'.$i, 'Read', $input);
            $length = strlen(json_encode((new CompactToolGuide())->describe($tool), JSON_THROW_ON_ERROR));
            $input->description = str_repeat('x', 4096 - $length);
            self::assertSame(4096, strlen(json_encode((new CompactToolGuide())->describe($tool), JSON_THROW_ON_ERROR)));
            $tools[] = $tool;
        }
        $sandbox = $this->createMock(SandboxExecutor::class);
        $sandbox->expects(self::never())->method('execute');
        $session = new CodeModeSession(new ToolCatalog($tools), new ToolBroker([]), $sandbox, new ExecutionIdentity('reader'), discovery: DiscoveryMode::Compact);
        try { $session->search('read'); self::fail('Oversized response accepted.'); }
        catch (SandboxException $error) { self::assertSame('discovery_limit', $error->reason); }
        try { $session->execute('return 1;'); self::fail('Oversized response activated tools.'); }
        catch (SandboxException $error) { self::assertSame('discovery_required', $error->reason); }
    }
}
