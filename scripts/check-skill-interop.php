<?php

declare(strict_types=1);

use App\Demo\Mcp\CaptureRequestFactory;
use App\Demo\Skills\Fixture;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

// Run against an explicitly supplied, separately installed copy of the experimental SDK.
$revision = Fixture::REVISION;
$sdk = 2 === $argc ? realpath($argv[1]) : false;
if (false === $sdk || !is_dir($sdk.'/.git') || !is_file($sdk.'/vendor/autoload.php')) {
    fwrite(\STDERR, "Usage: php scripts/check-skill-interop.php /path/to/installed-php-sdk-draft\n");
    exit(2);
}
$env = array_replace(array_fill_keys(array_keys(getenv() + $_ENV), false), ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'SystemRoot' => getenv('SystemRoot') ?: false]);
$git = static fn (array $args): string => trim((new Process(['git', ...$args], $sdk, $env, timeout: 5))->mustRun()->getOutput());
if ($revision !== $git(['rev-parse', 'HEAD']) || '' !== $git(['status', '--porcelain', '--untracked-files=all', '--', 'src', 'composer.json'])) {
    throw new RuntimeException('The probe requires the clean, pinned PR #372 source.');
}

$uri = Fixture::URI;
$file = dirname(__DIR__).'/examples/symfony/skills/read-published-article/SKILL.md';
$text = file_get_contents($file);
if (false === $text) {
    throw new RuntimeException('Cannot read the local text-only skill.');
}
$dir = sys_get_temp_dir().'/skill-interop-'.bin2hex(random_bytes(16));
$files = new Filesystem();
$files->mkdir($dir, 0700);
try {
    // Only this file is in the served directory. No host-side tool or catalog credential is registered.
    $files->dumpFile($dir.'/skills/read-published-article/SKILL.md', $text);
    $server = <<<'PHP'
        require $argv[1];
        Mcp\Server::builder()->setServerInfo('Text-only skill interop', '1')
            ->addSkillsFromDirectory('skills')->build()->run(new Mcp\Server\Transport\StdioTransport());
        PHP;
    $extension = Fixture::EXTENSION;
    $revisionDate = Fixture::VERSION;
    $factory = new CaptureRequestFactory('skill-interop-check', [$extension]);
    $requests = [];
    foreach (['server/discover' => [], 'skills/list' => [], 'skills/get' => ['uri' => $uri], 'resources/read' => ['uri' => $uri]] as $method => $params) {
        $requests[] = $factory->message(count($requests) + 1, $method, $params);
    }
    $input = implode("\n", array_map(static fn (array $r) => json_encode($r, \JSON_THROW_ON_ERROR), $requests))."\n";
    $process = new Process([\PHP_BINARY, '-r', $server, $sdk.'/vendor/autoload.php'], $dir, $env, $input, 10);
    $lines = explode("\n", trim($process->mustRun()->getOutput()));
    $results = [];
    foreach ($lines as $line) {
        $reply = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        if ('2.0' !== ($reply['jsonrpc'] ?? null) || !isset($reply['id'], $reply['result']) || isset($reply['error']) || isset($results[$reply['id']])) {
            throw new RuntimeException('Unexpected JSON-RPC response.');
        }
        $results[$reply['id']] = $reply['result'];
    }
    $manifest = $results[3]['skill'] ?? null;
    $digest = 'sha256:'.hash('sha256', $text);
    $size = strlen($text);
    $contents = $results[4]['contents'] ?? [];
    if (4 !== count($results) || !in_array($revisionDate, $results[1]['supportedVersions'] ?? [], true) || !isset($results[1]['capabilities']['extensions'][$extension])
        || !is_array($manifest) || [$manifest] !== ($results[2]['skills'] ?? null) || $uri !== ($manifest['uri'] ?? null)
        || 'read-published-article' !== ($manifest['frontmatter']['name'] ?? null)
        || [['uri' => $uri, 'digest' => $digest, 'size' => $size]] !== ($manifest['resources'] ?? null)
        || 1 !== count($contents) || $uri !== ($contents[0]['uri'] ?? null) || 'text/markdown' !== ($contents[0]['mimeType'] ?? null) || $text !== ($contents[0]['text'] ?? null)) {
        throw new RuntimeException('The advertised extension, manifest or resource bytes differ from the local skill.');
    }
    echo json_encode(['format' => 'php-sdk-skills-interop/v1', 'capturedAt' => gmdate(\DATE_ATOM), 'status' => 'passed',
        'scope' => 'Four real JSON-RPC requests to a separate PHP SDK draft server over stdio. No user identity, model, skill execution or article lookup. Manifest and returned bytes checked against the local file.',
        'source' => ['pr' => 'https://github.com/modelcontextprotocol/php-sdk/pull/372', 'commit' => $revision],
        'protocolVersion' => $revisionDate, 'methods' => array_column($requests, 'method'), 'advertisedCapabilities' => array_keys($results[1]['capabilities']),
        'extension' => $extension, 'listAndGetMatched' => true, 'manifest' => $manifest,
        'resourceRead' => ['uri' => $uri, 'mimeType' => $contents[0]['mimeType'], 'size' => strlen($contents[0]['text']), 'digest' => 'sha256:'.hash('sha256', $contents[0]['text'])],
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
} finally {
    $files->remove($dir);
}
