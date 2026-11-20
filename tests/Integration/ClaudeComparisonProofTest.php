<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\ClaudeComparisonProof;
use App\Demo\ClaudeUsage;
use PHPUnit\Framework\TestCase;

final class ClaudeComparisonProofTest extends TestCase
{
    public function testBothLiveAttemptsUseTheSameCatalogAndAnswerCorrectly(): void
    {
        $report = ClaudeComparisonProof::load(\dirname(__DIR__, 2));
        $this->assertCount(2, $report['attempts']);
        $this->assertTrue($report['pairs'][0]['matched']);
        $this->assertTrue($report['pairs'][0]['bothCorrect']);
        $this->assertSame([44131, 73251], array_column(array_column($report['attempts'], 'usage'), 'total'));
        $this->assertSame([7, 6], array_column(array_column($report['attempts'], 'usage'), 'requests'));
        $this->assertSame([1, 0], array_column(array_column($report['attempts'], 'usage'), 'duplicateRowsExcluded'));
        foreach ($report['attempts'] as $run) {
            $this->assertTrue($run['historicalSourceMatches']);
            $this->assertFalse($run['currentSourceMatches']);
            $this->assertTrue($run['verification']['passed']);
            $this->assertTrue($run['usage']['complete']);
            $this->assertSame($run['usage']['uncachedInput'] + $run['usage']['cacheWrite'] + $run['usage']['cacheRead'] + $run['usage']['output'], $run['usage']['total']);
            $this->assertSame($report['protocol']['questions'], array_column($run['turns'], 'question'));
        }
        $this->assertCount(0, $report['attempts'][0]['code']);
        $this->assertCount(4, $report['attempts'][1]['code']);
        foreach (['Bearer ', 'Authorization', 'X-Debug-Token', '"thinking"', '"signature"'] as $excluded) {
            $this->assertStringNotContainsString($excluded, json_encode($report));
        }
    }

    public function testChangedPromptsUsageCallsAndPermissionEvidenceFailVerification(): void
    {
        $report = ClaudeComparisonProof::load(\dirname(__DIR__, 2));
        foreach ($report['attempts'] as $original) {
            foreach (['question', 'usage', 'model', 'handler', 'budget', 'arguments', 'response', 'answer', 'timing', 'mode'] as $case) {
                $run = $original;
                match ($case) {
                    'question' => $run['turns'][0]['question'] .= ' Extra help.',
                    'usage' => $run['usageRows'][0]['usage']['cache_read_input_tokens'] = null,
                    'model' => $run['client']['model'] = 'different-model',
                    'handler' => $run['http'][4]['profiler']['operations'][0]['nativeEvents'][] = ['name' => 'publication_read', 'phase' => 'succeeded'],
                    'budget' => $run['http'][4]['profiler']['operations'][0]['budget'][0]['attempts'] = 0,
                    'arguments' => $run['http'][4]['request']['params']['arguments']['calls'][0]['arguments']['id'] = 202,
                    'response' => $run['http'][4]['response']['result']['structuredContent'] = $run['http'][3]['response']['result']['structuredContent'],
                    'answer' => $run['turns'][1]['answer'] = 'The fresh read succeeded.',
                    'timing' => $run['permissionChange']['at'] = $run['turns'][0]['askedAt'],
                    'mode' => $run['client']['nativeCodeMode'] = !$run['client']['nativeCodeMode'],
                };
                $this->assertFalse(ClaudeComparisonProof::verify($run, $report['protocol']['questions'])['passed'], $original['path'].' '.$case);
            }
        }
    }

    public function testDuplicateContentBlocksAreCountedOnceAndMissingUsageIsNotZero(): void
    {
        $row = ['messageId' => 'message-1', 'requestId' => 'request-1', 'model' => 'test-model', 'turn' => 0,
            'usage' => ['input_tokens' => 2, 'cache_creation_input_tokens' => 100, 'cache_read_input_tokens' => 500, 'output_tokens' => 10]];
        $final = $row;
        $final['usage']['output_tokens'] = 20;
        $report = ClaudeUsage::summarize([$row, ['model' => '<synthetic>'], $final]);
        $this->assertTrue($report['complete']);
        $this->assertSame(622, $report['total']);
        $this->assertSame(1, $report['requests']);
        $this->assertSame(1, $report['duplicateRowsExcluded']);
        $missing = $row;
        unset($missing['usage']['cache_creation_input_tokens']);
        $this->assertFalse(ClaudeUsage::summarize([$missing])['complete']);
        $this->assertFalse(ClaudeUsage::summarize([])['complete']);
        $this->assertFalse(ClaudeUsage::summarize([['model' => '<synthetic>']])['complete']);
        $this->assertFalse(ClaudeUsage::summarize([$final, $row])['complete']);
        $other = $row;
        $other['messageId'] = 'message-2';
        $this->assertFalse(ClaudeUsage::summarize([$row, $other])['complete']);
    }

    public function testAnIncompleteAttemptKeepsItsReportedTokensAndFailsCorrectness(): void
    {
        $report = ClaudeComparisonProof::load(\dirname(__DIR__, 2));
        $run = $report['attempts'][0];
        $run['turns'][1]['answer'] = '';
        $run['http'] = \array_slice($run['http'], 0, 4);
        $this->assertFalse(ClaudeComparisonProof::verify($run, $report['protocol']['questions'])['passed']);
        $this->assertSame(44131, ClaudeUsage::summarize($run['usageRows'])['total']);
    }
}
