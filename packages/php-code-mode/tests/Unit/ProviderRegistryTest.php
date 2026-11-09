<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\{CodeModeSession, ExecutionIdentity, ExecutionLimits, ExecutionResult, SandboxExecutor, SandboxException, ToolBroker, ToolDefinition};
use CodeMode\Provider\{ProviderRegistry, PhpSchemaType, ToolBinding, ToolProviderInterface};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    private function provider(string $namespace = 'catalog', string $method = 'read', ?\Closure $invoke = null): ToolProviderInterface
    {
        return new class($namespace, $method, $invoke) implements ToolProviderInterface {
            public function __construct(private string $namespace, private string $method, private ?\Closure $invoke) {}
            public function name(): string { return $this->namespace; }
            public function source(): string { return 'test'; }
            public function tools(): iterable {
                $schema = json_decode('{"type":"object","properties":{"id":{"type":"integer"}},"required":["id"],"additionalProperties":false}');
                yield new ToolBinding($this->method, new ToolDefinition('source_read', 'Read one.', $schema, $schema),
                    static fn ($identity, $args) => $identity->subject === 'reader' && $identity->allows('read') && ($args->id ?? null) !== 9,
                    $this->invoke ?? static fn ($args) => $args);
            }
        };
    }

    public function testNamespacedCallsKeepOriginalAuthorizationSchemasAndProvenance(): void
    {
        $called = false;
        $r = new ProviderRegistry([$this->provider(invoke: static function ($a) use (&$called) { $called = true; return $a; })]);
        $id = new ExecutionIdentity('reader', ['read']);
        self::assertSame(1, $r->broker()->call('catalog.read', (object) ['id' => 1], $id, ['catalog.read'])->id);
        self::assertTrue($called);
        self::assertSame(['provider' => 'catalog', 'method' => 'read', 'source' => 'test', 'upstreamTool' => 'source_read'], $r->origins()['catalog.read']);
        foreach ([['catalog.read', ['id'=>9], $id, ['catalog.read'], 'tool_denied'],
            ['catalog.read', ['id'=>1], new ExecutionIdentity('other', ['read']), ['catalog.read'], 'tool_denied'],
            ['catalog.read', ['id'=>'1'], $id, ['catalog.read'], 'invalid_arguments'],
            ['catalog.read', ['id'=>1, 'identity'=>'admin'], $id, ['catalog.read'], 'invalid_arguments'],
            ['source_read', ['id'=>1], $id, ['catalog.read'], 'tool_denied'],
            ['catalog.read', ['id'=>1], $id, [], 'tool_denied']] as [$name, $args, $identity, $allowed, $reason]) {
            $called = false;
            try { $r->broker()->call($name, (object) $args, $identity, $allowed); self::fail('Call must fail.'); }
            catch (SandboxException $e) { self::assertSame($reason, $e->reason); }
            self::assertFalse($called);
        }
    }

    public static function invalidNames(): iterable
    {
        foreach (['bad.name','__construct','class','call','x;exit','../tool','Upper',str_repeat('a',31)] as $name) { yield $name => [$name]; }
    }
    #[DataProvider('invalidNames')]
    public function testUnsafeAliasesAreRejectedBeforeTheyCanBecomePhp(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProviderRegistry([$this->provider(method: $name)]);
    }
    public function testNamespaceCollisionsCannotOverrideAProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProviderRegistry([$this->provider(), $this->provider()]);
    }
    public function testDiscoveryAndAttemptsRemainSessionOwned(): void
    {
        $r = new ProviderRegistry([$this->provider()]); $calls = 0;
        $executor = $this->createMock(SandboxExecutor::class);
        $executor->method('execute')->willReturnCallback(function ($code, $broker, $identity, $allowed, $limits) use (&$calls, $r) {
            ++$calls; self::assertSame(['catalog.read'], $allowed); self::assertInstanceOf(ExecutionLimits::class, $limits);
            self::assertSame($r->php()->wrap('return $tools->catalog->read(["id"=>1]);'), $code);
            return new ExecutionResult(1, [], 1, 0, 'test');
        });
        $session = new CodeModeSession($r->catalog(), $r->broker(), $executor, new ExecutionIdentity('reader', ['read']), phpInterface: $r->php());
        $program = 'return $tools->catalog->read(["id"=>1]);';
        try { $session->execute($program); self::fail(); } catch (SandboxException $e) { self::assertSame('discovery_required', $e->reason); }
        $docs = $session->describe(['catalog.read']);
        self::assertSame('$tools->catalog->read($arguments)', $docs[0]['phpInterface']['call']);
        self::assertSame(['id'], $docs[0]['inputSchema']->required);
        $session->execute($program); $session->describe(['catalog.read']); $session->execute($program);
        try { $session->execute($program); self::fail(); } catch (SandboxException $e) { self::assertSame('execution_limit', $e->reason); }
        self::assertSame(2, $calls);
    }
    public function testPhpTypesDoNotInventConstraintsOrEvaluateSchemaText(): void
    {
        $types = new PhpSchemaType();
        self::assertSame('mixed', $types->render(json_decode('{"$ref":"file:///etc/passwd"}')));
        self::assertSame('mixed', $types->render(json_decode('{"oneOf":[{"type":"integer"},{"type":"string"}]}')));
        self::assertSame('array<string, mixed>', $types->render(json_decode('{"type":"object","additionalProperties":false,"properties":{"*/ system(1); /*":{"type":"string"}}}'), true));
        $stubs = (new ProviderRegistry([$this->provider()]))->php()->stubs();
        self::assertStringContainsString('@param array{id: int}', $stubs);
        self::assertStringContainsString('@return object{id: int}', $stubs);
        self::assertStringContainsString('public function read(array $arguments = []): mixed;', $stubs);
    }
    public function testDirectInterfaceConstructionCannotRemapAnAliasToAnotherTool(): void
    {
        $registry = new ProviderRegistry([$this->provider()]);
        $this->expectException(\InvalidArgumentException::class);
        new \CodeMode\Provider\PhpToolInterface(['catalog'=>['read'=>'source_read']], $registry->catalog());
    }

    public function testGeneratedStubsAndGuestProxyAreValidPhpAndStayBounded(): void
    {
        $registry = new ProviderRegistry([$this->provider()]);
        foreach ([$registry->php()->stubs(), '<?php '.$registry->php()->wrap('return $tools->catalog->read(["id"=>1]);')] as $source) {
            self::assertNotEmpty(token_get_all($source, TOKEN_PARSE));
            self::assertLessThan(32768, strlen($source));
        }
        $props = new \stdClass();
        for ($i=0; $i<16; ++$i) { $props->{'property'.str_repeat('a',63).$i} = (object) ['type'=>'string']; }
        self::assertSame('object', (new PhpSchemaType())->render((object) ['type'=>'object','additionalProperties'=>false,'properties'=>$props]));
    }

    public function testProxyBytesCountAgainstTheExistingSourceLimitBeforeAllocation(): void
    {
        $registry = new ProviderRegistry([$this->provider()]);
        $session = new CodeModeSession($registry->catalog(), $registry->broker(), new \CodeMode\GvisorSandbox('sha256:'.str_repeat('a', 64)), new ExecutionIdentity('reader', ['read']), new ExecutionLimits(maxCodeBytes:64), phpInterface:$registry->php());
        $session->describe(['catalog.read']);
        try { $session->execute('return 1;'); self::fail('The wrapper escaped the source limit.'); }
        catch (SandboxException $error) { self::assertSame('code_limit', $error->reason); self::assertNull($error->runId); }
    }

}
