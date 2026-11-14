<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use CodeMode\Symfony\Profiler\CatalogDataCollector;
use CodeMode\Symfony\Profiler\CatalogTrace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CatalogProfilerTest extends TestCase
{
    public function testOnlyBoundedMetadataSurvivesSerializationAndReset(): void
    {
        $trace = new CatalogTrace();
        $trace->begin('execute_tools');
        $trace->budget(['executions' => 1, 'toolCalls' => 1, 'described' => ['read'], 'identity' => 'SECRET_USER'], 'saved');
        $trace->ledger(['result' => 'SECRET_RESULT', 'toolCalls' => [['name' => 'read', 'status' => 'denied',
            'reason' => 'tool_denied', 'arguments' => ['id' => 'SECRET_ARGUMENT'], 'result' => 'SECRET_RESULT', 'durationMs' => 1.2]]]);
        $trace->nativeEvent('read', 'requested');
        $trace->finish(['error' => ['reason' => 'tool_denied', 'message' => 'SECRET_EXCEPTION']], true, 2.3);
        $collector = new CatalogDataCollector($trace);
        $collector->collect(Request::create('/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer SECRET_TOKEN']), new Response('SECRET_BODY'));
        $stored = serialize($collector);
        $this->assertStringNotContainsString('SECRET', $stored);
        $copy = unserialize($stored);
        $this->assertSame('tool_denied', $copy->getOperations()[0]['calls'][0]['reason']);
        $this->assertSame(['phase' => 'saved', 'attempts' => 1, 'reservedCalls' => 1, 'knownContracts' => 1], $copy->getOperations()[0]['budget'][0]);
        for ($i = 0; $i < 40; ++$i) {
            $trace->begin('search_tools');
            $trace->finish([], false, 0);
        }
        $this->assertCount(32, $trace->snapshot()['operations']);
        $this->assertSame(9, $trace->snapshot()['dropped']);
        $collector->reset();
        $this->assertSame([], $collector->getOperations());
        $this->assertSame(['operations' => [], 'dropped' => 0], $trace->snapshot());
    }
}
