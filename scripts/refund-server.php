<?php

declare(strict_types=1);

use App\Demo\Refund\Gateway;
use App\Demo\Refund\Kernel;
use App\Demo\Refund\RecordingProfiler;
use CodeMode\Symfony\Profiler\CatalogTrace;
use Composer\Autoload\ClassLoader;
use Symfony\Component\HttpFoundation\Request;

if ('cli-server' !== \PHP_SAPI) {
    throw new RuntimeException('Start the loopback refund fixture through its capture command.');
}
require dirname(__DIR__).'/vendor/autoload.php';
// Only this isolated process loads the reviewed Skills draft. The main app keeps its installed SDK.
$draft = getenv('REFUND_SDK');
if (false !== $draft && '' !== $draft) {
    $loader = new ClassLoader();
    $loader->setPsr4('Mcp\\', $draft.'/src');
    $loader->register(true);
    if (realpath($draft.'/src/Server.php') !== (new ReflectionClass(Mcp\Server::class))->getFileName()) {
        throw new RuntimeException('Unexpected SDK loaded.');
    }
}

$directory = getenv('REFUND_DIRECTORY');
$kernel = new Kernel($directory, getenv('REFUND_MODE'));
$request = Request::createFromGlobals();
$started = hrtime(true);
$response = $kernel->handle($request);
$profileToken = is_file($directory.'/collect-profiles')
    ? RecordingProfiler::capture($directory, $request, $response, $kernel->getContainer()->get(CatalogTrace::class), (hrtime(true) - $started) / 1e6)
    : null;
$trace = $kernel->getContainer()->get(Gateway::class)->trace();
file_put_contents($directory.'/trace.json', json_encode($trace, \JSON_THROW_ON_ERROR), \LOCK_EX);
$response->send();
$kernel->terminate($request, $response);
