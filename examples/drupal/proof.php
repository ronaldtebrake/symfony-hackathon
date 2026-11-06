<?php

declare(strict_types=1);

use CodeMode\{AgentResponse, CodeModeSession, DiscoveryMode, DockerEngine, ExecutionIdentity, ExecutionLimits, GvisorSandbox, SandboxException};
use CodeMode\Mcp\McpGateway;
use CodeModeDemo\Drupal\ProofVerifier;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// Run on the CLI host, where the existing gVisor runtime is configured.
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require __DIR__.'/lib/ProofVerifier.php';
(new Dotenv())->bootEnv($root.'/.env');
$env = static fn (string $name, string $default = ''): string => (string) ($_SERVER[$name] ?? $_ENV[$name] ?? (getenv($name) ?: $default));
$ddev = (new ExecutableFinder())->find('ddev') ?? throw new RuntimeException('DDEV is required.');
$environment = [];
foreach (['PATH', 'HOME', 'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_CONFIG', 'TMPDIR'] as $name) {
    if (false !== ($value = getenv($name))) {
        $environment[$name] = $value;
    }
}
if (!is_dir(__DIR__.'/var')) {
    mkdir(__DIR__.'/var', 0700);
}
$lock = fopen(__DIR__.'/var/proof.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Another Drupal proof is running.');
}
$snapshot = static function () use ($ddev, $environment): array {
    $process = new Process([$ddev, '--skip-hooks', 'exec', '--raw', '--', 'vendor/bin/drush', '--quiet', 'php:script', '/var/www/html/scripts/snapshot.php'], __DIR__, array_replace(array_fill_keys(array_keys(getenv()), false), $environment), timeout: 15);
    $process->mustRun();
    return json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
};
$options = getopt('', ['discovery:']);
$discovery = DiscoveryMode::from($options['discovery'] ?? 'compact');
$report = ['discoveryMode' => $discovery->value, 'version' => 1, 'kind' => 'drupal-authorization-proof', 'createdAt' => gmdate(DATE_ATOM), 'status' => 'running',
    'transport' => 'local DDEV STDIO', 'modelCalled' => false, 'programOrigin' => 'Fixed operator-written probes',
    'runtime' => 'runsc', 'image' => $env('CODE_MODE_IMAGE'), 'cases' => []];
