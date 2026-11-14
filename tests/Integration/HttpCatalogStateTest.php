<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use CodeMode\BatchSession;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\SandboxException;
use CodeMode\Symfony\Http\CatalogStateStore;
use CodeMode\Tool;
use CodeMode\ToolBroker;
use CodeMode\ToolCatalog;
use CodeMode\ToolDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class HttpCatalogStateTest extends TestCase
{
    public function testRequestsCannotRaceOrRefundReservedCallsAfterFailure(): void
    {
        $directory = sys_get_temp_dir().'/http-catalog-'.bin2hex(random_bytes(8));
        $store = $this->store($directory);
        $other = $this->store($directory);
        $schema = (object) ['type' => 'object', 'additionalProperties' => false];
        $catalog = new ToolCatalog([new ToolDefinition('read', 'Read', $schema)]);
        $invoked = 0;
        $broker = new ToolBroker([new Tool('read', $schema, static fn () => true, static function () use (&$invoked, $other): int {
            ++$invoked;
            try {
                $other->withState('reader', static fn () => self::fail('Concurrent request acquired the same user budget.'));
            } catch (SandboxException $e) {
                self::assertSame('execution_busy', $e->reason);
            }
            throw new \RuntimeException('Private database failure.');
        })]);
        $session = static fn ($state, $save) => new BatchSession($catalog, $broker, new ExecutionIdentity('reader'), new ExecutionLimits(maxToolCalls: 2), maxSessionToolCalls: 2, state: $state, checkpoint: $save);
        try {
            $store->withState('reader', static fn ($state, $save) => $session($state, $save)->searchContracts(''));
            $read = ['name' => 'read', 'arguments' => new \stdClass()];
            try {
                $store->withState('reader', static fn ($state, $save) => $session($state, $save)->execute([$read, $read]));
            } catch (SandboxException $e) {
                $this->assertSame('tool_failed', $e->reason);
            }
            $this->assertSame(1, $invoked, 'Stop on the first failed handler.');
            // A new store instance (as in the next HTTP request) sees pre-dispatch reservations.
            $other->withState('reader', static function ($state) {
                self::assertSame(['described' => ['read'], 'executions' => 1, 'toolCalls' => 2], $state);
            });
            try {
                $other->withState('reader', static fn ($state, $save) => $session($state, $save)->execute([$read]));
                $this->fail('Failed calls refunded the reserved budget.');
            } catch (SandboxException $e) {
                $this->assertSame('tool_call_limit', $e->reason);
            }
            $this->assertSame(1, $invoked, 'An exhausted call budget cannot invoke another handler.');
            $other->withState('another-reader', fn ($state) => $this->assertSame([], $state));
            $other->reset('reader');
            $store->withState('reader', fn ($state) => $this->assertSame([], $state));
        } finally {
            (new Filesystem())->remove($directory);
        }
    }

    public function testFailureToPersistReservationPreventsInvocation(): void
    {
        $schema = (object) ['type' => 'object'];
        $invoked = false;
        $session = new BatchSession(new ToolCatalog([new ToolDefinition('read', 'Read', $schema)]),
            new ToolBroker([new Tool('read', $schema, static fn () => true, static function () use (&$invoked) { $invoked = true; })]),
            new ExecutionIdentity('reader'), state: ['described' => ['read'], 'executions' => 0, 'toolCalls' => 0],
            checkpoint: static fn () => throw new SandboxException('state_unavailable', 'Cannot reserve.'));
        try {
            $session->execute([['name' => 'read', 'arguments' => new \stdClass()]]);
            $this->fail('Persistence failure must stop execution.');
        } catch (SandboxException $e) {
            $this->assertSame('state_unavailable', $e->reason);
        }
        $this->assertFalse($invoked);
    }

    private function store(string $directory): CatalogStateStore
    {
        return new CatalogStateStore(new FilesystemAdapter('catalog_test', 0, $directory.'/cache'), new LockFactory(new FlockStore($directory.'/locks')));
    }
}
