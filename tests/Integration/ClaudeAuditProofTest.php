<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\ClaudeAuditProof;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

// Large capture inspections must not alter the legacy forked worker's memory preconditions.
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ClaudeAuditProofTest extends TestCase
{
    public function testFourActualClientConfigurationsKeepTheFailedAuditAndItsUsage(): void
    {
        $report = ClaudeAuditProof::load(\dirname(__DIR__, 2));
        $this->assertTrue($report['matched']);
        $this->assertCount(8, $report['attempts']);
        $this->assertSame([true, true], array_column($report['iterations'], 'matched'));
        $original = \array_slice($report['attempts'], 0, 4);
        $this->assertSame(['direct-regular', 'catalog-native', 'catalog-regular', 'direct-native'], array_column($original, 'arm'));
        $this->assertSame([53749, 91145, 54939, 49432], array_column(array_column($original, 'usage'), 'total'));
        $this->assertSame([5, 7, 6, 4], array_column(array_column($original, 'usage'), 'requests'));
        $this->assertSame([true, true, false, true], array_column(array_column($original, 'verification'), 'passed'));
        foreach ($report['attempts'] as $run) {
            $this->assertTrue($run['verification']['captureVerified'], json_encode($run['verification']));
            // Both iterations predate the gateway's opt-in field selection surface.
            $this->assertFalse($run['currentSourceMatches']);
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

    public function testCorrectedContractsKeepAllFourCorrectAnswersAndClientRuntimeFailures(): void
    {
        $report = ClaudeAuditProof::load(\dirname(__DIR__, 2));
        $runs = \array_slice($report['attempts'], 4);
        $this->assertSame([54067, 143647, 60781, 94655], array_column(array_column($runs, 'usage'), 'total'));
        $this->assertSame([5, 9, 6, 7], array_column(array_column($runs, 'usage'), 'requests'));
        foreach ($runs as $run) {
            $this->assertTrue($run['verification']['passed'], json_encode($run['verification']));
            $this->assertSame(0, $run['verification']['counts']['deniedReads']);
            $this->assertSame(['cms_list_articles' => 48, 'cms_get_articles' => 48, 'cms_get_media' => 24], $run['verification']['coverage']);
        }
        $this->assertCount(1, array_filter($runs[1]['code'], static fn ($block) => $block['isError']));
        $this->assertCount(2, array_filter($runs[3]['code'], static fn ($block) => $block['isError']));
        $this->assertSame(12, $runs[3]['verification']['counts']['reservedCalls']);
        // Only metadata was corrected: all security, fixture and dependency sources are unchanged.
        foreach ($report['attempts'][0]['sourceSha256'] as $path => $hash) {
            if (!\in_array($path, ['src/Demo/AuditMatrix/Tools.php', 'src/Demo/AuditMatrix/Gateway.php'], true)) {
                $this->assertSame($hash, $runs[0]['sourceSha256'][$path], $path);
            }
        }
    }

    public function testAnswerAndTransportTamperingCannotBecomeAValidAudit(): void
    {
        $report = ClaudeAuditProof::load(\dirname(__DIR__, 2));
        foreach (array_filter($report['attempts'], static fn ($run) => $run['verification']['passed']) as $original) {
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

    public function testNativeFieldSelectionPairIncludesActualChoicesAndOnlyLocalDiscovery(): void
    {
        $pair = ClaudeAuditProof::load(\dirname(__DIR__, 2))['fieldSelectionPair'];
        $this->assertTrue($pair['matched']);
        [$baseline, $offered] = $pair['attempts'];
        $this->assertTrue($baseline['verification']['passed']);
        $this->assertTrue($offered['verification']['passed']);
        $this->assertSame([0, 6], array_column($pair['attempts'], 'selectedReads'));
        $this->assertSame([173097, 167618], array_column(array_column($pair['attempts'], 'usage'), 'total'));
        $this->assertSame([12, 11], array_column(array_column($pair['attempts'], 'usage'), 'requests'));
        $index = array_key_first(array_filter($offered['agentCalls'], static fn ($call) => 'describeTool' === $call['name']));
        $offered['agentCalls'][$index]['arguments']['name'] = 'some_other_server__tool';
        $this->assertFalse(ClaudeAuditProof::verify($offered, $pair['protocol']['question'])['checks']['noExternalToolCalls']);
    }
}
