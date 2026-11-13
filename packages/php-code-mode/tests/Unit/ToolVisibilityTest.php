<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\{BatchSession, CodeModeSession, ExecutionIdentity, ExecutionLimits, ExecutionResult, SandboxException, SandboxExecutor, Tool, ToolBroker, ToolCatalog, ToolDefinition, ToolVisibilityInterface};
use PHPUnit\Framework\TestCase;

final class ToolVisibilityTest extends TestCase
{
    private function fixtures(): array
    {
        $schema = (object) ['type' => 'object', 'additionalProperties' => false];
        $definitions = $handlers = [];
        foreach (['public', 'private'] as $name) {
            $definitions[] = new ToolDefinition($name, $name.' capability', $schema);
            $handlers[] = new Tool($name, $schema, static fn () => true, static fn () => $name);
        }
        $policy = new class implements ToolVisibilityInterface {
            public bool $revoked = false;
            public bool $fail = false;
            public function isVisible(ExecutionIdentity $identity, string $tool): bool
            {
                if ($this->fail) { throw new \RuntimeException('Private policy failure'); }
                return !$this->revoked && ('public' === $tool || $identity->allows('private.read'));
            }
        };
        return [new ToolCatalog($definitions), new ToolBroker($handlers), $policy];
    }

    public function testDiscoveryFiltersBeforeCountingAndDoesNotLeakAcrossUsers(): void
    {
        [$catalog, $broker, $policy] = $this->fixtures();
        $reader = new BatchSession($catalog, $broker, new ExecutionIdentity('reader'), visibility: $policy);
        $editor = new BatchSession($catalog, $broker, new ExecutionIdentity('editor', ['private.read']), visibility: $policy);
        self::assertSame(2, $editor->searchContracts('')['matched']);
        foreach ([$reader->searchContracts(''), $reader->searchResult('')] as $result) {
            self::assertSame(1, $result['matched']);
            self::assertSame(['public'], array_column($result['tools'], 'name'));
            self::assertFalse($result['truncated']);
        }
        self::assertSame(0, $reader->searchContracts('private')['matched']);
        self::assertSame([], $reader->searchContracts('private')['tools']);
        $this->expectException(SandboxException::class);
        $reader->describe(['private']);
    }

    public function testRevocationBlocksPreviouslyDiscoveredToolAndRetainsBudget(): void
    {
        [$catalog, $broker, $policy] = $this->fixtures();
        $session = new BatchSession($catalog, $broker, new ExecutionIdentity('editor', ['private.read']), visibility: $policy);
        $session->searchContracts('private');
        $policy->revoked = true;
        self::assertSame(0, $session->searchContracts('')['matched']);
        try { $session->execute([['name' => 'private', 'arguments' => (object) []]]); self::fail('Revoked tool executed'); }
        catch (SandboxException $e) { self::assertSame('tool_denied', $e->reason); }
        self::assertSame(1, $session->state()['executions']);
        self::assertSame(1, $session->state()['toolCalls']);
        // Rehydrated discovery is not a permission grant either.
        $next = new BatchSession($catalog, $broker, new ExecutionIdentity('editor'), state: $session->state(), visibility: $policy);
        try { $next->execute([['name' => 'private', 'arguments' => (object) []]]); self::fail('Stored discovery authorized a call'); }
        catch (SandboxException $e) { self::assertSame('tool_denied', $e->reason); }
    }

    public function testVisibilityFailuresAreClosedAndDoNotDiscloseExceptions(): void
    {
        [$catalog, $broker, $policy] = $this->fixtures();
        $session = new BatchSession($catalog, $broker, new ExecutionIdentity('reader'), visibility: $policy);
        $session->describe(['public']); $policy->fail = true;
        self::assertSame(0, $session->searchContracts('')['matched']);
        try { $session->execute([['name' => 'public', 'arguments' => (object) []]]); self::fail('Policy failure authorized call'); }
        catch (SandboxException $e) { self::assertSame('tool_denied', $e->reason); self::assertStringNotContainsString('Private policy', $e->getMessage()); }
    }

    public function testVisibilityNeverReplacesResourceAuthorization(): void
    {
        [$catalog, , $policy] = $this->fixtures();
        $invoked = false;
        $broker = new ToolBroker([new Tool('public', $catalog->all()[0]->inputSchema, static fn () => false, function () use (&$invoked) { $invoked = true; })]);
        $session = new BatchSession($catalog, $broker, new ExecutionIdentity('reader'), visibility: $policy);
        $session->searchContracts('public');
        try { $session->execute([['name' => 'public', 'arguments' => (object) []]]); self::fail('Visibility granted access'); }
        catch (SandboxException $e) { self::assertSame('tool_denied', $e->reason); }
        self::assertFalse($invoked);
    }

