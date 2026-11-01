<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Command\CodeModeSecurityProofCommand;
use App\Demo\{ReportStore, SecurityProofRunner};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SecurityProofTest extends KernelTestCase
{
    public function testCliRecordsRealReadDenialAndTimeoutWithCleanupEvidence(): void
    {
        self::bootKernel();
        $directory = sys_get_temp_dir().'/code-mode-security-'.bin2hex(random_bytes(8));
        $store = new ReportStore($directory);
        try {
            $store->save(['status' => 'untouched-comparison']);
            $tester = new CommandTester(new CodeModeSecurityProofCommand(self::getContainer()->get(SecurityProofRunner::class), $store));
            self::assertSame(0, $tester->execute([]), $tester->getDisplay());
            self::assertSame(['status' => 'untouched-comparison'], json_decode($store->read(), true));
            $report = json_decode($store->readSecurityProof(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('complete', $report['status']);
            self::assertCount(3, $report['cases']);
            self::assertSame([null, 'tool_denied', 'wall_timeout'], array_column($report['cases'], 'reason'));
            self::assertSame(48, $report['cases'][0]['value']);
            self::assertSame('denied', $report['cases'][1]['toolCalls'][0]['status']);
            self::assertSame('succeeded', $report['cases'][2]['toolCalls'][0]['status']);
            self::assertGreaterThanOrEqual(2000, $report['cases'][2]['durationMs']);
            self::assertCount(3, array_unique(array_column($report['cases'], 'runId')));
            foreach ($report['cases'] as $case) {
                self::assertTrue($case['passed']);
                self::assertTrue($case['containerRemoved']);
                self::assertMatchesRegularExpression('/\Acode-mode-[a-f0-9]{32}\z/', $case['runId']);
                self::assertArrayHasKey('cleanupCheckedAt', $case);
                self::assertGreaterThan(0, $case['durationMs']);
            }
            self::assertSame(0600, fileperms($directory.'/var/demo/security.json') & 0777);
        } finally {
            foreach (['latest.json', 'security.json'] as $filename) {
                if (is_file($directory.'/var/demo/'.$filename)) {
                    unlink($directory.'/var/demo/'.$filename);
                }
            }
            foreach ([$directory.'/var/demo', $directory.'/var', $directory] as $path) {
                if (is_dir($path)) {
                    rmdir($path);
                }
            }
        }
    }
}
