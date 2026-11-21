<?php

declare(strict_types=1);

use App\Controller\DemoController;
use App\Demo\RefundRecording\ProgrammaticProof;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
ProgrammaticProof::verify(json_decode(file_get_contents($root.'/docs/evidence/programmatic-refund.json'), true, flags: \JSON_THROW_ON_ERROR));
$output = $root.'/var/pages';
$files = new Filesystem();
$mediaDir = $root.'/public/media';
$judge = json_decode(file_get_contents($mediaDir.'/symfony-code-mode-judge-film.json'), true, flags: \JSON_THROW_ON_ERROR);
$ready = json_decode(file_get_contents($mediaDir.'/symfony-code-mode-ready-film.json'), true, flags: \JSON_THROW_ON_ERROR);
$plain = json_decode(file_get_contents($mediaDir.'/symfony-code-mode-plain-film.json'), true, flags: \JSON_THROW_ON_ERROR);
$support = json_decode(file_get_contents($mediaDir.'/symfony-code-mode-support-film.json'), true, flags: \JSON_THROW_ON_ERROR);
$groupEvidence = ['catalog-scaling.json', 'refund-group-discovery.json'];
foreach ([
    ['symfony-code-mode-ready-film/v1', $ready, 'claude-structured-refund.json', []],
    ['symfony-plain-claude-film/v1', $plain, 'claude-plain-launch.json', $groupEvidence],
    ['symfony-support-story-film/v1', $support, 'claude-support-review.json', $groupEvidence],
] as [$format, $cut, $receipt, $additionalEvidence]) {
    if ($format !== $cut['format'] || 90 !== $cut['durationSeconds'] || 25 !== $cut['fps'] || 1920 !== $cut['width'] || 1080 !== $cut['height']) {
        throw new RuntimeException('Unexpected film format in '.$format.'.');
    }
    if ('docs/evidence/'.$receipt !== $cut['capture']['receipt']['file']) {
        throw new RuntimeException('Unexpected Claude session in '.$format.'.');
    }
    foreach ([$receipt, ...$additionalEvidence] as $filename) {
        $path = 'docs/evidence/'.$filename;
        if (hash_file('sha256', $root.'/'.$path) !== ($cut['sources'][$path] ?? null)) {
            throw new RuntimeException('The film cites a different capture: '.$path);
        }
    }
}
$structured = json_decode(file_get_contents($root.'/docs/evidence/claude-structured-refund.json'), true, flags: \JSON_THROW_ON_ERROR);
$recorded = $ready['capture']['receipt'];
if (3 !== $recorded['attempt'] || 'verified' !== $structured['attempts'][2]['status'] || $recorded['programId'] !== $structured['attempts'][2]['programId']) {
    throw new RuntimeException('The judge film must identify the verified third Claude program.');
}
$film = json_decode(file_get_contents($mediaDir.'/symfony-code-mode-film.json'), true, flags: \JSON_THROW_ON_ERROR);
if (90 !== $film['durationSeconds'] || 25 !== $film['fps'] || 2250 !== $film['frames'] || [1536, 864] !== $film['resolution'] || 'none' !== $film['audio']) {
    throw new RuntimeException('Unexpected judge film format.');
}
$editorial = json_decode(file_get_contents($mediaDir.'/editorial-review-film.json'), true, flags: \JSON_THROW_ON_ERROR);
$codex = json_decode(file_get_contents($mediaDir.'/codex-refund-review-film.json'), true, flags: \JSON_THROW_ON_ERROR);
if (($film['receipt'] ?? null) !== $codex['receipt']) {
    throw new RuntimeException('The judge film receipt differs from the recorded Codex MCP calls.');
}
$scale = $film['catalogScaleCapture'] ?? [];
$scaleCommand = 'scripts/check-catalog-size.php';
foreach ([$scaleCommand, 'src/Demo/CatalogScalingProof.php', 'docs/evidence/catalog-scaling.json'] as $file) {
    if (($scale['sourceSha256'][$file] ?? null) !== hash_file('sha256', $root.'/'.$file)) {
        throw new RuntimeException('The catalog scaling source differs from the opening shot: '.$file);
    }
}
$scaleOutput = (new Process([\PHP_BINARY, $scaleCommand], $root))->mustRun()->getOutput();
if (['php', $scaleCommand] !== ($scale['command'] ?? null) || preg_split('/\R/', rtrim($scaleOutput, "\r\n")) !== ($scale['stdout'] ?? null)) {
    throw new RuntimeException('The catalog scaling command no longer reproduces the opening shot.');
}
$narration = 'docs/judge-voiceover.txt';
$liveFilm = json_decode(file_get_contents($mediaDir.'/symfony-code-mode-live-film.json'), true, flags: \JSON_THROW_ON_ERROR);
if (90 !== $liveFilm['durationSeconds'] || 'none' !== $liveFilm['audio']) {
    throw new RuntimeException('Unexpected live CLI film format.');
}
$terminalFilm = json_decode(file_get_contents($mediaDir.'/symfony-code-mode-terminal-film.json'), true, flags: \JSON_THROW_ON_ERROR);
if (90 !== $terminalFilm['durationSeconds'] || 'none' !== $terminalFilm['audio']) {
    throw new RuntimeException('Unexpected terminal recording format.');
}
$explainer = json_decode(file_get_contents($mediaDir.'/symfony-code-mode-native-explainer-film.json'), true, flags: \JSON_THROW_ON_ERROR);
if (90 !== $explainer['durationSeconds'] || 25 !== $explainer['fps'] || 1920 !== $explainer['width'] || 1080 !== $explainer['height']) {
    throw new RuntimeException('Unexpected native explainer format.');
}
foreach (['symfony-code-mode-terminal-90s.mp4', 'symfony-code-mode-terminal-film.json', 'symfony-code-mode-terminal-full.mp4', 'symfony-code-mode-terminal-operator.mp4'] as $source) {
    $path = 'public/media/'.$source;
    if (($explainer['sources'][$path] ?? null) !== hash_file('sha256', $root.'/'.$path)) {
        throw new RuntimeException('The native explainer credits a different terminal recording: '.$path);
    }
}
$explainerNarration = 'docs/judge-native-explainer-voiceover.txt';
$readyNarration = 'docs/judge-ready-voiceover.txt';
$plainNarration = 'docs/judge-plain-voiceover.txt';
$supportNarration = 'docs/judge-support-voiceover.txt';
$liveNarration = 'docs/judge-live-voiceover.txt';
foreach (['docs/judge-workflow-voiceover.txt' => $judge['narration'], $narration => $film['narration'], $liveNarration => $liveFilm['narration'], $explainerNarration => $explainer['narration'], $readyNarration => $ready['narration'], $plainNarration => $plain['narration'], $supportNarration => $support['narration'], 'docs/evidence/claude-refund-film-session.json' => $liveFilm['session']] as $path => $source) {
    if ($path !== ($source['file'] ?? null) || hash_file('sha256', $root.'/'.$path) !== ($source['sha256'] ?? null)) {
        throw new RuntimeException($path.' differs from its film manifest.');
    }
}
$media = [
    'symfony-code-mode-judge-90s.mp4' => $judge['assets'],
    'symfony-code-mode-judge-poster.jpg' => $judge['assets'],
    'symfony-code-mode-ready-90s.mp4' => $ready['assets'],
    'symfony-code-mode-ready-poster.jpg' => $ready['assets'],
    'symfony-code-mode-plain-90s.mp4' => $plain['assets'],
    'symfony-code-mode-plain-poster.jpg' => $plain['assets'],
    'symfony-code-mode-support-90s.mp4' => $support['assets'],
    'symfony-code-mode-support-poster.jpg' => $support['assets'],
    'symfony-code-mode-90s.mp4' => $film['assets'],
    'symfony-code-mode-poster.jpg' => $film['assets'],
    'symfony-code-mode-live-90s.mp4' => $liveFilm['assets'],
    'symfony-code-mode-live-poster.jpg' => $liveFilm['assets'],
    'symfony-code-mode-native-explainer-90s.mp4' => $explainer['assets'],
    'symfony-code-mode-native-explainer-poster.jpg' => $explainer['assets'],
    'symfony-code-mode-terminal-90s.mp4' => $terminalFilm['assets'],
    'symfony-code-mode-terminal-full.mp4' => $terminalFilm['assets'],
    'symfony-code-mode-terminal-operator.mp4' => $terminalFilm['assets'],
    'symfony-code-mode-terminal-poster.jpg' => $terminalFilm['assets'],
    'editorial-review-90s.mp4' => $editorial['assets'],
    'editorial-review-poster.jpg' => $editorial['assets'],
    'codex-refund-review-film.json' => $film['sources'],
    'codex-refund-review.mp4' => $codex['assets'],
    'codex-refund-review-poster.jpg' => $codex['assets'],
];
foreach ($media as $asset => $digests) {
    if (hash_file('sha256', $mediaDir.'/'.$asset) !== ($digests[$asset] ?? null)) {
        throw new RuntimeException($asset.' differs from its film manifest.');
    }
}
if (($film['sources']['codex-refund-review.mp4'] ?? null) !== $codex['assets']['codex-refund-review.mp4']) {
    throw new RuntimeException('The judge film credits a different Codex recording.');
}
$evidence = ['agentProcedure' => 'agent-procedure.json', 'judgeDemo' => 'judge-demo.json'];
foreach ($evidence as $source => $filename) {
    $path = 'docs/evidence/'.$filename;
    if ($path !== $editorial[$source]['file'] || hash_file('sha256', $root.'/'.$path) !== $editorial[$source]['sha256']) {
        throw new RuntimeException('The film manifest does not match '.$path.'.');
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
    'media/judge-workflow-voiceover.txt' => 'docs/judge-workflow-voiceover.txt',
    'media/judge-voiceover.txt' => $narration,
    'media/judge-native-explainer-voiceover.txt' => $explainerNarration,
    'media/judge-ready-voiceover.txt' => $readyNarration,
    'media/judge-plain-voiceover.txt' => $plainNarration,
    'media/judge-support-voiceover.txt' => $supportNarration,
    'media/judge-live-voiceover.txt' => $liveNarration,
] as $path => $source) {
    $files->copy($root.'/'.$source, $output.'/'.$path);
}
foreach ([...array_keys($media), 'symfony-code-mode-judge-film.json', 'editorial-review-film.json', 'symfony-code-mode-film.json', 'symfony-code-mode-live-film.json', 'symfony-code-mode-native-explainer-film.json', 'symfony-code-mode-plain-film.json', 'symfony-code-mode-ready-film.json', 'symfony-code-mode-support-film.json', 'symfony-code-mode-terminal-film.json'] as $filename) {
    $files->copy($mediaDir.'/'.$filename, $output.'/media/'.$filename);
}
foreach ([...$evidence, ...$groupEvidence, 'direct-tool.json', 'programmatic-refund.json', 'claude-plain-launch.json', 'claude-structured-refund.json', 'claude-support-review.json', 'claude-refund-film-session.json', 'group-selection.json'] as $filename) {
    $files->copy($root.'/docs/evidence/'.$filename, $output.'/evidence/'.$filename);
}
echo "Built the judge workbench in var/pages.\n";
