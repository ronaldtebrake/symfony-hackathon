<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\HintEvaluation\Study;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;

final class PermissionHintStudyTest extends TestCase
{
    public function testFreshSystemHintDisappearsWhileTheOriginalConversationAndReadPolicyRemain(): void
    {
        $read = ['calls' => [['name' => 'publication_read', 'arguments' => ['id' => 101]]]];
        $responses = [
            new ToolCallResult([new ToolCall('search', 'symfony_search_tools', ['query' => 'publication'])]),
            new ToolCallResult([new ToolCall('read', 'symfony_execute_tools', $read)]),
            json_encode(['status' => 'answered', 'answer' => Study::TITLE]),
            new ToolCallResult([new ToolCall('deny', 'symfony_execute_tools', $read)]),
            new ToolCallResult([new ToolCall('hidden', 'symfony_search_tools', ['query' => 'publication'])]),
            '{"status":"denied","answer":null,"contractVisible":false}',
        ];
        $platform = MockPlatformFactory::createPlatform(static function () use (&$responses) {
            return array_shift($responses) ?? throw new \LogicException('Unexpected extra request.');
        });
        $cell = (new Study())->runCell(\dirname(__DIR__, 2), '', 'hint', 'direct', static function (): void {}, $platform);
        $this->assertSame('complete', $cell['status'], json_encode($cell));
        $first = $cell['turns']['direct'];
        $second = $cell['turns']['revoked'];
        $this->assertEqualsCanonicalizing(['symfony_search_tools', 'symfony_execute_tools'], $cell['modelToolNames']);
        $this->assertSame(['search_tools', 'execute_tools'], array_column($first['calls'], 'name'));
        $this->assertSame(['execute_tools', 'search_tools'], array_column($second['calls'], 'name'));
        $this->assertSame([Study::HINT], $first['injectedHints']);
        $this->assertSame([], $second['observedHints']);
        $this->assertSame([], $second['injectedHints']);
        $this->assertTrue($first['score']['correct']);
        $this->assertTrue($second['score']['correct']);
        $this->assertNull($second['tokens']);
        $this->assertSame([hash('sha256', Study::PROMPT)], array_values(array_unique(array_column($second['requestEvidence'], 'systemPromptSha256'))));
        $this->assertCount(2, $second['requestEvidence'][0]['userMessageSha256']);
        $this->assertFalse(Study::score('direct', $first['answer'], [])['correct']);
        $reordered = $first['calls'];
        $reordered[1]['arguments']['calls'][0] = ['arguments' => ['id' => 101], 'name' => 'publication_read'];
        $this->assertTrue(Study::score('direct', $first['answer'], $reordered)['correct']);
        $reordered[1]['arguments']['calls'][0]['arguments']['id'] = '101';
        $this->assertFalse(Study::score('direct', $first['answer'], $reordered)['correct']);
        $this->assertFalse(Study::score('revoked', $second['answer'], [$second['calls'][0]])['correct']);
        $this->assertFalse(Study::score('revoked', '{"status":"denied","answer":null,"contractVisible":true}', $second['calls'], $first['calls'])['correct']);
        $irrelevant = $second['calls'];
        $irrelevant[1]['arguments']['query'] = 'bananas';
        $this->assertFalse(Study::score('revoked', $second['answer'], $irrelevant, $first['calls'])['correct']);
        foreach (['', 'publication_read'] as $query) {
            $irrelevant[1]['arguments']['query'] = $query;
            $this->assertTrue(Study::score('revoked', $second['answer'], $irrelevant)['correct']);
        }
        $this->assertNull(Study::tokens([['tokens' => ['prompt' => 5, 'completion' => 1, 'total' => 5]]], 1));
    }
}
