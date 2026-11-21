<?php

declare(strict_types=1);

use App\Controller\DemoController;
use App\Demo\RefundRecording\ProgrammaticProof;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
ProgrammaticProof::verify(json_decode(file_get_contents($root.'/docs/evidence/programmatic-refund.json'), true, flags: \JSON_THROW_ON_ERROR));
$output = $root.'/var/pages';
$files = new Filesystem();
$mediaDir = $root.'/public/media';
$judge = json_decode(file_get_contents($mediaDir.'/symfony-code-mode-judge-film.json'), true, flags: \JSON_THROW_ON_ERROR);
$narration = 'docs/judge-workflow-voiceover.txt';
if ($narration !== $judge['narration']['file'] || hash_file('sha256', $root.'/'.$narration) !== $judge['narration']['sha256']) {
    throw new RuntimeException('The narration differs from the judge film manifest.');
}
$media = ['symfony-code-mode-judge-90s.mp4', 'symfony-code-mode-judge-poster.jpg'];
foreach ($media as $asset) {
    if (hash_file('sha256', $mediaDir.'/'.$asset) !== $judge['assets'][$asset]) {
        throw new RuntimeException($asset.' differs from the judge film manifest.');
    }
}
$request = Request::create('http://localhost/demo', server: ['REMOTE_ADDR' => '127.0.0.1']);
$page = (new DemoController($root))->index($request);
$files->remove($output);
$files->dumpFile($output.'/index.html', $page->getContent());
$files->dumpFile($output.'/.nojekyll', '');
foreach ([
    'demo.css' => 'public/demo.css',
    'images/symfony-mcp-profiler.png' => 'docs/images/symfony-mcp-profiler.png',
    'images/symfony-mcp-architecture.svg' => 'docs/images/symfony-mcp-architecture.svg',
    'media/judge-workflow-voiceover.txt' => $narration,
] as $path => $source) {
    $files->copy($root.'/'.$source, $output.'/'.$path);
}
foreach ([...$media, 'symfony-code-mode-judge-film.json'] as $filename) {
    $files->copy($mediaDir.'/'.$filename, $output.'/media/'.$filename);
}
foreach (['agent-procedure.json', 'judge-demo.json', 'catalog-scaling.json', 'refund-group-discovery.json', 'direct-tool.json', 'programmatic-refund.json', 'claude-structured-refund.json', 'group-selection.json'] as $filename) {
    $files->copy($root.'/docs/evidence/'.$filename, $output.'/evidence/'.$filename);
}
echo "Built the judge workbench in var/pages.\n";
