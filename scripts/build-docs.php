<?php

declare(strict_types=1);

// No Kernel, Dotenv, live reports, Docker or inference. Publish only reviewed fixture evidence.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Controller\DemoController;
use App\Demo\ReportStore;
use Symfony\Component\HttpFoundation\Request;

$root = dirname(__DIR__);
$output = $root.'/var/pages';
// Clear only this fixed generated directory, so stale files cannot enter the Pages artifact.
(new Symfony\Component\Filesystem\Filesystem())->remove($output);
foreach (['', '/evidence', '/images', '/media'] as $directory) {
    if (!is_dir($output.$directory) && !mkdir($output.$directory, 0755, true)) {
        throw new RuntimeException('Cannot create documentation output.');
    }
}
$controller = new DemoController(new ReportStore($root, reviewedOnly: true), $root);
$request = Request::create('http://localhost/demo', server: ['REMOTE_ADDR' => '127.0.0.1']);
$reports = [
    'skills-proof' => 'skillsProof', 'refund-protocol' => 'refundProtocol', 'refund-skill' => 'refundSkill',
    'editorial-audit-replay' => 'editorialAuditReplay', 'editorial-groups' => 'editorialGroups',
    'editorial-groups-footprint' => 'editorialGroupsFootprint',
    'editorial-review' => 'editorialReview', 'editorial-review-agent' => 'editorialReviewAgent', 'catalog-scaling' => 'catalogScaling',
    'report' => 'report', 'security-report' => 'securityReport', 'agent-report' => 'codexReport',
    'boundary-report' => 'boundaryReport', 'huggingface-report' => 'huggingFaceReport',
    'huggingface-comparison' => 'huggingFaceComparison', 'adoption-report' => 'adoptionReport',
    'evaluation-report' => 'evaluationReport', 'audit-three-way' => 'threeWayAudit', 'catalog-proof' => 'catalogProof', 'native-catalog' => 'nativeCatalog', 'http-catalog' => 'httpCatalog',
    'performance-report' => 'performanceReport', 'performance-conversation' => 'performanceConversation', 'performance-baseline' => 'performanceBaseline', 'response-payloads' => 'responsePayloads',
];
foreach ($reports as $filename => $method) {
    $response = $controller->$method($request);
    $body = $response->getContent();
    $report = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
    if (200 !== $response->getStatusCode() || 'empty' === ($report['status'] ?? null)) {
        throw new RuntimeException('Missing reviewed evidence: '.$filename);
    }
    if ('editorial-review' === $filename && ('symfony-editorial-review/v1' !== ($report['format'] ?? null) || true !== ($report['verified'] ?? null))) {
        throw new RuntimeException('The editorial reviewer evidence failed verification.');
    }
    if ('editorial-review-agent' === $filename && ('symfony-editorial-review-agent-v1' !== ($report['format'] ?? null) || 4 !== count($report['turns'] ?? []))) {
        throw new RuntimeException('Incomplete reviewer conversation.');
    }
    if ('refund-protocol' === $filename) {
        App\Demo\Refund\ProtocolProof::verify($report, $root);
    }
    if ('refund-skill' === $filename) {
        App\Demo\RefundStudy\Proof::verify($report, $root);
    }
    if ('editorial-audit-replay' === $filename) {
        App\Demo\EditorialAuditReplay::verify($report);
    }
    if ('editorial-groups' === $filename) {
        App\Demo\EditorialGroupsProof::verify($report, $root);
    }
    if ('editorial-groups-footprint' === $filename && hash_file('sha256', $root.'/docs/evidence/editorial-groups.json') !== ($report['captureSha256'] ?? null)) {
        throw new RuntimeException('Discovery token counts must match the reviewed Groups capture.');
    }
    if (in_array($filename, ['native-catalog', 'http-catalog'], true) && true !== ($report['verification']['passed'] ?? null)) {
        throw new RuntimeException('Native catalog rehearsal failed verification.');
    }
    if ('http-catalog' === $filename && [] === array_filter($report['nativeCodeMode']['attempts'] ?? [], static fn ($run) => $run['verification']['passed'] && ($run['historicalSourceMatches'] || $run['currentSourceMatches']))) {
        throw new RuntimeException('No verified native Code Mode capture matches a reviewed application revision.');
    }
    if ('http-catalog' === $filename && ([] === ($report['claudeComparison']['attempts'] ?? []) || [] === array_filter($report['claudeComparison']['pairs'] ?? [], static fn ($pair) => $pair['matched']))) {
        throw new RuntimeException('Missing matched Claude comparison evidence.');
    }
    if (false === file_put_contents($output.'/evidence/'.$filename.'.json', $body)) {
        throw new RuntimeException('Cannot write documentation evidence.');
    }
}
$html = file_get_contents($root.'/templates/demo.html');
$html = str_replace(['data-static="false"', 'href="/demo.css"', 'src="/demo.js"', 'src="/docs.js"', 'src="/editorial-review.js"', 'src="/catalog-proof.js"', 'src="/catalog-scaling.js"', 'src="/php-syntax.js"', 'src="/images/', 'href="/images/', 'src="/media/', 'href="/media/', 'poster="/media/'],
    ['data-static="true"', 'href="demo.css"', 'src="demo.js"', 'src="docs.js"', 'src="editorial-review.js"', 'src="catalog-proof.js"', 'src="catalog-scaling.js"', 'src="php-syntax.js"', 'src="images/', 'href="images/', 'src="media/', 'href="media/', 'poster="media/'], $html);
