<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\BatchSession;
use CodeMode\CodeModeSession;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\ExecutionResult;
use CodeMode\SandboxException;
use CodeMode\SandboxExecutor;
use CodeMode\Tool;
use CodeMode\ToolBroker;
use CodeMode\ToolCatalog;
use CodeMode\ToolDefinition;
use CodeMode\ToolVisibilityInterface;
use PHPUnit\Framework\TestCase;

final class ToolVisibilityTest extends TestCase
{
    public function testDiscoveryFiltersBeforeCountingAndDoesNotLeakAcrossUsers(): void
    {
        [$catalog, $broker, $policy] = $this->fixtures();
        $reader = new BatchSession($catalog, $broker, new ExecutionIdentity('reader'), visibility: $policy);
        $editor = new BatchSession($catalog, $broker, new ExecutionIdentity('editor', ['private.read']), visibility: $policy);
        $this->assertSame(2, $editor->searchContracts('')['matched']);
        foreach ([$reader->searchContracts(''), $reader->searchResult('')] as $result) {
            $this->assertSame(1, $result['matched']);
            $this->assertSame(['public'], array_column($result['tools'], 'name'));
            $this->assertFalse($result['truncated']);
        }
        $this->assertSame(0, $reader->searchContracts('private')['matched']);
        $this->assertSame([], $reader->searchContracts('private')['tools']);
        $this->expectException(SandboxException::class);
        $reader->describe(['private']);
    }

    public function testRevocationBlocksPreviouslyDiscoveredToolAndRetainsBudget(): void
    {
        [$catalog, $broker, $policy] = $this->fixtures();
        $session = new BatchSession($catalog, $broker, new ExecutionIdentity('editor', ['private.read']), visibility: $policy);
        $session->searchContracts('private');
        $policy->revoked = true;
        $this->assertSame(0, $session->searchContracts('')['matched']);
        try {
            $session->execute([['name' => 'private', 'arguments' => (object) []]]);
            $this->fail('Revoked tool executed');
        } catch (SandboxException $e) {
            $this->assertSame('tool_denied', $e->reason);
        }
        $this->assertSame(1, $session->state()['executions']);
        $this->assertSame(1, $session->state()['toolCalls']);
        // Rehydrated discovery is not a permission grant either.
        $next = new BatchSession($catalog, $broker, new ExecutionIdentity('editor'), state: $session->state(), visibility: $policy);
        try {
            $next->execute([['name' => 'private', 'arguments' => (object) []]]);
            $this->fail('Stored discovery authorized a call');
        } catch (SandboxException $e) {
            $this->assertSame('tool_denied', $e->reason);
        }
    }

    public function testVisibilityFailuresAreClosedAndDoNotDiscloseExceptions(): void
    {
        [$catalog, $broker, $policy] = $this->fixtures();
        $session = new BatchSession($catalog, $broker, new ExecutionIdentity('reader'), visibility: $policy);
        $session->describe(['public']);
        $policy->fail = true;
        $this->assertSame(0, $session->searchContracts('')['matched']);
        try {
            $session->execute([['name' => 'public', 'arguments' => (object) []]]);
            $this->fail('Policy failure authorized call');
        } catch (SandboxException $e) {
            $this->assertSame('tool_denied', $e->reason);
            $this->assertStringNotContainsString('Private policy', $e->getMessage());
        }
    }

    public function testVisibilityNeverReplacesResourceAuthorization(): void
    {
        [$catalog, , $policy] = $this->fixtures();
        $invoked = false;
        $broker = new ToolBroker([new Tool('public', $catalog->all()[0]->inputSchema, static fn () => false, static function () use (&$invoked) { $invoked = true; })]);
        $session = new BatchSession($catalog, $broker, new ExecutionIdentity('reader'), visibility: $policy);
        $session->searchContracts('public');
        try {
            $session->execute([['name' => 'public', 'arguments' => (object) []]]);
            $this->fail('Visibility granted access');
        } catch (SandboxException $e) {
            $this->assertSame('tool_denied', $e->reason);
        }
        $this->assertFalse($invoked);
    }

