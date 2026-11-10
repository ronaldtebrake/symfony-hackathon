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
foreach (['', '/evidence', '/images'] as $directory) {
    if (!is_dir($output.$directory) && !mkdir($output.$directory, 0755, true)) {
        throw new RuntimeException('Cannot create documentation output.');
    }
}
$controller = new DemoController(new ReportStore($root, reviewedOnly: true), $root);
$request = Request::create('http://localhost/demo', server: ['REMOTE_ADDR' => '127.0.0.1']);
$reports = [
    'report' => 'report', 'security-report' => 'securityReport', 'agent-report' => 'codexReport',
    'boundary-report' => 'boundaryReport', 'huggingface-report' => 'huggingFaceReport',
    'huggingface-comparison' => 'huggingFaceComparison', 'adoption-report' => 'adoptionReport',
    'evaluation-report' => 'evaluationReport', 'audit-three-way' => 'threeWayAudit', 'catalog-proof' => 'catalogProof', 'native-catalog' => 'nativeCatalog',
    'performance-report' => 'performanceReport', 'performance-conversation' => 'performanceConversation', 'performance-baseline' => 'performanceBaseline', 'response-payloads' => 'responsePayloads',
];
foreach ($reports as $filename => $method) {
    $response = $controller->$method($request);
    $body = $response->getContent();
    $report = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    if (200 !== $response->getStatusCode() || 'empty' === ($report['status'] ?? null)) {
        throw new RuntimeException('Missing reviewed evidence: '.$filename);
    }
    if ('native-catalog' === $filename && true !== ($report['verification']['passed'] ?? null)) {
        throw new RuntimeException('Native catalog rehearsal failed verification.');
    }
    if (false === file_put_contents($output.'/evidence/'.$filename.'.json', $body)) {
        throw new RuntimeException('Cannot write documentation evidence.');
    }
}
$html = file_get_contents($root.'/templates/demo.html');
$html = str_replace(['data-static="false"', 'href="/demo.css"', 'src="/demo.js"', 'src="/docs.js"', 'src="/catalog-proof.js"', 'src="/php-syntax.js"', 'src="/images/'],
    ['data-static="true"', 'href="demo.css"', 'src="demo.js"', 'src="docs.js"', 'src="catalog-proof.js"', 'src="php-syntax.js"', 'src="images/'], $html);
$html = preg_replace('#href="/demo/([a-z-]+)"#', 'href="evidence/$1.json"', $html);
// The static host cannot execute PHP or contact the local MCP server.
$html = str_replace('<meta name="viewport"', '<meta http-equiv="Content-Security-Policy" content="default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\'; object-src \'none\'; base-uri \'none\'; form-action \'none\'">' . "\n  " . '<meta name="viewport"', $html);
file_put_contents($output.'/index.html', $html);
foreach (['demo.css', 'demo.js', 'docs.js', 'catalog-proof.js', 'php-syntax.js'] as $file) { copy($root.'/public/'.$file, $output.'/'.$file); }
copy($root.'/docs/images/code-mode-value.svg', $output.'/images/code-mode-value.svg');
file_put_contents($output.'/.nojekyll', '');
echo "Built visual documentation in var/pages using ".count($reports)." reviewed evidence exports.\n";