    public function testPermissionCanBeRevokedBetweenCallsInOneBatch(): void
    {
        [$catalog, , $policy] = $this->fixtures();
        $privateInvoked = false;
        $schema = $catalog->all()[0]->inputSchema;
        $broker = new ToolBroker([
            new Tool('public', $schema, static fn () => true, static function () use ($policy) { $policy->revoked = true; return 'allowed'; }),
            new Tool('private', $schema, static fn () => true, function () use (&$privateInvoked) { $privateInvoked = true; }),
        ]);
        $session = new BatchSession($catalog, $broker, new ExecutionIdentity('editor', ['private.read']), visibility: $policy);
        $session->searchContracts('');
        try { $session->execute([['name' => 'public', 'arguments' => (object) []], ['name' => 'private', 'arguments' => (object) []]]); self::fail('Revocation was ignored inside a batch'); }
        catch (SandboxException $e) { self::assertSame('tool_denied', $e->reason); self::assertSame(['succeeded', 'denied'], array_column($e->toolCalls, 'status')); }
        self::assertFalse($privateInvoked);
        self::assertSame(2, $session->state()['toolCalls']);
    }

    public function testHiddenNamesAreRemovedBeforeSearchLimitsAndCounts(): void
    {
        [$catalog, , $policy] = $this->fixtures();
        $definitions = $catalog->all();
        for ($i = 0; $i < 12; ++$i) { $definitions[] = new ToolDefinition('private'.$i, 'Private capability', $definitions[0]->inputSchema); }
        $visible = (new ToolCatalog($definitions))->visibleTo(new ExecutionIdentity('reader'), $policy);
        self::assertSame(1, $visible->searchContracts('')['matched']);
        self::assertSame(1, $visible->searchResult('')['matched']);
        self::assertFalse($visible->searchContracts('')['truncated']);
    }

    public function testPhpProxyDoesNotExposeHiddenProviderMethods(): void
    {
        $schema = (object) ['type' => 'object'];
        $catalog = new ToolCatalog([new ToolDefinition('docs.published', 'Read published', $schema), new ToolDefinition('docs.restricted', 'Read restricted', $schema)]);
        $php = new \CodeMode\Provider\PhpToolInterface(['docs' => ['published' => 'docs.published', 'restricted' => 'docs.restricted']], $catalog);
        $program = $php->wrap('return null;', ['docs.published']);
        self::assertStringContainsString('docs.published', $program);
        self::assertStringNotContainsString('docs.restricted', $program);
        self::assertStringNotContainsString("'docs'", $php->wrap('return null;', []));
    }

    public function testSessionVisibilityCannotWeakenExistingBrokerPolicy(): void
    {
        [$catalog, , $allow] = $this->fixtures();
        $deny = new class implements ToolVisibilityInterface {
            public function isVisible(ExecutionIdentity $identity, string $tool): bool { return false; }
        };
        $broker = new ToolBroker([new Tool('public', $catalog->all()[0]->inputSchema, static fn () => true, static fn () => 'should not run')], $deny);
        try { $broker->withVisibility($allow)->call('public', (object) [], new ExecutionIdentity('reader'), ['public']); self::fail('Existing policy was replaced'); }
        catch (SandboxException $e) { self::assertSame('tool_denied', $e->reason); }
    }

    public function testPhpSessionUsesSameVisibilityAndRechecksBeforeBrokerCall(): void
    {
        [$catalog, $broker, $policy] = $this->fixtures();
        $sandbox = new class implements SandboxExecutor {
            public function execute(string $code, ToolBroker $broker, ExecutionIdentity $identity, array $allowedTools = [], ?ExecutionLimits $limits = null): ExecutionResult
            {
                $broker->call('public', (object) [], $identity, $allowedTools);
                throw new \LogicException('The revoked handler must not run.');
            }
        };
        $session = new CodeModeSession($catalog, $broker, $sandbox, new ExecutionIdentity('reader'), visibility: $policy);
        self::assertSame(['public'], array_column($session->search(''), 'name'));
        self::assertSame(1, $session->searchResult('')['matched']);
        $session->describe(['public']); $policy->revoked = true;
        try { $session->execute('return null;'); self::fail('Revoked PHP call executed'); }
        catch (SandboxException $e) { self::assertSame('tool_denied', $e->reason); }
    }
}
