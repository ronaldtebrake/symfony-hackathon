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
        self::assertStringContainsString('src="media/symfony-native-tools-demo-90s.mp4"', $html);
        self::assertStringContainsString('poster="media/symfony-native-tools-poster.jpg"', $html);
        self::assertStringNotContainsString('="/media/', $html);
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/var/pages', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = substr($file->getPathname(), strlen($root.'/var/pages/'));
            self::assertStringNotContainsString('unused-build-sentinel', file_get_contents($file->getPathname()));
        }
        sort($files);
        $expected = ['.nojekyll', 'index.html', 'demo.css', 'demo.js', 'docs.js', 'catalog-proof.js', 'php-syntax.js', 'images/code-mode-value.svg'];
        foreach (['symfony-native-tools-demo-90s.mp4', 'symfony-native-tools-poster.jpg', 'profiler-authorized.png', 'profiler-denied.png', 'video-proof.json'] as $name) { $expected[] = 'media/'.$name; }
        foreach (['report', 'security-report', 'agent-report', 'boundary-report', 'huggingface-report', 'huggingface-comparison', 'adoption-report', 'evaluation-report', 'performance-report', 'performance-conversation', 'performance-baseline', 'response-payloads', 'audit-three-way', 'catalog-proof', 'native-catalog', 'http-catalog'] as $name) { $expected[] = 'evidence/'.$name.'.json'; }
        sort($expected); self::assertSame($expected, $files);
        self::assertFileDoesNotExist($root.'/var/pages/evidence/discovery-report.json');
        self::assertStringNotContainsString('compact-discovery', $html);
    }

    public function testVideoChaptersAndClaimsMatchTheirSeparateCaptures(): void
    {
        $root = dirname(__DIR__, 2);
        $video = json_decode(file_get_contents($root.'/public/media/video-proof.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2700, $video['frames']);
        self::assertSame(90, $video['durationSeconds']);
        self::assertSame('none', $video['audio']);
        preg_match_all('/start:(\d+), end:(\d+)/', file_get_contents($root.'/public/docs.js'), $timings, PREG_SET_ORDER);
        self::assertSame(array_map(static fn (array $chapter): array => [$chapter['start'], $chapter['end']], $video['chapters']),
            array_map(static fn (array $match): array => [(int) $match[1], (int) $match[2]], $timings));
        $requests = array_column($video['development']['requests'], null, 'label');
        self::assertSame($requests['authorized']['request']['params'], $requests['denied']['request']['params']);
        self::assertSame('A Symfony application owns its tools', $requests['authorized']['response']['result']['structuredContent']['results'][0]['value']['title']);
        $denied = $requests['denied']['response']['result']['structuredContent'];
        self::assertSame('tool_denied', $denied['error']['reason']);
        self::assertSame(0, $denied['completedCalls']);
        self::assertArrayNotHasKey('results', $denied);
        self::assertSame(['requested', 'succeeded'], array_column($requests['authorized']['operations'][0]['nativeEvents'], 'phase'));
        self::assertSame([], $requests['denied']['operations'][0]['nativeEvents']);
        self::assertSame(2, $requests['denied']['operations'][0]['budget'][2]['attempts']);
        self::assertSame([], $requests['hidden']['response']['result']['structuredContent']['tools']);
        foreach ($video['development']['checks'] as $label => $passed) { self::assertTrue($passed, $label); }
        foreach (['src/ArticleTool.php', 'src/ArticleVoter.php', 'src/ArticleController.php'] as $file) {
            self::assertSame($video['development']['sourceSha256'][$file], hash_file('sha256', $root.'/examples/symfony/'.$file));
        }
        $audit = (new ReportStore($root, reviewedOnly: true))->readThreeWayAudit();
        $matching = array_values(array_filter($audit['attempts'], static fn (array $attempt): bool => $attempt['protocol'] === $video['historicalAudit']['protocol'] && $attempt['createdAt'] === $video['historicalAudit']['createdAt']));
        self::assertCount(1, $matching);
        self::assertTrue($matching[0]['verification']['passed']);
        foreach ($video['historicalAudit']['providerTokens'] as $arm => $tokens) {
            self::assertSame($matching[0]['runs'][$arm]['result']['providerTokens']['total'], $tokens);
        }
    }
}