try {
    $before = $snapshot();
    if (!ProofVerifier::fixture($before)) {
        throw new RuntimeException('The Drupal fixture or account permissions do not match the read-only proof.');
    }
    $report['fixture'] = array_diff_key($before, ['events' => true]);
    $report['versions'] = [];
    foreach (json_decode(file_get_contents(__DIR__.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR)['packages'] as $package) {
        if (in_array($package['name'], ['drupal/core', 'drupal/tool', 'drupal/mcp_server', 'drupal/mcp_server_tool_bridge', 'mcp/sdk'], true)) {
            $report['versions'][$package['name']] = $package['version'];
        }
    }
    $gateway = new McpGateway(static function () use ($ddev, $environment): Client {
        $client = Client::builder()->setClientInfo('symfony-code-mode-drupal-proof', '1.0')->setInitTimeout(2)->setRequestTimeout(2)->setMaxRetries(0)->build();
        try {
            // Account and command are operator-owned. The guest supplies only tool arguments.
            $client->connect(new StdioTransport($ddev, ['--skip-hooks', 'exec', '--raw', '--', 'vendor/bin/drush', '--quiet', 'mcp:server', 'code_mode_reader'], __DIR__, $environment, maxBufferSize: 65536));
            return $client;
        } catch (Throwable) {
            $client->disconnect();
            throw new RuntimeException('The local Drupal MCP server is unavailable.');
        }
    }, [ProofVerifier::TOOL => static fn (ExecutionIdentity $identity): bool => $identity->allows('content.audit')]);
    $catalog = $gateway->discover();
    $docker = new DockerEngine(json_decode($env('CODE_MODE_DOCKER_COMMAND', '["docker"]'), true, flags: JSON_THROW_ON_ERROR));
    $sandbox = new GvisorSandbox($report['image'], $docker);
    $limits = new ExecutionLimits(wallTimeMs: 15000, toolTimeMs: 3000, maxToolCalls: 1);
    $report['limits'] = ['wallTimeMs' => $limits->wallTimeMs, 'toolTimeMs' => $limits->toolTimeMs, 'memoryMiB' => $limits->memoryMiB, 'maxToolCalls' => $limits->maxToolCalls, 'executions' => 2];
    $session = new CodeModeSession($catalog, $gateway->broker($catalog), $sandbox, new ExecutionIdentity('drupal-proof-reader', ['content.audit']), $limits, discovery: $discovery);
    $report['discovery'] = $session->search(ProofVerifier::TOOL);
    $report['discoveryRequests'] = 1;
    if (DiscoveryMode::Full === $discovery || in_array(true, array_column($report['discovery'], 'requiresDescribe'), true)) {
        $report['described'] = $session->describe([ProofVerifier::TOOL]);
        ++$report['discoveryRequests'];
    }
    $cursor = $before['auditCursor'];
    foreach (['authorized_read' => 'published', 'restricted_read' => 'restricted'] as $id => $fixtureKey) {
        $code = 'return $tools->call("'.ProofVerifier::TOOL.'", ["id" => '.$before[$fixtureKey]['id'].']);';
        $case = ['id' => $id, 'code' => $code, 'startedAt' => gmdate(DATE_ATOM), 'value' => null, 'reason' => null, 'runId' => null, 'toolCalls' => [], 'containerRemoved' => false];
        $started = hrtime(true);
        try {
            $response = AgentResponse::execute($session, $code, static function (string $submitted, array $trace) use (&$case): void {
                $case['value'] = json_decode(json_encode($trace['value'] ?? null, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                $case['toolCalls'] = $trace['toolCalls'];
                $case['runId'] = $trace['runId'];
                $case['reason'] = $trace['error']['reason'] ?? null;
            });
        } catch (SandboxException $error) {
            $response = AgentResponse::failure($error, $session->profile());
        }
        $case['agentResponse'] = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $case['durationMs'] = (hrtime(true) - $started) / 1_000_000;
        if (is_string($case['runId']) && preg_match('/\Acode-mode-[a-f0-9]{32}\z/D', $case['runId'])) {
            $case['containerRemoved'] = '' === $docker->control(['ps', '-aq', '--filter', 'name=^/'.$case['runId'].'$']);
        }
        $after = $snapshot();
        $case['drupalEvents'] = array_values(array_filter($after['events'], static fn (array $event): bool => $event['id'] > $cursor));
        $cursor = $after['auditCursor'];
        $case['passed'] = ProofVerifier::casePassed($case, $before);
        $case['completedAt'] = gmdate(DATE_ATOM);
        $report['cases'][] = $case;
    }
    $report['contentUnchanged'] = $before['contentHash'] === $after['contentHash'];
    $report['accountStillRestricted'] = ProofVerifier::fixture($after);
    $report['status'] = $report['contentUnchanged'] && $report['accountStillRestricted'] && array_column($report['cases'], 'passed') === [true, true] ? 'complete' : 'failed';
} catch (Throwable $error) {
    $report['status'] = 'failed';
    // Do not export transport STDERR, host paths or exception messages.
    $report['error'] = $error instanceof SandboxException ? $error->reason : 'proof_unavailable';
}
$report['completedAt'] = gmdate(DATE_ATOM);
$json = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
file_put_contents(__DIR__.'/var/latest-proof.json', $json, LOCK_EX);
echo $json;
flock($lock, LOCK_UN);
fclose($lock);
exit($report['status'] === 'complete' ? 0 : 1);
