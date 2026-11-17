<?php

declare(strict_types=1);

use App\Demo\AuditDataset;
use App\Demo\AuditMatrix\Gateway;
use App\Demo\AuditMatrix\Kernel;
use App\Demo\HttpConversation\Application;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

// The same file is a loopback router and an operator-owned disposable host.
if ('cli-server' === \PHP_SAPI) {
    $directory = (string) getenv('MATRIX_DIRECTORY');
    $kernel = new Kernel($directory, (string) getenv('MATRIX_SURFACE'));
    $request = Request::createFromGlobals();
    $response = $kernel->handle($request);
    $rpc = json_decode($request->getContent(), true);
    if (isset($rpc['id'])) {
        ob_start();
        $response->sendContent();
        $raw = ob_get_clean();
        $body = $raw;
        if (preg_match('/^data: (.+)$/m', $raw, $match)) {
            $body = $match[1];
        }
        $record = ['at' => gmdate(\DATE_ATOM), 'request' => $rpc, 'response' => json_decode($body, true),
            'httpStatus' => $response->getStatusCode(), 'trace' => $kernel->getContainer()->get(Gateway::class)->trace()];
        $encoded = json_encode($record, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (str_contains($encoded, file_get_contents($directory.'/token'))) {
            throw new RuntimeException('Credential in evidence.');
        }
        file_put_contents(getenv('MATRIX_CAPTURE').'/http.jsonl', $encoded."\n", \FILE_APPEND | \LOCK_EX);
        $response->sendHeaders();
        echo $raw;
    } else {
        $response->send();
    }
    $kernel->terminate($request, $response);

    return;
}

if (!in_array($argv[1] ?? '', ['direct', 'catalog'], true) || !isset($argv[2])) {
    fwrite(\STDERR, "Usage: php scripts/claude-audit-host.php direct|catalog capture-directory\n");
    exit(1);
}
umask(0077);
$root = dirname(__DIR__);
$surface = $argv[1];
$fs = new Filesystem();
$fs->mkdir($argv[2], 0700);
$capture = realpath($argv[2]);
if (is_file($capture.'/host.json') || is_file($capture.'/http.jsonl') || is_file($capture.'/stop')) {
    throw new RuntimeException('Use a new capture directory. Previous attempts must remain intact.');
}
$directory = sys_get_temp_dir().'/symfony-audit-matrix-'.bin2hex(random_bytes(12));
$fs->mkdir($directory, 0700);
file_put_contents($directory.'/token', bin2hex(random_bytes(32)));
file_put_contents($directory.'/permission', "granted\n");
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (false === $socket) {
    $fs->remove($directory);
    throw new RuntimeException('Cannot bind a loopback port.');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$env = array_replace(array_fill_keys(array_keys($_ENV + getenv()), false), ['PATH' => getenv('PATH'),
    'MATRIX_DIRECTORY' => $directory, 'MATRIX_SURFACE' => $surface, 'MATRIX_CAPTURE' => $capture]);
$process = new Process([\PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'disable_functions='.Application::DISABLED, '-S', $address, __FILE__], $root, $env);
$process->disableOutput();
try {
    $process->start();
    file_put_contents($capture.'/private.json', json_encode(['endpoint' => 'http://'.$address.'/mcp', 'directory' => $directory, 'token' => file_get_contents($directory.'/token')], \JSON_THROW_ON_ERROR));
    file_put_contents($capture.'/mcp.json', json_encode(['mcpServers' => ['symfony_audit' => ['type' => 'http', 'url' => 'http://'.$address.'/mcp',
        'alwaysLoad' => true, 'headers' => ['Authorization' => 'Bearer ${SYMFONY_REHEARSAL_TOKEN}']]]], \JSON_THROW_ON_ERROR));
    $source = [];
    $paths = array_merge(glob($root.'/src/Demo/AuditMatrix/*.php'), [$root.'/src/Demo/AuditDataset.php', $root.'/src/Demo/FixtureServer.php',
        $root.'/packages/php-code-mode/src/BatchSession.php', $root.'/packages/php-code-mode/src/ToolBroker.php',
        $root.'/packages/symfony-code-mode/src/Ai/ApprovedToolbox.php', $root.'/packages/symfony-code-mode/src/Ai/ApprovedTool.php',
        $root.'/packages/symfony-code-mode/src/Http/CatalogStateStore.php', $root.'/packages/symfony-code-mode/src/Ai/Search/SymfonyCatalogSearch.php',
        $root.'/packages/symfony-code-mode/src/Ai/Search/Upstream/Bm25ToolSearch.php', $root.'/composer.lock']);
    foreach ($paths as $path) {
        $source[substr($path, strlen($root) + 1)] = hash_file('sha256', $path);
    }
    $dataset = new AuditDataset();
    file_put_contents($capture.'/host.json', json_encode(['startedAt' => gmdate(\DATE_ATOM), 'sourceSha256' => $source,
        'datasetSha256' => hash('sha256', json_encode([$dataset->articles(), $dataset->media()], \JSON_THROW_ON_ERROR))], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    echo "Disposable audit HTTP app ready. Use mcp.json with the private token supplied through the environment.\n";
    echo "Create the capture directory's stop file to finish and clean up. Maximum lifetime: 45 minutes.\n";
    $until = time() + 2700;
    while (!is_file($capture.'/stop') && time() < $until && $process->isRunning()) {
        usleep(100000);
    }
} finally {
    $process->stop();
    $fs->remove($directory);
    $fs->remove($capture.'/private.json');
    file_put_contents($capture.'/cleanup.json', json_encode(['applicationRemoved' => !is_dir($directory), 'at' => gmdate(\DATE_ATOM)], \JSON_THROW_ON_ERROR));
}
