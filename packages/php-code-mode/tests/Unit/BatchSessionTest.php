<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\{BatchSession, BatchResult, ExecutionIdentity, ExecutionLimits, SandboxException, ToolDefinition};
use CodeMode\Provider\{ProviderRegistry, ToolBinding, ToolProviderInterface};
use PHPUnit\Framework\TestCase;

final class BatchSessionTest extends TestCase
{
    private function session(?\Closure $invoke = null, ?ExecutionLimits $limits = null, ?\Closure $authorize = null, bool $describe = true): BatchSession
    {
        $provider = new class($invoke, $authorize) implements ToolProviderInterface {
            public function __construct(private ?\Closure $invoke, private ?\Closure $authorize) {}
            public function name(): string { return 'content'; }
            public function source(): string { return 'test'; }
            public function tools(): iterable
            {
                $schema = json_decode('{"type":"object","properties":{"id":{"type":"integer"}},"required":["id"],"additionalProperties":false}');
                foreach (['read', 'other'] as $name) {
                    yield new ToolBinding($name, new ToolDefinition('upstream_'.$name, 'Read an approved record.', $schema),
                        $this->authorize ?? static fn ($identity, $args) => $identity->subject === 'reader' && $identity->allows('read') && ($args->id ?? null) !== 9,
                        $this->invoke ?? static fn ($args) => ['id' => $args->id]);
                }
            }
        };
        $registry = new ProviderRegistry([$provider]);
        $session = new BatchSession($registry->catalog(), $registry->broker(), new ExecutionIdentity('reader', ['read']), $limits ?? new ExecutionLimits());
        if ($describe) { $session->describe(['content.read']); }
        return $session;
    }

    private function call(mixed $id = 1, string $name = 'content.read'): array { return ['name' => $name, 'arguments' => ['id' => $id]]; }

    private function fails(BatchSession $session, array $calls, string $reason): SandboxException
    {
        try { $session->execute($calls); self::fail('Batch must fail.'); }
        catch (SandboxException $error) { self::assertSame($reason, $error->reason); return $error; }
    }

    public function testLiteralBatchPreservesOrderAndHostEvidenceWithoutExecutingCode(): void
    {
        $session = $this->session();
        $records = [];
        $result = $session->respond([$this->call(3), $this->call(1)], static function ($calls, $trace) use (&$records) { $records[] = $trace; });
        self::assertSame([3, 1], array_map(static fn ($row) => $row['value']->id, $result['results']));
        self::assertSame(['results'], array_keys($result));
        self::assertSame(['succeeded', 'succeeded'], array_column($records[0]['toolCalls'], 'status'));
        self::assertSame(false, $records[0]['runtime']['generatedCode']);
        self::assertSame(strlen(json_encode($result, JSON_THROW_ON_ERROR)), $records[0]['outputBytes']);
        self::assertSame(['tools_search', 'tools_describe', 'tools_execute'], array_column($session->toolDefinitions(), 'name'));
        self::assertArrayNotHasKey('php', $session->describe(['content.read'])[0]);
    }

    public function testDiscoveryDoesNotAuthorizeOtherNamesAndBudgetsSurviveRecovery(): void
    {
        $session = $this->session(describe: false);
        $session->searchResult('read');
        $this->fails($session, [$this->call()], 'discovery_required');
        $session->describe(['content.read']);
        $error = $this->fails($session, [$this->call(9)], 'tool_denied');
        self::assertSame(['error' => ['reason' => 'tool_denied', 'tool' => 'content.read', 'index' => 0], 'completedCalls' => 0], BatchResult::failure($error));
        self::assertSame(1, $session->execute([$this->call()])->results[0]['value']->id);
        $this->fails($session, [$this->call()], 'execution_limit');
        foreach (['content.other', 'upstream_read', 'system'] as $name) { $this->fails($this->session(), [$this->call(1, $name)], 'tool_denied'); }
    }

