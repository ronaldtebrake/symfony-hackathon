<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/src/ObservedProvider.php';
require __DIR__.'/src/BatchComparison.php';
require __DIR__.'/src/BatchComparisonVerifier.php';

use CodeMode\DockerEngine;
use SurfaceProof\{BatchComparison, Connection};

if (array_diff(array_slice($argv, 1), ['--batch-only'])) { throw new InvalidArgumentException('Only --batch-only is supported.'); }
umask(0077);
if (!is_dir(__DIR__.'/var')) { mkdir(__DIR__.'/var', 0700, true); }
$lock = fopen(__DIR__.'/var/proof.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Another Data Surface proof is active.'); }
$env = static fn ($key, $default = '') => (string) ($_SERVER[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default);
try {
    $batchOnly = in_array('--batch-only', $argv, true);
    // This branch does not read runner settings or instantiate a Docker client.
    $docker = $batchOnly ? null : new DockerEngine(json_decode($env('CODE_MODE_DOCKER_COMMAND', '["docker"]'), true, flags: JSON_THROW_ON_ERROR));
    $report = (new BatchComparison())->run(new Connection($env('SURFACE_PROOF_SITE')), $docker, $batchOnly ? '' : $env('CODE_MODE_IMAGE'));
    $path = __DIR__.'/var/batch-comparison-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", LOCK_EX);
    foreach ($report['verification'] as $name => $ok) { echo ($ok ? 'PASS ' : 'FAIL ').$name."\n"; }
    echo 'Saved '.$path."\n";
} finally { flock($lock, LOCK_UN); fclose($lock); }
exit('complete' === ($report['status'] ?? null) ? 0 : 1);