    public function testPermissionCanBeRevokedBetweenCallsInOneBatch(): void
    {
        [$catalog, , $policy] = $this->fixtures();
        $privateInvoked = false;
        $schema = $catalog->all()[0]->inputSchema;
        $broker = new ToolBroker([
            new Tool('public', $schema, static fn () => true, static function () use ($policy) {
                $policy->revoked = true;

                return 'allowed';
            }),
            new Tool('private', $schema, static fn () => true, static function () use (&$privateInvoked) { $privateInvoked = true; }),
        ]);
        $session = new BatchSession($catalog, $broker, new ExecutionIdentity('editor', ['private.read']), visibility: $policy);
        $session->searchContracts('');
        try {
            $session->execute([['name' => 'public', 'arguments' => (object) []], ['name' => 'private', 'arguments' => (object) []]]);
            $this->fail('Revocation was ignored inside a batch');
        } catch (SandboxException $e) {
            $this->assertSame('tool_denied', $e->reason);
            $this->assertSame(['succeeded', 'denied'], array_column($e->toolCalls, 'status'));
        }
        $this->assertFalse($privateInvoked);
        $this->assertSame(2, $session->state()['toolCalls']);
    }

    public function testHiddenNamesAreRemovedBeforeSearchLimitsAndCounts(): void
    {
        [$catalog, , $policy] = $this->fixtures();
        $definitions = $catalog->all();
        for ($i = 0; $i < 12; ++$i) {
            $definitions[] = new ToolDefinition('private'.$i, 'Private capability', $definitions[0]->inputSchema);
        }
        $visible = (new ToolCatalog($definitions))->visibleTo(new ExecutionIdentity('reader'), $policy);
        $this->assertSame(1, $visible->searchContracts('')['matched']);
        $this->assertSame(1, $visible->searchResult('')['matched']);
        $this->assertFalse($visible->searchContracts('')['truncated']);
    }

    public function testPhpProxyDoesNotExposeHiddenProviderMethods(): void
    {
        $schema = (object) ['type' => 'object'];
        $catalog = new ToolCatalog([new ToolDefinition('docs.published', 'Read published', $schema), new ToolDefinition('docs.restricted', 'Read restricted', $schema)]);
        $php = new \CodeMode\Provider\PhpToolInterface(['docs' => ['published' => 'docs.published', 'restricted' => 'docs.restricted']], $catalog);
        $program = $php->wrap('return null;', ['docs.published']);
        $this->assertStringContainsString('docs.published', $program);
        $this->assertStringNotContainsString('docs.restricted', $program);
        $this->assertStringNotContainsString("'docs'", $php->wrap('return null;', []));
    }

    public function testSessionVisibilityCannotWeakenExistingBrokerPolicy(): void
    {
        [$catalog, , $allow] = $this->fixtures();
        $deny = new class implements ToolVisibilityInterface {
            public function isVisible(ExecutionIdentity $identity, string $tool): bool
            {
                return false;
            }
        };
        $broker = new ToolBroker([new Tool('public', $catalog->all()[0]->inputSchema, static fn () => true, static fn () => 'should not run')], $deny);
        try {
            $broker->withVisibility($allow)->call('public', (object) [], new ExecutionIdentity('reader'), ['public']);
            $this->fail('Existing policy was replaced');
        } catch (SandboxException $e) {
            $this->assertSame('tool_denied', $e->reason);
        }
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
        $this->assertSame(['public'], array_column($session->search(''), 'name'));
        $this->assertSame(1, $session->searchResult('')['matched']);
        $session->describe(['public']);
        $policy->revoked = true;
        try {
            $session->execute('return null;');
            $this->fail('Revoked PHP call executed');
        } catch (SandboxException $e) {
            $this->assertSame('tool_denied', $e->reason);
        }
    }

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
                if ($this->fail) {
                    throw new \RuntimeException('Private policy failure');
                }

                return !$this->revoked && ('public' === $tool || $identity->allows('private.read'));
            }
        };

        return [new ToolCatalog($definitions), new ToolBroker($handlers), $policy];
    }
}
