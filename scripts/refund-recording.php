<?php

declare(strict_types=1);

use App\Demo\GroupSelection\Discovery;
use App\Demo\Refund\Fixture;
use App\Demo\Refund\RecordingProfiler;
use App\Demo\Refund\Tools;
use App\Demo\RefundRecording\Proof;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$root = dirname(__DIR__);
if ('cli-server' === \PHP_SAPI) {
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
$rehearsal = in_array('--rehearsal', $argv, true);
$port = $rehearsal ? 8017 : 8016;
$control = $root.'/var/refund-recording/'.($rehearsal ? 'rehearsal' : 'recording');
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
        $mode = in_array('--catalog', $argv, true) ? 'catalog' : 'inline';
        $fixture = new Fixture($root, null, $mode, $port);
        try {
            $fixture->serveRecording(static function (string $directory, string $url) use ($pointer, $fs, $profile, $root, $mode): void {
                if ($profile) {
                    $fs->dumpFile($directory.'/collect-profiles', '');
                    if (is_dir($root.'/examples/symfony/var/cache/dev/profiler')) {
                        $fs->dumpFile($directory.'/share-dev-profiles', '');
                    }
                }
                $fs->dumpFile($pointer, json_encode(['directory' => $directory, 'url' => $url, 'mode' => $mode], \JSON_THROW_ON_ERROR));
                echo 'Recording endpoint ready at '.$url.' ('.$mode.")\n";
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
    if (!preg_match('{\A'.preg_quote(sys_get_temp_dir(), '{').'/symfony-refund-[0-9a-f]{24}\z}', $directory) || !is_dir($directory) || 'http://127.0.0.1:'.$port.'/mcp' !== $active['url']) {
        throw new RuntimeException('The recording fixture is unavailable.');
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
        $fs->dumpFile($directory.'/permission', 'grant' === $command ? "granted\n" : "revoked\n");
    } elseif ('profiles' === $command) {
        foreach (RecordingProfiler::panels($directory) as $panel) {
            echo $panel."\n";
        }

        return;
    } elseif ('trace' === $command) {
        echo is_file($directory.'/wire.jsonl') ? file_get_contents($directory.'/wire.jsonl') : '';

        return;
    } elseif ('status' !== $command) {
        throw new InvalidArgumentException('Use serve [--catalog] [--profile], status, revoke, grant, trace, profiles or stop. The headers command is for the MCP client.');
    }
    echo $active['url'].' · '.($active['mode'] ?? 'inline').' · access '.trim(file_get_contents($directory.'/permission'))."\n";
} catch (Throwable $error) {
    fwrite(\STDERR, $error->getMessage()."\n");
    exit(1);
}
