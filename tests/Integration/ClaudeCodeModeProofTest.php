<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\ClaudeCodeModeProof;
use App\Demo\HttpConversation\Proof;
use PHPUnit\Framework\TestCase;

final class ClaudeCodeModeProofTest extends TestCase
{
    public function testNativeJavaScriptAndHttpEvidenceAgreeWithoutChangingSymfonySource(): void
    {
        $root = \dirname(__DIR__, 2);
        $report = ClaudeCodeModeProof::load($root);
        $this->assertCount(1, $report['attempts']);
        $run = $report['attempts'][0];
        $this->assertTrue($run['verification']['passed']);
        $this->assertTrue($run['currentSourceMatches']);
        $this->assertSame(Proof::load($root)['attempts'][1]['sourceSha256'], $run['sourceSha256']);
        $this->assertCount(5, $run['code']);
        $this->assertCount(6, $run['agentCalls']);
        $this->assertSame(['searchTools', 'searchTools'], array_column(\array_slice($run['agentCalls'], 0, 2), 'name'));
        $this->assertSame($run['agentCalls'][4]['codeId'], $run['agentCalls'][5]['codeId']);
        foreach (['Bearer ', 'Authorization', 'X-Debug-Token', 'thinking', 'signature'] as $excluded) {
            $this->assertStringNotContainsString($excluded, json_encode($report));
        }
    }

    public function testVerificationRejectsChangedEvidenceInsteadOfTrustingSuccessFlags(): void
    {
        $run = ClaudeCodeModeProof::load(\dirname(__DIR__, 2))['attempts'][0];
        foreach (['javascript', 'arguments', 'result', 'handler', 'budget', 'revocation', 'ordinary_client', 'different_block', 'wrong_answer'] as $case) {
            $changed = $run;
            match ($case) {
                'javascript' => $changed['code'][4]['code'] = 'return "success";',
                'arguments' => $changed['agentCalls'][4]['arguments']['calls'][0]['arguments']['id'] = 202,
                'result' => $changed['http'][4]['response']['result']['structuredContent'] = $changed['http'][3]['response']['result']['structuredContent'],
                'handler' => $changed['http'][4]['profiler']['operations'][0]['nativeEvents'][] = ['name' => 'publication_read', 'phase' => 'succeeded'],
                'budget' => $changed['http'][4]['profiler']['operations'][0]['budget'][0]['attempts'] = 0,
                'revocation' => $changed['permissionChange']['at'] = $changed['turns'][0]['askedAt'],
                'ordinary_client' => $changed['client']['nativeCodeMode'] = false,
                'different_block' => $changed['agentCalls'][5]['codeId'] = $changed['code'][2]['id'],
                'wrong_answer' => $changed['turns'][1]['answer'] = $changed['turns'][0]['answer'],
            };
            $this->assertFalse(ClaudeCodeModeProof::verify($changed)['passed'], $case);
        }
    }
}
