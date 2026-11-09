<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SurfaceProof\Verifier;

require_once dirname(__DIR__).'/src/Connection.php';
require_once dirname(__DIR__).'/src/Verifier.php';

final class ProofTest extends TestCase
{
    private function report(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/proof/reference.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    public function testReviewedReferenceContainsRealReadsAndDenials(): void
    {
        $report = $this->report();
        self::assertSame('complete', $report['status']);
        self::assertNotContains(false, (new Verifier())->check($report));
        self::assertContains(false, (new Verifier())->check([]));
    }

    public function testMissingEvidenceCannotPass(): void
    {
        $verifier = new Verifier();
        foreach (array_keys($this->report()['executions']) as $label) {
            $report = $this->report();
            unset($report['executions'][$label]);
            self::assertContains(false, $verifier->check($report), $label);
        }
        foreach (['value', 'toolCalls', 'sourceSha256', 'containerRemoved'] as $key) {
            $report = $this->report();
            unset($report['executions']['composition'][$key]);
            self::assertContains(false, $verifier->check($report), $key);
        }
        foreach (['before', 'after', 'revoked', 'contracts', 'origins', 'interfaces', 'direct', 'mcp', 'limits'] as $key) {
            $report = $this->report();
            unset($report[$key]);
            self::assertContains(false, $verifier->check($report), $key);
        }
    }

    public function testErrorsAndTamperedSecurityEvidenceCannotPass(): void
    {
        $mutations = [
            static function (array &$r): void { $r['error'] = 'proof_unavailable'; },
            static function (array &$r): void { $r['restorationFailed'] = true; },
            static function (array &$r): void { $r['before']['nodes']['field_denied']['titleAllowed'] = true; },
            static function (array &$r): void { $r['revoked']['queryAllowed'] = true; },
            static function (array &$r): void { $r['after']['account']['permissions'][] = 'bypass node access'; },
            static function (array &$r): void { $r['after']['sources']['view-config'] = 'changed'; },
            static function (array &$r): void { $r['executions']['revoked']['toolCalls'] = []; },
            static function (array &$r): void { $r['executions']['composition']['executedPhp'] .= "\nreturn true;"; },
            static function (array &$r): void { $r['limits']['maxToolCalls'] = 100; },
            static function (array &$r): void { $r['mcp']['empty']['value'] = null; },
        ];
        foreach ($mutations as $index => $mutate) {
            $report = $this->report();
            $mutate($report);
            self::assertContains(false, (new Verifier())->check($report), 'Mutation '.$index);
        }
    }
}
