<?php

declare(strict_types=1);

use App\Demo\GroupSelection\Discovery;
use App\Demo\Refund\Fixture;
use App\Demo\Refund\Gateway;
use App\Demo\Refund\Kernel;
use App\Demo\Refund\ProgrammaticCapture;
use App\Demo\Refund\RecordingProfiler;
use App\Demo\Refund\Tools;
use App\Demo\RefundRecording\InteractiveChatCommand;
use App\Demo\RefundRecording\Proof;
use CodeMode\Symfony\Profiler\CatalogTrace;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$root = dirname(__DIR__);
if ('cli-server' === \PHP_SAPI) {
    $directory = getenv('REFUND_DIRECTORY');
    if (is_file($directory.'/revoke-after-order')) {
        require $root.'/vendor/autoload.php';
        $kernel = new Kernel($directory, 'catalog');
        $request = Request::createFromGlobals();
        $started = hrtime(true);
        $response = $kernel->handle($request);
        $container = $kernel->getContainer();
        if (is_file($directory.'/collect-profiles')) {
            RecordingProfiler::capture($directory, $request, $response, $container->get(CatalogTrace::class), (hrtime(true) - $started) / 1e6);
        }
        ProgrammaticCapture::record($directory, $request, $response, $container->get(Gateway::class)->trace()['operations']);
        $response->send();
        $kernel->terminate($request, $response);

        return;
    }
    require __DIR__.'/refund-server.php';
    /** @var Request $request */
    /** @var Response $response */
    $directory = getenv('REFUND_DIRECTORY');
    $message = json_decode($request->getContent(), true);
    if (!in_array($message['method'] ?? null, ['initialize', 'server/discover', 'tools/list', 'groups/list', 'groups/get', 'tools/call'], true)) {
        return;
    }
    $raw = (string) $response->getContent();
    if (preg_match('/^data: ?(.+)$/m', $raw, $part)) {
        $raw = $part[1];
    }
    $envelope = json_decode($raw, true);
    $reply = $envelope['result'] ?? [];
    $method = $message['method'];
    $row = ['method' => $method, 'httpStatus' => $response->getStatusCode()];
    if (!isset($envelope['result'])) {
        $row['errorCode'] = $envelope['error']['code'] ?? null;
    } elseif ('initialize' === $method) {
        $row['protocolVersion'] = $reply['protocolVersion'] ?? null;
    } elseif ('groups/list' === $method) {
        $row['groups'] = array_values(array_intersect(array_column($reply['groups'] ?? [], 'name'), array_keys(Discovery::GROUPS)));
    } elseif ('groups/get' === $method || 'tools/list' === $method) {
        $row['tools'] = array_column($reply['tools'] ?? [], 'name');
        if ('groups/get' === $method && isset($reply['tools']) && in_array($message['params']['name'] ?? null, array_keys(Discovery::GROUPS), true)) {
            $row['group'] = $message['params']['name'];
        }
    } elseif ('tools/call' === $method) {
        $params = $message['params'] ?? [];
        $name = $params['name'] ?? '';
        $row['tool'] = in_array($name, [...Tools::NAMES, ...Discovery::CATALOG], true) ? $name : '[other]';
        $args = $params['arguments'] ?? [];
        $row['arguments'] = in_array($args, [['orderId' => 'R-1042'], ['policyId' => 'standard-eu']], true) ? $args : ['redacted' => true];
        $row['isError'] = $reply['isError'] ?? false;
        if (in_array($name, Discovery::CATALOG, true) && is_array($reply['structuredContent'] ?? null)) {
            $row['structuredContentSha256'] = hash('sha256', json_encode($reply['structuredContent'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        }
        if (in_array($name, Tools::NAMES, true)) {
            $row['result'] = $reply['structuredContent'] ?? null;
        }
        $operation = $trace['operations'][0] ?? [];
        $row['status'] = $operation['status'] ?? null;
        $row['nativeEvents'] = array_column($operation['nativeEvents'] ?? [], 'phase');
        if (null !== ($profileToken ?? null)) {
            $profile = RecordingProfiler::operations($directory, $profileToken)[0] ?? [];
            $row['profiler'] = ['operation' => $profile['operation'] ?? null, 'status' => $profile['status'] ?? null,
                'reason' => $profile['reason'] ?? null, 'nativeEvents' => array_column($profile['nativeEvents'] ?? [], 'phase')];
        }
    }
    file_put_contents($directory.'/wire.jsonl', json_encode($row, \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND | \LOCK_EX);

    return;
}

require $root.'/vendor/autoload.php';
$command = $argv[1] ?? 'status';
if ('--verify' === $command) {
    Proof::verify(json_decode(file_get_contents($root.'/docs/evidence/codex-refund-fallback.json'), true, flags: \JSON_THROW_ON_ERROR));
    echo "Verified the recorded Codex refund conversation.\n";

    return;
}

umask(0077);
if ('chat' === $command || 'chat-headers' === $command) {
    try {
        if ('chat-headers' === $command) {
            InteractiveChatCommand::headers($argv[2] ?? '');

            return;
        }
        exit(InteractiveChatCommand::chat($root, $argv[2] ?? '', in_array('--revoke-after-order', $argv, true)));
    } catch (Throwable $error) {
        fwrite(\STDERR, $error->getMessage()."\n");
        exit(1);
    }
}

$rehearsal = in_array('--rehearsal', $argv, true);
$isolated = in_array('--isolated-proof', $argv, true);
$port = $isolated ? 0 : ($rehearsal ? 8017 : 8016);
$control = $root.'/var/refund-recording/'.($isolated ? 'programmatic' : ($rehearsal ? 'rehearsal' : 'recording'));
$fs = new Filesystem();
$fs->mkdir($control, 0700);
$lock = fopen($control.'/server.lock', 'c+');
$pointer = $control.'/active.json';
try {
    if ('serve' === $command) {
        if (!flock($lock, \LOCK_EX | \LOCK_NB)) {
            throw new RuntimeException('A recording fixture is already running.');
        }
        $profile = in_array('--profile', $argv, true);
        $mode = $isolated || in_array('--catalog', $argv, true) ? 'catalog' : 'inline';
        $fixture = new Fixture($root, null, $mode, $port);
        try {
            $fixture->serveRecording(static function (string $directory, string $url) use ($pointer, $fs, $profile, $root, $mode, $isolated): void {
                if ($isolated) {
                    $fs->dumpFile($directory.'/revoke-after-order', '');
                }
                if ($profile) {
                    $fs->dumpFile($directory.'/collect-profiles', '');
                    if (is_dir($root.'/examples/symfony/var/cache/dev/profiler')) {
                        $fs->dumpFile($directory.'/share-dev-profiles', '');
                    }
                }
                $fs->dumpFile($pointer, json_encode(['directory' => $directory, 'url' => $url, 'mode' => $mode], \JSON_THROW_ON_ERROR));
                if (function_exists('pcntl_async_signals')) {
                    pcntl_async_signals(true);
                    foreach ([\SIGINT, \SIGTERM, \SIGHUP] as $signal) {
                        pcntl_signal($signal, static fn () => $fs->touch($directory.'/stop-recording'));
                    }
                }
                echo 'Symfony MCP is listening at '.$url.' ('.$mode.")\n";
            });
        } finally {
            $fixture->close();
            $fs->remove($pointer);
        }

        return;
    }
    if (flock($lock, \LOCK_EX | \LOCK_NB) || !is_file($pointer)) {
        throw new RuntimeException('Start the refund recording server first.');
    }
    $active = json_decode(file_get_contents($pointer), true, flags: \JSON_THROW_ON_ERROR);
    $directory = $active['directory'];
    $port = $isolated ? parse_url($active['url'], \PHP_URL_PORT) : $port;
    if (!preg_match('{\A'.preg_quote(sys_get_temp_dir(), '{').'/symfony-refund-[0-9a-f]{24}\z}', $directory) || !is_dir($directory) || !is_int($port) || 'http://127.0.0.1:'.$port.'/mcp' !== $active['url']) {
        throw new RuntimeException('The recording fixture is unavailable.');
    }
    if ('endpoint' === $command) {
        echo $active['url']."\n";

        return;
    }
    if ('client-config' === $command && $isolated) {
        $helper = implode(' ', array_map(escapeshellarg(...), [\PHP_BINARY, __FILE__, 'headers', '--isolated-proof']));
        echo json_encode(['mcpServers' => ['symfony_code_mode' => [
            'type' => 'http', 'url' => $active['url'], 'alwaysLoad' => true, 'headersHelper' => $helper,
        ]]], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n";

        return;
    }
    if ('token' === $command) {
        if (stream_isatty(\STDOUT)) {
            throw new RuntimeException('Pipe the demo token to your clipboard or read it into a client environment variable.');
        }
        echo trim(file_get_contents($directory.'/reader.token'));

        return;
    }
    if ('headers' === $command && !stream_isatty(\STDOUT)) {
        echo json_encode(['Authorization' => 'Bearer '.trim(file_get_contents($directory.'/reader.token'))], \JSON_THROW_ON_ERROR);

        return;
    }
    if ('stop' === $command) {
        $fs->dumpFile($directory.'/stop-recording', '');
        echo "Stopping the recording fixture.\n";

        return;
    }
    if ('revoke' === $command || 'grant' === $command) {
        if ($isolated) {
            throw new LogicException('The isolated proof revokes its disposable grant automatically after the order read.');
        }
        $fs->dumpFile($directory.'/permission', 'grant' === $command ? "granted\n" : "revoked\n");
    } elseif ('profiles' === $command) {
        foreach (RecordingProfiler::panels($directory) as $panel) {
            echo $panel."\n";
        }

        return;
    } elseif ('trace' === $command) {
        $file = $directory.'/'.($isolated ? 'programmatic.jsonl' : 'wire.jsonl');
        echo is_file($file) ? file_get_contents($file) : '';

        return;
    } elseif ('status' !== $command) {
        throw new InvalidArgumentException('Use serve [--catalog] [--profile] [--isolated-proof], endpoint, status, revoke, grant, trace, profiles or stop. Use client-config --isolated-proof for the programmatic client. The headers command is for the MCP client.');
    }
    echo $active['url'].' · '.($active['mode'] ?? 'inline').' · access '.trim(file_get_contents($directory.'/permission'))."\n";
} catch (Throwable $error) {
    fwrite(\STDERR, $error->getMessage()."\n");
    exit(1);
}
