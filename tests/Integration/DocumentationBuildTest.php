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
        $root = \dirname(__DIR__, 2);
        $report = json_decode((new ReportStore($root, reviewedOnly: true))->readHuggingFaceComparison(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertTrue($report['verifiedComparison']);
        $direct = $report['direct']['result'];
        $code = $report['code']['result'];
        $svg = new \DOMDocument();
        $this->assertTrue($svg->load($root.'/docs/images/code-mode-value.svg', \LIBXML_NONET));
        $labels = [];
        foreach ((new \DOMXPath($svg))->query('//*[local-name()="text"]') as $text) {
            $labels[] = $text->textContent;
        }
        foreach ([$direct, $code] as $run) {
            $this->assertContains(number_format($run['providerTokens']['total']).' tokens', $labels);
            $this->assertContains(\count($run['brokerCalls']).' MCP CALLS', $labels);
        }
        $saved = $direct['providerTokens']['total'] - $code['providerTokens']['total'];
        $this->assertContains(number_format(100 * $saved / $direct['providerTokens']['total'], 1).'%', $labels);
        $this->assertContains($direct['modelRequests'].' → '.$code['modelRequests'].' model requests · '.number_format($saved).' fewer tokens in this pair', $labels);
        $this->assertStringContainsString('prefers-reduced-motion', file_get_contents($root.'/docs/images/code-mode-value.svg'));
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
                $this->assertSame('{"source":"reviewed"}', $store->$method());
            }
            $this->assertNull($store->readHuggingFacePair());
            $this->assertNull($store->readLocalPerformanceProof());
            $this->assertSame([['id' => 'reviewed']], $store->readThreeWayAudit()['attempts']);
            $this->assertCount(2, (new ReportStore($root))->readThreeWayAudit()['attempts']);
            $this->assertStringContainsString('PRIVATE_LOCAL_SENTINEL', (new ReportStore($root))->read());
        } finally {
            $fs->remove($root);
        }
    }

    public function testStaticBuildExportsOnlyFixedAssetsAndVerifiedReports(): void
    {
        $root = \dirname(__DIR__, 2);
        (new Filesystem())->mkdir($root.'/var/pages');
        file_put_contents($root.'/var/pages/stale-private.txt', 'DO_NOT_PUBLISH');
        $process = new Process([\PHP_BINARY, 'scripts/build-docs.php'], $root, ['HUGGINGFACE_API_KEY' => 'unused-build-sentinel'], timeout: 60);
        $process->mustRun();
        $this->assertFileDoesNotExist($root.'/var/pages/stale-private.txt');
        $html = file_get_contents($root.'/var/pages/index.html');
        $this->assertStringContainsString('data-static="true"', $html);
        $this->assertStringContainsString('href="demo.css"', $html);
        $this->assertStringContainsString('href="evidence/agent-report.json"', $html);
        $this->assertStringNotContainsString('href="/demo', $html);
        $this->assertStringNotContainsString('Codex', $html);
        $this->assertStringContainsString('Content-Security-Policy', $html);
        $this->assertStringContainsString('href="evidence/editorial-review.json"', $html);
        $this->assertStringContainsString('href="evidence/editorial-review-agent.json"', $html);
        $this->assertStringContainsString('src="editorial-review.js"', $html);
        $this->assertStringContainsString('src="media/server-code-mode-90s.mp4"', $html);
        $this->assertStringContainsString('poster="media/server-code-mode-poster.jpg"', $html);
        $this->assertStringContainsString('src="media/editorial-review-90s.mp4?v=11"', $html);
        $this->assertStringContainsString('poster="media/editorial-review-poster.jpg?v=11"', $html);
        $this->assertStringContainsString('src="media/symfony-native-tools-demo-90s.mp4"', $html);
        $this->assertStringContainsString('poster="media/symfony-native-tools-poster.jpg"', $html);
        $this->assertStringNotContainsString('="/media/', $html);
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/var/pages', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = substr($file->getPathname(), \strlen($root.'/var/pages/'));
            $this->assertStringNotContainsString('unused-build-sentinel', file_get_contents($file->getPathname()));
        }
        sort($files);
        $expected = ['.nojekyll', 'index.html', 'demo.css', 'demo.js', 'docs.js', 'editorial-review.js', 'catalog-proof.js', 'catalog-scaling.js', 'php-syntax.js', 'images/code-mode-value.svg', 'images/symfony-mcp-architecture.svg'];
        foreach (['server-code-mode-90s.mp4', 'server-code-mode-poster.jpg', 'server-code-mode-film.json', 'editorial-review-90s.mp4', 'editorial-review-poster.jpg', 'editorial-review-film.json', 'symfony-native-tools-demo-90s.mp4', 'symfony-native-tools-poster.jpg', 'profiler-authorized.png', 'profiler-denied.png', 'video-proof.json'] as $name) {
            $expected[] = 'media/'.$name;
        }
        foreach (['skills-proof', 'refund-protocol', 'refund-skill', 'editorial-groups', 'editorial-groups-footprint', 'editorial-audit-replay', 'editorial-review', 'editorial-review-agent', 'report', 'security-report', 'agent-report', 'boundary-report', 'huggingface-report', 'huggingface-comparison', 'adoption-report', 'evaluation-report', 'performance-report', 'performance-conversation', 'performance-baseline', 'response-payloads', 'audit-three-way', 'catalog-proof', 'native-catalog', 'http-catalog', 'catalog-scaling'] as $name) {
            $expected[] = 'evidence/'.$name.'.json';
        }
        sort($expected);
        $this->assertSame($expected, $files);
        $this->assertStringContainsString('href="images/symfony-mcp-architecture.svg"', $html);
        $this->assertStringContainsString('src="images/symfony-mcp-architecture.svg"', $html);
        $this->assertFileEquals($root.'/docs/images/symfony-mcp-architecture.svg', $root.'/var/pages/images/symfony-mcp-architecture.svg');
        $this->assertStringContainsString('](docs/images/symfony-mcp-architecture.svg)](https://ronaldtebrake.github.io/symfony-hackathon/#mcp-architecture)', file_get_contents($root.'/README.md'));
        $this->assertStringContainsString('href="evidence/skills-proof.json"', $html);
        foreach (['refund-skill', 'refund-protocol'] as $export) {
            $this->assertStringContainsString('href="evidence/'.$export.'.json"', $html);
            $this->assertFileEquals($root.'/docs/evidence/'.$export.'.json', $root.'/var/pages/evidence/'.$export.'.json');
        }
        $skills = json_decode(file_get_contents($root.'/var/pages/evidence/skills-proof.json'), true, flags: \JSON_THROW_ON_ERROR);
        foreach (['http' => 'skills-http', 'sameKernel' => 'skills-kernel-reuse', 'agent' => 'skills-agent', 'security' => 'workflow-security'] as $key => $file) {
            $this->assertSame(json_decode(file_get_contents($root.'/docs/evidence/'.$file.'.json'), true, flags: \JSON_THROW_ON_ERROR), $skills[$key]);
        }
        $this->assertFileDoesNotExist($root.'/var/pages/evidence/discovery-report.json');
        $this->assertStringNotContainsString('compact-discovery', $html);
        $http = json_decode(file_get_contents($root.'/var/pages/evidence/http-catalog.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertTrue($http['nativeCodeMode']['attempts'][0]['verification']['passed']);
        $this->assertTrue($http['nativeCodeMode']['attempts'][0]['historicalSourceMatches']);
        $this->assertFalse($http['nativeCodeMode']['attempts'][0]['currentSourceMatches']);
        $this->assertCount(5, $http['nativeCodeMode']['attempts'][0]['code']);
        $this->assertCount(2, $http['claudeComparison']['attempts']);
        $this->assertTrue($http['claudeComparison']['pairs'][0]['matched']);
        $this->assertSame([44131, 73251], array_column(array_column($http['claudeComparison']['attempts'], 'usage'), 'total'));
        $this->assertStringContainsString('value="claude-comparison"', $html);
        $this->assertTrue($http['claudeAudit']['matched']);
        $this->assertCount(8, $http['claudeAudit']['attempts']);
        $this->assertSame([true, true], array_column($http['claudeAudit']['iterations'], 'matched'));
        $this->assertSame([true, true, false, true, true, true, true, true], array_column(array_column($http['claudeAudit']['attempts'], 'verification'), 'passed'));
        $this->assertStringContainsString('value="claude-audit"', $html);
        $this->assertTrue($http['claudeAudit']['fieldSelection']['verified']);
        $this->assertTrue($http['claudeAudit']['fieldSelectionPair']['matched']);
        $this->assertStringContainsString('value="claude-fields"', $html);

        $document = new \DOMDocument();
        $document->loadHTML($html, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $refundStudy = json_decode(file_get_contents($root.'/var/pages/evidence/refund-skill.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame($refundStudy['protocol']['questions']['review'], $xpath->evaluate('string(//*[@id="refund-question"]/p)'));
        $chapter = $xpath->query('//*[@id="documentation"]/section[@id="mcp-paths"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $chapter, 'The hash router opens only direct chapter sections.');
        $this->assertSame('mcp-paths-heading', $chapter->getAttribute('aria-labelledby'));
        $this->assertSame(1, $xpath->query('.//*[@id="mcp-paths-heading"]', $chapter)->length);
        $this->assertSame(1, $xpath->query('.//select[@id="mcp-client-mode"]', $chapter)->length);
        $this->assertSame(1, $xpath->query('.//*[@id="mcp-path-metrics"]', $chapter)->length);
        $this->assertSame(0, $xpath->query('//nav//a[@href="#mcp-paths"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="demo-tour"]//video/source[@src="media/editorial-review-90s.mp4?v=11"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="demo-tour"]//*[@id="editorial-checkpoint"]//a[@href="evidence/editorial-audit-replay.json"]')->length);
        $this->assertSame(['revoked', 'control'], array_map(static fn (\DOMElement $option): string => $option->getAttribute('value'), iterator_to_array($xpath->query('//*[@id="editorial-replay-run"]/option'))));
        $this->assertFileEquals($root.'/docs/evidence/editorial-audit-replay.json', $root.'/var/pages/evidence/editorial-audit-replay.json');
        foreach (['editorial-groups', 'editorial-groups-footprint'] as $report) {
            $this->assertSame(1, $xpath->query('//*[@id="publishing-group"]//a[@href="evidence/'.$report.'.json"]')->length);
            $this->assertFileEquals($root.'/docs/evidence/'.$report.'.json', $root.'/var/pages/evidence/'.$report.'.json');
        }
        $this->assertGreaterThan(0, $xpath->query('//*[@id="how-it-works"]//a[@href="?client=symfony-ai#native-rehearsal"]')->length);
        foreach (['?client=claude-audit#native-rehearsal', '?client=claude-fields#native-rehearsal'] as $href) {
            $this->assertGreaterThan(0, $xpath->query('.//a[@href="'.$href.'"]', $chapter)->length, 'Keep evidence links relative to the Pages deployment.');
        }
    }

    public function testVideoChaptersAndClaimsMatchTheirSeparateCaptures(): void
    {
        $root = \dirname(__DIR__, 2);
        $video = json_decode(file_get_contents($root.'/public/media/video-proof.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('](https://ronaldtebrake.github.io/symfony-hackathon/#demo-tour)', file_get_contents($root.'/README.md'));
        $this->assertSame($video['assets']['symfony-native-tools-demo-90s.mp4'], $video['readmeAttachment']['sha256']);
        $this->assertSame(2700, $video['frames']);
        $this->assertSame(90, $video['durationSeconds']);
        $this->assertSame('none', $video['audio']);
        $this->assertSame(hash_file('sha256', $root.'/docs/evidence/catalog-scaling.json'), $video['schemaScaling']['captureSha256']);
        $scaling = \App\Demo\CatalogScalingProof::load($root);
        $this->assertSame(array_column($scaling['samples'], 'availableTools'), $video['schemaScaling']['availableTools']);
        $this->assertSame(array_column(array_column($scaling['samples'], 'catalog'), 'tokens'), $video['schemaScaling']['catalogTokens']);
        preg_match_all('/start:(\d+), end:(\d+)/', file_get_contents($root.'/public/docs.js'), $timings, \PREG_SET_ORDER);
        $this->assertSame(array_map(static fn (array $chapter): array => [$chapter['start'], $chapter['end']], $video['chapters']),
            array_map(static fn (array $match): array => [(int) $match[1], (int) $match[2]], $timings));
        $binding = $video['liveHttpConversation'];
        $this->assertSame(hash_file('sha256', $root.'/docs/evidence/http-conversation.json'), $binding['captureSha256']);
        $attempts = \App\Demo\HttpConversation\Proof::load($root)['attempts'];
        $this->assertCount($binding['retainedAttempts'], $attempts);
        $runs = array_values(array_filter($attempts, static fn (array $run): bool => $run['id'] === $binding['attemptId']));
        $this->assertCount(1, $runs);
        $run = $runs[0];
        $this->assertTrue($run['verification']['passed']);
        $this->assertTrue($run['historicalSourceMatches']);
        $this->assertFalse($run['currentSourceMatches']);
        $this->assertSame($run['trace']['providerTokens'], $binding['providerTokens']);
        $this->assertSame($run['trace']['modelRequests'], $binding['modelRequests']);
        $this->assertSame(\count($run['trace']['agentCalls']), $binding['mcpCalls']);
        $this->assertSame($run['model'], $binding['model']);
        $this->assertSame($run['protocol'], $binding['protocol']);
        $this->assertSame([2, 3], array_column($binding['stages'], 'httpToolCallIndex'));
        $this->assertSame($video['chapters'][7]['start'], $binding['stages'][0]['start']);
        $this->assertSame($binding['stages'][0]['end'], $binding['stages'][1]['start']);
        $this->assertSame($video['chapters'][7]['end'], $binding['stages'][1]['end']);
        foreach ($video['assets'] as $asset => $hash) {
            $this->assertSame($hash, hash_file('sha256', $root.'/public/media/'.$asset));
        }
        $requests = array_column($video['development']['requests'], null, 'label');
        $this->assertSame($requests['authorized']['request']['params'], $requests['denied']['request']['params']);
        $this->assertSame('A Symfony application owns its tools', $requests['authorized']['response']['result']['structuredContent']['results'][0]['value']['title']);
        $denied = $requests['denied']['response']['result']['structuredContent'];
        $this->assertSame('tool_denied', $denied['error']['reason']);
        $this->assertSame(0, $denied['completedCalls']);
        $this->assertArrayNotHasKey('results', $denied);
        $this->assertSame(['requested', 'succeeded'], array_column($requests['authorized']['operations'][0]['nativeEvents'], 'phase'));
        $this->assertSame([], $requests['denied']['operations'][0]['nativeEvents']);
        $this->assertSame(2, $requests['denied']['operations'][0]['budget'][2]['attempts']);
        $this->assertSame([], $requests['hidden']['response']['result']['structuredContent']['tools']);
        foreach ($video['development']['checks'] as $label => $passed) {
            $this->assertTrue($passed, $label);
        }
        // The movie preserves its recorded revision, even when current source is reformatted.
        // HttpCatalogProofTest separately verifies a fresh execution against current source.
        $this->assertSame(array_keys($video['development']['sourceSha256']), array_keys($video['development']['sourceSnapshots']));
        foreach ($video['development']['sourceSha256'] as $file => $hash) {
            $this->assertSame($hash, hash('sha256', $video['development']['sourceSnapshots'][$file]));
        }
        $audit = (new ReportStore($root, reviewedOnly: true))->readThreeWayAudit();
        $matching = array_values(array_filter($audit['attempts'], static fn (array $attempt): bool => $attempt['protocol'] === $video['historicalAudit']['protocol'] && $attempt['createdAt'] === $video['historicalAudit']['createdAt']));
        $this->assertCount(1, $matching);
        $this->assertTrue($matching[0]['verification']['passed']);
        foreach ($video['historicalAudit']['providerTokens'] as $arm => $tokens) {
            $this->assertSame($matching[0]['runs'][$arm]['result']['providerTokens']['total'], $tokens);
        }
    }
}
