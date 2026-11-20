<?php

declare(strict_types=1);

use App\Controller\DemoController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$output = $root.'/var/pages';
$files = new Filesystem();
$film = json_decode(file_get_contents($root.'/public/media/editorial-review-film.json'), true, flags: \JSON_THROW_ON_ERROR);
if (90 !== $film['durationSeconds'] || 2700 !== $film['frames'] || 'none' !== $film['audio']) {
    throw new RuntimeException('Unexpected judge film format.');
}
foreach (['editorial-review-90s.mp4', 'editorial-review-poster.jpg'] as $asset) {
    if (hash_file('sha256', $root.'/public/media/'.$asset) !== $film['assets'][$asset]) {
        throw new RuntimeException('The judge film asset differs from its recording manifest.');
    }
}
foreach (['refundProtocol' => 'refund-protocol.json', 'refundStudy' => 'refund-skill.json', 'catalogScaling' => 'catalog-scaling.json', 'httpCatalog' => 'http-catalog-proof.json'] as $source => $filename) {
    if (hash_file('sha256', $root.'/docs/evidence/'.$filename) !== $film[$source]['sha256']) {
        throw new RuntimeException('The original refund evidence differs from the judge film manifest.');
    }
}
$request = Request::create('http://localhost/demo', server: ['REMOTE_ADDR' => '127.0.0.1']);
$page = (new DemoController($root))->index($request);
$files->remove($output);
$files->dumpFile($output.'/index.html', $page->getContent());
$files->dumpFile($output.'/.nojekyll', '');
foreach ([
    'demo.css' => 'public/demo.css',
    'images/symfony-mcp-architecture.svg' => 'docs/images/symfony-mcp-architecture.svg',
    'media/editorial-review-90s.mp4' => 'public/media/editorial-review-90s.mp4',
    'media/editorial-review-poster.jpg' => 'public/media/editorial-review-poster.jpg',
    'evidence/judge-demo.json' => 'docs/evidence/judge-demo.json',
] as $path => $source) {
    $files->copy($root.'/'.$source, $output.'/'.$path);
}
echo "Built the judge workbench in var/pages.\n";
