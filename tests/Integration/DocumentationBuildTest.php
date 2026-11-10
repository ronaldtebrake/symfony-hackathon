<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\ReportStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class DocumentationBuildTest extends TestCase
{
    public function testAnimationNumbersMatchTheReviewedProviderEvidence(): void
    {
        $root = dirname(__DIR__, 2);
        $report = json_decode((new ReportStore($root, reviewedOnly: true))->readHuggingFaceComparison(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($report['verifiedComparison']);
        $direct = $report['direct']['result'];
        $code = $report['code']['result'];
        $svg = new \DOMDocument();
        self::assertTrue($svg->load($root.'/docs/images/code-mode-value.svg', LIBXML_NONET));
        $labels = [];
        foreach ((new \DOMXPath($svg))->query('//*[local-name()="text"]') as $text) { $labels[] = $text->textContent; }
        foreach ([$direct, $code] as $run) {
            self::assertContains(number_format($run['providerTokens']['total']).' tokens', $labels);
            self::assertContains(count($run['brokerCalls']).' MCP CALLS', $labels);
        }
        $saved = $direct['providerTokens']['total'] - $code['providerTokens']['total'];
        self::assertContains(number_format(100 * $saved / $direct['providerTokens']['total'], 1).'%', $labels);
        self::assertContains($direct['modelRequests'].' → '.$code['modelRequests'].' model requests · '.number_format($saved).' fewer tokens in this pair', $labels);
        self::assertStringContainsString('prefers-reduced-motion', file_get_contents($root.'/docs/images/code-mode-value.svg'));
    }

    public function testReviewedStoreCannotReadLocalReports(): void
    {
        $root = sys_get_temp_dir().'/code-mode-pages-'.bin2hex(random_bytes(6));
        $fs = new Filesystem();
        $fs->mkdir([$root.'/docs/evidence', $root.'/var/demo/audit-three-way']);
        file_put_contents($root.'/docs/evidence/audit-three-way.json', '{"attempts":[{"id":"reviewed"}]}');
        file_put_contents($root.'/var/demo/audit-three-way/20261109-120000-abcdef12.json', '{"id":"PRIVATE_LOCAL_SENTINEL"}');
        foreach (['compact-discovery.json', 'evaluation.json', 'adoption-proof.json', 'huggingface-proof.json', 'security-proof-2026-11-01.json', 'performance-proof.json', 'performance-conversation.json', 'performance-baseline.json', 'reference-comparison-2026-10-31.json'] as $file) {
            file_put_contents($root.'/docs/evidence/'.$file, '{"source":"reviewed"}');
        }
        foreach (['discovery.json', 'evaluation.json', 'adoption.json', 'huggingface.json', 'huggingface-pair.json', 'security.json', 'performance.json', 'performance-conversation.json', 'performance-baseline.json', 'latest.json'] as $file) {
            file_put_contents($root.'/var/demo/'.$file, '{"source":"PRIVATE_LOCAL_SENTINEL"}');
        }
        try {
            $store = new ReportStore($root, reviewedOnly: true);
            foreach (['readDiscoveryComparison', 'readEvaluation', 'readAdoptionProof', 'readHuggingFaceProof', 'readSecurityProof', 'readPerformanceProof', 'readPerformanceConversation', 'readPerformanceBaseline', 'read'] as $method) {
                self::assertSame('{"source":"reviewed"}', $store->$method());
            }
            self::assertNull($store->readHuggingFacePair());
            self::assertNull($store->readLocalPerformanceProof());
            self::assertSame([['id' => 'reviewed']], $store->readThreeWayAudit()['attempts']);
            self::assertCount(2, (new ReportStore($root))->readThreeWayAudit()['attempts']);
            self::assertStringContainsString('PRIVATE_LOCAL_SENTINEL', (new ReportStore($root))->read());
        } finally { $fs->remove($root); }
    }

    public function testStaticBuildExportsOnlyFixedAssetsAndVerifiedReports(): void
    {
        $root = dirname(__DIR__, 2);
        (new Filesystem())->mkdir($root.'/var/pages');
        file_put_contents($root.'/var/pages/stale-private.txt', 'DO_NOT_PUBLISH');
        $process = new Process([PHP_BINARY, 'scripts/build-docs.php'], $root, ['HUGGINGFACE_API_KEY' => 'unused-build-sentinel'], timeout: 60);
        $process->mustRun();
        self::assertFileDoesNotExist($root.'/var/pages/stale-private.txt');
        $html = file_get_contents($root.'/var/pages/index.html');
        self::assertStringContainsString('data-static="true"', $html);
        self::assertStringContainsString('href="demo.css"', $html);
        self::assertStringContainsString('href="evidence/agent-report.json"', $html);
        self::assertStringNotContainsString('href="/demo', $html);
        self::assertStringNotContainsString('Codex', $html);
        self::assertStringContainsString('Content-Security-Policy', $html);
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/var/pages', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = substr($file->getPathname(), strlen($root.'/var/pages/'));
            self::assertStringNotContainsString('unused-build-sentinel', file_get_contents($file->getPathname()));
        }
        sort($files);
        $expected = ['.nojekyll', 'index.html', 'demo.css', 'demo.js', 'docs.js', 'catalog-proof.js', 'php-syntax.js', 'images/code-mode-value.svg'];
        foreach (['report', 'security-report', 'agent-report', 'boundary-report', 'huggingface-report', 'huggingface-comparison', 'adoption-report', 'evaluation-report', 'performance-report', 'performance-conversation', 'performance-baseline', 'response-payloads', 'audit-three-way', 'catalog-proof', 'native-catalog'] as $name) { $expected[] = 'evidence/'.$name.'.json'; }
        sort($expected); self::assertSame($expected, $files);
        self::assertFileDoesNotExist($root.'/var/pages/evidence/discovery-report.json');
        self::assertStringNotContainsString('compact-discovery', $html);
    }
}
