<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\ClaudeAuditProof;
use PHPUnit\Framework\TestCase;

final class ClaudeAuditProofTest extends TestCase
{
    public function testFourActualClientConfigurationsKeepTheFailedAuditAndItsUsage(): void
    {
        $report = ClaudeAuditProof::load(\dirname(__DIR__, 2));
        $this->assertTrue($report['matched']);
        $this->assertSame(['direct-regular', 'catalog-native', 'catalog-regular', 'direct-native'], array_column($report['attempts'], 'arm'));
        $this->assertSame([53749, 91145, 54939, 49432], array_column(array_column($report['attempts'], 'usage'), 'total'));
        $this->assertSame([5, 7, 6, 4], array_column(array_column($report['attempts'], 'usage'), 'requests'));
        $this->assertSame([true, true, false, true], array_column(array_column($report['attempts'], 'verification'), 'passed'));
        foreach ($report['attempts'] as $run) {
            $this->assertTrue($run['verification']['captureVerified'], json_encode($run['verification']));
            $this->assertTrue($run['currentSourceMatches']);
            $this->assertTrue($run['usage']['complete']);
        }
        $failed = $report['attempts'][2];
        $this->assertSame(2, $failed['verification']['counts']['deniedReads']);
        $this->assertSame(18, $failed['verification']['counts']['reservedCalls']);
        $this->assertSame(['cms_list_articles' => 48, 'cms_get_articles' => 12, 'cms_get_media' => 0], $failed['verification']['coverage']);
        $this->assertStringContainsString("I couldn't finish the audit", $failed['turns'][0]['answer']);
        $this->assertSame([], $report['attempts'][0]['code']);
        $this->assertCount(6, $report['attempts'][1]['code']);
        $this->assertCount(3, $report['attempts'][3]['code']);
    }

    public function testAnswerAndTransportTamperingCannotBecomeAValidAudit(): void
    {
        $report = ClaudeAuditProof::load(\dirname(__DIR__, 2));
        foreach ([$report['attempts'][0], $report['attempts'][1], $report['attempts'][3]] as $original) {
            foreach (['answer', 'call', 'data', 'budget', 'handler', 'usage', 'tool'] as $case) {
                $run = $original;
                $index = array_key_first(array_filter($run['http'], static fn ($row) => 'tools/call' === $row['request']['method'] && 'search_tools' !== $row['request']['params']['name']));
                switch ($case) {
                    case 'answer':
                        $run['turns'][0]['answer'] = '{"audited":48,"findings":[]}';
                        $run['turns'][0]['answerSha256'] = hash('sha256', $run['turns'][0]['answer']);
                        break;
                    case 'call':
                        $run['http'][$index]['request']['params']['_meta']['claudecode/toolUseId'] = 'unbound';
                        break;
                    case 'data':
                        $run['http'][$index]['response']['result']['structuredContent'] = ['items' => []];
                        $run['http'][$index]['response']['result']['content'][0]['text'] = '{"items":[]}';
                        break;
                    case 'budget':
                        $run['http'][$index]['trace']['operations'][0]['budget'][0]['attempts'] = 9;
                        break;
                    case 'handler':
                        $run['http'][$index]['trace']['operations'][0]['nativeEvents'] = [];
                        break;
                    case 'usage':
                        unset($run['usageRows'][0]['usage']['cache_read_input_tokens']);
                        break;
                    case 'tool':
                        $run['agentCalls'][] = ['name' => 'Bash'];
                        break;
                }
                $this->assertFalse(ClaudeAuditProof::verify($run, $report['protocol']['question'])['passed'], $original['arm'].' '.$case);
            }
        }
    }

    public function testDeniedCallsCannotClaimAHandlerInvocationOrARefund(): void
    {
        $report = ClaudeAuditProof::load(\dirname(__DIR__, 2));
        $failed = $report['attempts'][2];
        $index = array_key_first(array_filter($failed['http'], static fn ($row) => $row['response']['result']['isError'] ?? false));
        $failed['http'][$index]['trace']['operations'][0]['nativeEvents'][] = ['name' => 'cms_get_media', 'phase' => 'requested'];
        $this->assertFalse(ClaudeAuditProof::verify($failed, $report['protocol']['question'])['captureVerified']);
    }
}
