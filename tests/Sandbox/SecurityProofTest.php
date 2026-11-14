<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Command\CodeModeSecurityProofCommand;
use App\Demo\ReportStore;
use App\Demo\SecurityProofRunner;
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
            $this->assertSame(0, $tester->execute([]), $tester->getDisplay());
            $this->assertSame(['status' => 'untouched-comparison'], json_decode($store->read(), true));
            $report = json_decode($store->readSecurityProof(), true, flags: \JSON_THROW_ON_ERROR);
            $this->assertSame('complete', $report['status']);
            $this->assertCount(3, $report['cases']);
            $this->assertSame([null, 'tool_denied', 'wall_timeout'], array_column($report['cases'], 'reason'));
            $this->assertSame(48, $report['cases'][0]['value']);
            $this->assertSame('denied', $report['cases'][1]['toolCalls'][0]['status']);
            $this->assertSame('succeeded', $report['cases'][2]['toolCalls'][0]['status']);
            $this->assertGreaterThanOrEqual(2000, $report['cases'][2]['durationMs']);
            $this->assertCount(3, array_unique(array_column($report['cases'], 'runId')));
            foreach ($report['cases'] as $case) {
                $this->assertTrue($case['passed']);
                $this->assertTrue($case['containerRemoved']);
                $this->assertMatchesRegularExpression('/\Acode-mode-[a-f0-9]{32}\z/', $case['runId']);
                $this->assertArrayHasKey('cleanupCheckedAt', $case);
                $this->assertGreaterThan(0, $case['durationMs']);
            }
            $this->assertSame(0600, fileperms($directory.'/var/demo/security.json') & 0777);
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