    public function testEveryCallRechecksPolicyAndStopsWithoutInvokingLaterCalls(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'batch-calls-');
        try {
            $session = $this->session(invoke: static function ($args) use ($path) { file_put_contents($path, $args->id."\n", FILE_APPEND); return $args; });
            $error = $this->fails($session, [$this->call(1), $this->call(9), $this->call(2)], 'tool_denied');
            self::assertSame("1\n", file_get_contents($path));
            self::assertSame(['succeeded', 'denied'], array_column($error->toolCalls, 'status'));
            self::assertSame(1, BatchResult::failure($error)['completedCalls']);
            // A permission change made after discovery is visible at execution.
            $session = $this->session(authorize: static fn () => file_get_contents($path) === 'allowed');
            file_put_contents($path, 'denied');
            $this->fails($session, [$this->call()], 'tool_denied');
            file_put_contents($path, 'allowed');
            self::assertSame(1, $session->execute([$this->call()])->results[0]['value']->id);
        } finally { unlink($path); }
    }

    public function testEnvelopePreflightAndArgumentSchemasRejectCapabilityInjection(): void
    {
        foreach ([
            [$this->call(), $this->call() + ['identity' => 'admin']],
            [['name' => 'content.read', 'arguments' => [1]]],
            [['name' => 'content.read', 'arguments' => ['id' => 1], 'code' => 'system(1)']],
        ] as $calls) {
            $error = $this->fails($this->session(), $calls, 'invalid_arguments');
            self::assertSame([], $error->toolCalls);
        }
        foreach (['1', ['ref' => 'previous.result.id'], null] as $id) {
            $this->fails($this->session(), [$this->call($id)], 'invalid_arguments');
        }
        $this->fails($this->session(), [['name' => 'content.read', 'arguments' => ['id' => 1, 'identity' => 'admin']]], 'invalid_arguments');
        $this->fails($this->session(limits: new ExecutionLimits(maxToolCalls: 1)), [$this->call(), $this->call()], 'tool_call_limit');
        $this->fails($this->session(), [], 'tool_call_limit');
        $this->fails($this->session(), [$this->call(str_repeat('x', 33000))], 'input_limit');
        $this->fails($this->session(limits: new ExecutionLimits(maxFrameBytes: 256)), [$this->call(str_repeat('x', 300))], 'frame_limit');
    }

    public function testPerResultAndAggregateOutputBoundsDoNotPretendToRollBackReads(): void
    {
        $this->fails($this->session(invoke: static fn () => str_repeat('x', 500), limits: new ExecutionLimits(maxToolResultBytes: 256)), [$this->call()], 'tool_result_limit');
        $error = $this->fails($this->session(invoke: static fn () => str_repeat('x', 150), limits: new ExecutionLimits(maxFrameBytes: 256, maxOutputBytes: 256)), [$this->call(), $this->call()], 'output_limit');
        self::assertCount(2, $error->toolCalls);
        self::assertSame(2, BatchResult::failure($error)['completedCalls']);
    }

    public function testCooperativeDeadlineWaitsForReturnAndNeverStartsTheNextCall(): void
    {
        $calls = 0; $finished = false;
        $session = $this->session(invoke: static function () use (&$calls, &$finished) {
            ++$calls; usleep(150000); $finished = true; return 1;
        }, limits: new ExecutionLimits(wallTimeMs: 500, toolTimeMs: 100));
        $this->fails($session, [$this->call(), $this->call()], 'tool_deadline_exceeded');
        self::assertTrue($finished, 'An in-process deadline cannot interrupt a handler.');
        self::assertSame(1, $calls);
        self::assertFalse(BatchResult::runtime()['hardTermination']);
    }

    public function testAggregateDeadlineIncludesAllCalls(): void
    {
        $error = $this->fails($this->session(invoke: static function () { usleep(180000); return 1; }, limits: new ExecutionLimits(wallTimeMs: 300, toolTimeMs: 250)), [$this->call(), $this->call()], 'tool_deadline_exceeded');
        self::assertSame(['succeeded', 'incomplete'], array_column($error->toolCalls, 'status'));
    }

    public function testErrorsAndReturnedDataCannotForgeExecutionMetadata(): void
    {
        $error = $this->fails($this->session(invoke: static fn () => throw new \RuntimeException('SECRET credential')), [$this->call()], 'tool_failed');
        self::assertStringNotContainsString('SECRET', json_encode(BatchResult::failureTrace($error)));
        $result = $this->session(invoke: static fn () => ['runId' => 'forged', 'runtime' => 'gvisor'])->execute([$this->call()]);
        self::assertStringStartsWith('batch-', $result->trace()['runId']);
        self::assertSame('json-batch', $result->trace()['runtime']['executor']);
        self::assertSame('forged', $result->results[0]['value']->runId);
    }

    public function testLongerOperatorConfiguredConversationsKeepATotalUnderlyingCallBudget(): void
    {
        $schema = json_decode('{"type":"object","properties":{},"additionalProperties":false}');
        $catalog = new \CodeMode\ToolCatalog([new ToolDefinition('read', 'Read', $schema)]);
        $broker = new \CodeMode\ToolBroker([new \CodeMode\Tool('read', $schema, static fn () => true, static fn () => 1)]);
        $session = new BatchSession($catalog, $broker, new ExecutionIdentity('reader'), new ExecutionLimits(maxToolCalls: 2), maxBatches: 14, maxSessionToolCalls: 3);
        $session->describe(['read']);
        $read = ['name' => 'read', 'arguments' => new \stdClass()];
        self::assertCount(2, $session->execute([$read, $read])->results);
        $this->fails($session, [$read, $read], 'tool_call_limit');
        self::assertCount(1, $session->execute([$read])->results);
        $this->fails($session, [$read], 'tool_call_limit');
    }

    public function testCatalogSearchDisclosesContractsWithoutGrantingPermissions(): void
    {
        $session = $this->session(describe: false);
        $before = json_encode($session->catalogToolDefinitions());
        self::assertSame(['search_tools', 'execute_tools'], array_column($session->catalogToolDefinitions(), 'name'));
        $found = $session->searchContracts('content.read');
        self::assertSame(['id'], $found['tools'][0]['inputSchema']->required);
        self::assertFalse($found['truncated']);
        $this->fails($session, [$this->call(9)], 'tool_denied');
        self::assertSame(1, $session->execute([$this->call()])->results[0]['value']->id);
        $this->fails($session, [$this->call()], 'execution_limit');
        self::assertSame($before, json_encode($session->catalogToolDefinitions()), 'The advertised surface stays fixed.');
        $unseen = $this->session(describe: false); $unseen->searchContracts('content.read');
        $this->fails($unseen, [$this->call(1, 'content.other')], 'tool_denied');
    }

    public function testCatalogMatchesSchemaFieldsAndReportsBoundedFullContracts(): void
    {
        $schema = static fn ($field) => json_decode(json_encode(['type' => 'object', 'properties' => [$field => ['type' => 'string']], 'additionalProperties' => false]));
        $definitions = [new ToolDefinition('read_a', 'Read a record.', $schema('rainfall_mm'))];
        for ($i = 0; $i < 9; ++$i) { $definitions[] = new ToolDefinition('read_'.$i, 'Read a record.', $schema('id')); }
        $catalog = new \CodeMode\ToolCatalog($definitions);
        self::assertSame(['read_a'], array_column($catalog->searchContracts('rainfall')['tools'], 'name'));
        $result = $catalog->searchContracts('');
        self::assertSame(10, $result['matched']); self::assertSame(8, $result['returned']); self::assertTrue($result['truncated']);
        self::assertCount(1, $catalog->searchContracts('read_8')['tools']);
        $largeSchema = $schema('id'); $largeSchema->description = str_repeat('x', 31000);
        $huge = new \CodeMode\ToolCatalog([new ToolDefinition('huge', str_repeat('x', 1500), $largeSchema)]);
        $this->expectException(SandboxException::class);
        $huge->searchContracts('');
    }
}
