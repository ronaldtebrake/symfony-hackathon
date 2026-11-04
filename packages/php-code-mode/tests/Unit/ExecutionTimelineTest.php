<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\ExecutionTimeline;
use PHPUnit\Framework\TestCase;

final class ExecutionTimelineTest extends TestCase
{
    public function testNestedSpansAreMonotonicBoundedAndCannotBeRewritten(): void
    {
        $trace = new ExecutionTimeline();
        $outer = $trace->begin('execution', 'PHP');
        $inner = $trace->begin('broker', 'read');
        $trace->end($inner, 'denied'); $trace->end($outer, 'failed');
        $trace->end($inner, 'succeeded');
        $spans = $trace->snapshot();
        self::assertLessThanOrEqual($spans[1]['startMs'], $spans[0]['startMs']);
        self::assertLessThanOrEqual($spans[0]['endMs'], $spans[1]['endMs']);
        self::assertSame('denied', $spans[1]['status']);
        for ($i = 0; $i < 600; ++$i) { $trace->end($trace->begin('model', 'Request')); }
        self::assertCount(512, $trace->snapshot());
        self::assertNull($trace->begin('model', 'Too many'));
    }
}
