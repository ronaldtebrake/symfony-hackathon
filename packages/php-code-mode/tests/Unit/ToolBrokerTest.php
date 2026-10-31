<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\ExecutionIdentity;
use CodeMode\SandboxException;
use CodeMode\Tool;
use CodeMode\ToolBroker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolBrokerTest extends TestCase
{
    #[DataProvider('deniedCalls')]
    public function testDeniedCallsNeverInvokeHandler(string $name, array $permissions, array $allowlist, string $arguments, string $reason): void
    {
        $called = false;
        $broker = new ToolBroker([new Tool('articles.read', json_decode('{"type":"object","properties":{"id":{"type":"integer","minimum":1}},"required":["id"],"additionalProperties":false}'),
            static fn (ExecutionIdentity $identity, \stdClass $args): bool => $identity->allows('read') && ($args->id ?? null) !== 99,
            static function () use (&$called): void { $called = true; },
        )]);
        try {
            $broker->call($name, json_decode($arguments), new ExecutionIdentity('alice', $permissions), $allowlist);
            self::fail('The broker accepted a denied call.');
        } catch (SandboxException $e) {
            self::assertSame($reason, $e->reason);
        }
        self::assertFalse($called);
    }

    public static function deniedCalls(): iterable
    {
        yield 'unknown tool' => ['system', ['read'], ['system'], '{"id":1}', 'tool_denied'];
        yield 'not allowed this run' => ['articles.read', ['read'], [], '{"id":1}', 'tool_denied'];
        yield 'wrong identity permission' => ['articles.read', [], ['articles.read'], '{"id":1}', 'tool_denied'];
        yield 'object access denied' => ['articles.read', ['read'], ['articles.read'], '{"id":99}', 'tool_denied'];
        yield 'type mismatch' => ['articles.read', ['read'], ['articles.read'], '{"id":"1"}', 'invalid_arguments'];
        yield 'forged identity' => ['articles.read', ['read'], ['articles.read'], '{"id":1,"identity":"admin"}', 'invalid_arguments'];
        yield 'schema bound' => ['articles.read', ['read'], ['articles.read'], '{"id":0}', 'invalid_arguments'];
    }

    public function testHostIdentityReachesHandlerAndExceptionDetailsStayPrivate(): void
    {
        $identity = new ExecutionIdentity('alice');
        $broker = new ToolBroker([new Tool('fail', (object) ['type' => 'object'],
            static fn (): bool => true,
            static function (\stdClass $args, ExecutionIdentity $actual) use ($identity): never {
                self::assertSame($identity, $actual);
                throw new \RuntimeException('PRIVATE_TOKEN=host-secret');
            },
        )]);
        try {
            $broker->call('fail', new \stdClass(), $identity, ['fail']);
            self::fail('The handler must fail.');
        } catch (SandboxException $e) {
            self::assertSame('tool_failed', $e->reason);
            self::assertStringNotContainsString('host-secret', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }
}
