<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SurfaceProof\BatchComparisonVerifier;

require_once dirname(__DIR__).'/src/Connection.php';
require_once dirname(__DIR__).'/src/Verifier.php';
require_once dirname(__DIR__).'/src/BatchComparisonVerifier.php';

final class BatchComparisonTest extends TestCase
{
    private function report(): array { return json_decode(file_get_contents(dirname(__DIR__).'/proof/batch-comparison.json'), true, 64, JSON_THROW_ON_ERROR); }

    public function testReferenceUsesSameActualReadsButPreservesDifferentResponseShapes(): void
    {
        $report = $this->report();
        self::assertSame('complete', $report['status']);
        self::assertNotContains(false, BatchComparisonVerifier::check($report));
        self::assertContains(false, BatchComparisonVerifier::check([]));
        $batch = $report['runs']['batch']['composition']['response']['results'];
        self::assertCount(4, [...$batch[0]['value']['matches'], ...$batch[1]['value']['matches']]);
        self::assertCount(3, $report['runs']['php']['composition']['response']['value']['matches']);
    }

    public function testMissingAndAlteredEvidenceCannotPass(): void
    {
        foreach (['batch', 'php'] as $mode) {
            foreach (array_keys($this->report()['runs'][$mode]) as $label) {
                $report = $this->report(); unset($report['runs'][$mode][$label]);
                self::assertContains(false, BatchComparisonVerifier::check($report), $mode.':'.$label);
            }
        }
        foreach ([
            static function (&$r) { $r['modelCalled'] = true; },
            static function (&$r) { $r['restorationFailed'] = true; },
            static function (&$r) { $r['revoked']['queryAllowed'] = true; },
            static function (&$r) { $r['after']['contentHash'] = 'changed'; },
            static function (&$r) { $r['before']['nodes']['field_denied']['titleAllowed'] = true; },
            static function (&$r) { $r['runs']['batch']['composition']['runtime']['generatedCode'] = true; },
            static function (&$r) { $r['runs']['batch']['composition']['upstream'][0]['arguments']['values']['limit'] = 5; },
            static function (&$r) { $r['runs']['php']['composition']['containerRemoved'] = false; },
            static function (&$r) { $r['runs']['php']['composition']['sourceSha256'] = 'forged'; },
            static function (&$r) { $r['runs']['batch']['restricted']['response']['results'][0]['value']['count'] = 1; },
            static function (&$r) { unset($r['runs']['batch']['composition']['responseSha256']); },
            static function (&$r) { $r['runs']['batch']['revoked']['response']['results'] = []; },
        ] as $mutate) {
            $report = $this->report(); $mutate($report);
            self::assertContains(false, BatchComparisonVerifier::check($report));
        }
    }

    public function testOfflineTokenEvidenceIsBoundToExactCapturedPayloads(): void
    {
        $report = $this->report();
        $tokens = json_decode(file_get_contents(dirname(__DIR__).'/proof/batch-payload-tokens.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(hash_file('sha256', dirname(__DIR__).'/proof/batch-comparison.json'), $tokens['sourceSha256']);
        self::assertSame('offline-data-surface-payload-counts', $tokens['kind']);
        foreach (['batch', 'php'] as $mode) {
            $run = $report['runs'][$mode]['composition'];
            $discovery = $report['discovery'][$mode];
            $parts = ['toolDefinitions' => $discovery['tools'], 'searchRequest' => ['query' => 'search'], 'searchResponse' => $discovery['search'],
                'describeRequest' => ['names' => ['drupal.search']], 'describeResponse' => $discovery['describe'], 'executionRequest' => $run['request'], 'executionResponse' => $run['response']];
            $sum = 0;
            foreach ($parts as $name => $value) {
                $text = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                self::assertSame(hash('sha256', $text), $tokens['samples'][$mode][$name]['sha256']);
                self::assertSame(strlen($text), $tokens['samples'][$mode][$name]['bytes']);
                $sum += $tokens['samples'][$mode][$name]['tokens'];
            }
            self::assertSame($sum, $tokens['samples'][$mode]['sumOfSingleCopies']);
        }
    }
}