$html = preg_replace('#href="/demo/([a-z-]+)"#', 'href="evidence/$1.json"', $html);
// The static host cannot execute PHP or contact the local MCP server.
$html = str_replace('<meta name="viewport"', '<meta http-equiv="Content-Security-Policy" content="default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\'; object-src \'none\'; base-uri \'none\'; form-action \'none\'">'."\n  ".'<meta name="viewport"', $html);
file_put_contents($output.'/index.html', $html);
foreach (['demo.css', 'demo.js', 'docs.js', 'editorial-review.js', 'catalog-proof.js', 'catalog-scaling.js', 'php-syntax.js'] as $file) {
    copy($root.'/public/'.$file, $output.'/'.$file);
}
foreach (['code-mode-value.svg', 'symfony-mcp-architecture.svg'] as $file) {
    if (!copy($root.'/docs/images/'.$file, $output.'/images/'.$file)) {
        throw new RuntimeException('Cannot export documentation image: '.$file);
    }
}
// Publish only the curated edit and its reviewed inserts, never raw recordings or profiler storage.
$films = [
    'server-code-mode-film.json' => ['server-code-mode-90s.mp4', 'server-code-mode-poster.jpg'],
    'video-proof.json' => ['symfony-native-tools-demo-90s.mp4', 'symfony-native-tools-poster.jpg', 'profiler-authorized.png', 'profiler-denied.png'],
    'editorial-review-film.json' => ['editorial-review-90s.mp4', 'editorial-review-poster.jpg'],
];
foreach ($films as $manifest => $media) {
    $video = json_decode(file_get_contents($root.'/public/media/'.$manifest), true, flags: \JSON_THROW_ON_ERROR);
    if (90 !== ($video['durationSeconds'] ?? null) || 'none' !== ($video['audio'] ?? null)) {
        throw new RuntimeException('Unexpected film format: '.$manifest);
    }
    if ('server-code-mode-film.json' === $manifest) {
        $sources = array_column($video['evidence'], 'sha256', 'file');
        foreach (['codex-conversation-2026-11-01', 'huggingface-pair', 'security-proof-2026-11-01'] as $capture) {
            $file = 'docs/evidence/'.$capture.'.json';
            if (hash_file('sha256', $root.'/'.$file) !== ($sources[$file] ?? null)) {
                throw new RuntimeException('The Code Mode film differs from the published capture: '.$capture);
            }
        }
    }
    if ('editorial-review-film.json' === $manifest) {
        $sources = [
            'catalogFilm' => 'public/media/symfony-native-tools-demo-90s.mp4',
            'catalogFilmProvenance' => 'public/media/video-proof.json',
            'sandboxAudit' => 'docs/evidence/huggingface-pair.json',
            'httpCatalog' => 'docs/evidence/http-catalog-proof.json',
            'catalogScaling' => 'docs/evidence/catalog-scaling.json',
            'refundProtocol' => 'docs/evidence/refund-protocol.json',
            'refundStudy' => 'docs/evidence/refund-skill.json',
        ];
        foreach ($sources as $key => $file) {
            if ($file !== ($video[$key]['file'] ?? null) || hash_file('sha256', $root.'/'.$file) !== ($video[$key]['sha256'] ?? null)) {
                throw new RuntimeException('The reviewer film differs from its source: '.$file);
            }
        }
    }
    foreach ($media as $file) {
        if (($video['assets'][$file] ?? null) !== hash_file('sha256', $root.'/public/media/'.$file)) {
            throw new RuntimeException('Film asset failed verification: '.$file);
        }
        copy($root.'/public/media/'.$file, $output.'/media/'.$file);
    }
    copy($root.'/public/media/'.$manifest, $output.'/media/'.$manifest);
}
file_put_contents($output.'/.nojekyll', '');
echo 'Built visual documentation in var/pages using '.count($reports)." reviewed evidence exports.\n";
