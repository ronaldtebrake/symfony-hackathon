<?php

declare(strict_types=1);

use App\Demo\BackendFactory;
use App\Demo\GroupSelection\Proof;
use App\Demo\GroupSelection\Recording;
use App\Demo\GroupSelection\Study;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
ini_set('zend.exception_ignore_args', '1');
if ('--verify' === ($argv[1] ?? '') && $argc <= 3) {
    $file = $argv[2] ?? $root.'/docs/evidence/group-selection.json';
    Proof::verify(json_decode(file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR));
    echo "Verified selection, schema disclosure, actual HTTP calls, answers and provider usage.\n";

    return;
}
$profile = 2 === $argc && '--profile' === ($argv[1] ?? '');
$sdk = null;
if (!$profile && ('--live' !== ($argv[1] ?? '') || 3 !== $argc || false === ($sdk = realpath($argv[2])) || !is_file($sdk.'/vendor/autoload.php'))) {
    fwrite(\STDERR, "Usage: php scripts/compare-group-selection.php --verify [capture.json] | --profile | --live /path/to/installed-skills-sdk\n");
    exit(2);
}
(new Dotenv())->bootEnv($root.'/.env');
$key = BackendFactory::env('HUGGINGFACE_API_KEY');
if ('' === trim($key)) {
    fwrite(\STDERR, "Set HUGGINGFACE_API_KEY in the ignored local environment.\n");
    exit(2);
}
umask(0077);
$id = gmdate('Ymd-His').'-'.bin2hex(random_bytes(5));
if ($profile) {
    $directory = $root.'/var/demo/group-selection-profiler/'.$id;
    echo 'Private filming capture: '.$directory."\n";
    Recording::capture($root, $key, $directory, static function (string $url): void {
        echo 'Follow this run in Symfony Profiler: '.$url."\n";
        flush();
    });
    echo "Retained live conversation and native Symfony Profiler panels.\n";

    return;
}
$path = $root.'/var/demo/group-selection/'.$id.'.json';
$fs = new Filesystem();
$report = ['format' => 'symfony-group-selection-v1', 'protocol' => Study::protocol(), 'attempts' => []];
$save = static function () use ($fs, &$report, $key, $path): void {
    $json = json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
    if (strlen($json) > 4 * 1048576 || str_contains($json, $key) || str_contains($json, 'Bearer ')) {
        throw new RuntimeException('Refusing unsafe capture.');
    }
    $fs->dumpFile($path, $json);
};
$save();
echo 'Capture: '.substr($path, strlen($root) + 1)."\n";
foreach (Study::ORDER as [$case, $arm]) {
    $attempt = Study::attempt($root, $sdk, $case, $arm, $key);
    $report['attempts'][] = ['run' => $id, 'capturedAt' => gmdate(\DATE_ATOM)] + $attempt;
    $save();
    echo $case.' '.$arm.' '.$attempt['status'].' ('.$attempt['providerRequests']." model requests)\n";
}
Proof::verify($report);
exit(in_array('failed', array_column($report['attempts'], 'status'), true) ? 1 : 0);
