<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\Protocol;
use CodeMode\SandboxException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtocolTest extends TestCase
{
    #[DataProvider('invalidFrames')]
    public function testMalformedOrForgedMessagesAreRejected(string $frame): void
    {
        $this->expectException(SandboxException::class);
        Protocol::decode($frame, 1);
    }

    public static function invalidFrames(): iterable
    {
        foreach (['[]', 'null', '{', '{"type":"shell","command":"id"}',
            '{"type":"tool_call","id":0,"name":"safe","arguments":{}}',
            '{"type":"tool_call","id":"1","name":"safe","arguments":{}}',
            '{"type":"tool_call","id":1,"name":"safe","arguments":[]}',
            '{"type":"tool_call","id":1,"name":"safe","arguments":{},"identity":"admin"}',
            '{"type":"tool_call","id":1,"name":"safe; id","arguments":{}}',
            '{"type":"result","value":42,"toolCalls":["forged"]}',
            '{"type":"result"}', str_repeat('[', 40).'0'.str_repeat(']', 40),
        ] as $frame) {
            yield [$frame];
        }
    }
}
