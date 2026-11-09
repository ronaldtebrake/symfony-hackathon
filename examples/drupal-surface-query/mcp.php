<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/src/ObservedProvider.php';

use CodeMode\{CodeModeSession, DockerEngine, ExecutionIdentity, ExecutionLimits, ExecutionTraceFile, GvisorSandbox};
use CodeMode\Provider\ProviderRegistry;
use CodeMode\Symfony\McpServerFactory;
use Mcp\Server\Transport\StdioTransport;
use SurfaceProof\{Connection, ObservedProvider, Verifier};

// Operator-selected directory, never an MCP argument. Each process is one conversation.
$directory = realpath($argv[1] ?? '');
$root = realpath(__DIR__.'/var');
if (false === $directory || false === $root || !str_starts_with($directory, $root.'/')) {
    throw new InvalidArgumentException('Provide a fresh private capture directory under this example\'s var directory.');
}
umask(0077);
$lock = fopen(__DIR__.'/var/proof.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Another Data Surface capture is active.'); }
$env = static fn ($key, $default = '') => (string) ($_SERVER[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default);
$connection = new Connection($env('SURFACE_PROOF_SITE'));
$docker = new DockerEngine(json_decode($env('CODE_MODE_DOCKER_COMMAND', '["docker"]'), true, flags: JSON_THROW_ON_ERROR));
$docker->requireGvisor();
$before = $connection->control('snapshot');
if (!Verifier::fixture($before)) { throw new RuntimeException('Unexpected fixture access or permissions.'); }
$journal = fopen($directory.'/upstream.jsonl', 'x+');
$providers = new ProviderRegistry([new ObservedProvider($connection->provider(), $journal)]);
$limits = new ExecutionLimits(wallTimeMs: 15000, toolTimeMs: 3000, maxToolCalls: 2);
$session = new CodeModeSession($providers->catalog(), $providers->broker(), new GvisorSandbox($env('CODE_MODE_IMAGE'), $docker),
    new ExecutionIdentity('surface-proof-reader', ['published.search']), $limits, phpInterface: $providers->php());
$manifest = ['version' => 1, 'kind' => 'data-surface-client-conversation', 'createdAt' => gmdate(DATE_ATOM),
    'sessionId' => bin2hex(random_bytes(16)), 'image' => $env('CODE_MODE_IMAGE'), 'runtime' => $session->profile()->metadata(),
    'limits' => get_object_vars($limits) + ['executionAttempts' => 2], 'before' => $before,
    'origins' => $providers->origins(), 'contracts' => $providers->catalog()->all(), 'interfaces' => $providers->php()->stubs()];
file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
$sink = new ExecutionTraceFile($directory.'/executions.jsonl');
$record = static function (string $code, array $trace) use ($sink, $session, $docker, $connection, $directory): void {
    $trace['executedPhp'] = $session->executionCode($code);
    $trace['sourceSha256'] = hash('sha256', $trace['executedPhp']);
    $id = $trace['runId'] ?? '';
    $trace['containerRemoved'] = 1 === preg_match('/\Acode-mode-[a-f0-9]{32}\z/D', $id)
        && '' === $docker->control(['ps', '-aq', '--filter', 'name=^/'.$id.'$']);
    $sink($code, $trace);
    file_put_contents($directory.'/after.json', json_encode($connection->control('snapshot'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
};
try {
    (new McpServerFactory())->create($session, $record)->run(new StdioTransport());
} finally {
    fclose($journal);
    flock($lock, LOCK_UN);
    fclose($lock);
}
