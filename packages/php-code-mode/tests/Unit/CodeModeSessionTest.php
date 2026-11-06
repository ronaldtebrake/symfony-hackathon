<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\{CodeModeSession, ExecutionIdentity, ExecutionLimits, ExecutionResult, SandboxException, SandboxExecutor, ToolBroker, ToolCatalog, ToolDefinition};
use PHPUnit\Framework\TestCase;

final class CodeModeSessionTest extends TestCase
{
    public function testDiscoverySelectsOnlyApprovedSchemasAndExecutionAttemptsAreBounded(): void
    {
        $sandbox = new class implements SandboxExecutor {
            public array $allowed = [];
            public function execute(string $code, ToolBroker $broker, ExecutionIdentity $identity, array $allowedTools = [], ?ExecutionLimits $limits = null): ExecutionResult
            {
                $this->allowed = $allowedTools;
                return new ExecutionResult(42, [], 1, 0, 'unit-test');
            }
        };
        $catalog = new ToolCatalog([new ToolDefinition('content.read', 'Read content metadata', (object) ['type' => 'object'])]);
        $session = new CodeModeSession($catalog, new ToolBroker([]), $sandbox, new ExecutionIdentity('alice'), discovery: \CodeMode\DiscoveryMode::Full);
        self::assertSame(['name', 'description'], array_keys($session->search('metadata')[0]));
        try {
            $session->execute('return 42;');
            self::fail('Schema discovery must happen first.');
        } catch (SandboxException $e) {
            self::assertSame('discovery_required', $e->reason);
        }
        $session->describe(['content.read']);
        self::assertSame(42, $session->execute('return 42;')->value);
        self::assertSame(['content.read'], $sandbox->allowed);
        $session->execute('return 42;');
        try {
            $session->execute('return 42;');
            self::fail('Execution attempts must be bounded.');
        } catch (SandboxException $e) {
            self::assertSame('execution_limit', $e->reason);
        }
    }

    public function testUnknownToolCannotBeDescribed(): void
    {
        $this->expectException(SandboxException::class);
        (new ToolCatalog([]))->describe(['admin.delete']);
    }

    public function testFailedGuideDoesNotActivateAnyToolInTheBatch(): void
    {
        $sandbox = $this->createMock(SandboxExecutor::class);
        $sandbox->expects(self::never())->method('execute');
        $catalog = new ToolCatalog([
            new ToolDefinition('valid', 'Read', (object) ['type' => 'object']),
            new ToolDefinition('malformed', 'Read', (object) ['type' => 'object', 'required' => 'invalid schema']),
        ]);
        $session = new CodeModeSession($catalog, new ToolBroker([]), $sandbox, new ExecutionIdentity('reader'));
        try { $session->describe(['valid', 'malformed']); self::fail('Invalid metadata was accepted.'); }
        catch (\TypeError) {}
        try { $session->execute('return 1;'); self::fail('Failed discovery activated a tool.'); }
        catch (SandboxException $e) { self::assertSame('discovery_required', $e->reason); }
    }

    public function testInvalidNameTypesAreRejectedWithoutCoercion(): void
    {
        $this->expectException(SandboxException::class);
        (new ToolCatalog([]))->describe([['name' => 'admin.delete']]);
    }
}
