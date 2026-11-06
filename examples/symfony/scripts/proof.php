<?php

declare(strict_types=1);

use CodeMode\CodeModeSession;
use CodeMode\DockerEngine;
use CodeMode\Symfony\SessionFactory;
use Composer\InstalledVersions;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

$app = dirname(__DIR__);
require $app.'/vendor/autoload.php';
if (is_file($app.'/.env.local')) {
    (new Dotenv())->load($app.'/.env.local');
}

$checks = [];
$verify = static function (string $name, bool $passed) use (&$checks): void {
    $checks[$name] = $passed;
    if (!$passed) {
        throw new RuntimeException('Proof check failed: '.$name);
    }
};

// Pass only trusted runtime connection settings to the local MCP server, never model tokens.
$environment = [];
foreach (['PATH', 'HOME', 'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_CONFIG', 'SSH_AUTH_SOCK', 'XDG_RUNTIME_DIR', 'TMPDIR', 'CODE_MODE_IMAGE', 'CODE_MODE_DOCKER_COMMAND'] as $key) {
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
    if (is_string($value)) { $environment[$key] = $value; }
}
$client = Client::builder()->setClientInfo('fresh-symfony-proof', '1')->setInitTimeout(10)->setRequestTimeout(30)->setMaxRetries(0)->build();
$exitCode = 0;

try {
    $vendor = realpath($app.'/vendor').'/';
    foreach ([CodeModeSession::class, SessionFactory::class] as $class) {
        $source = realpath((new ReflectionClass($class))->getFileName());
        $verify('own_vendor:'.$class, is_string($source) && str_starts_with($source, $vendor));
    }
    $verify('no_demo_autoloader', !class_exists('App\\Kernel'));
    foreach (['php-code-mode', 'symfony-code-mode'] as $package) {
        $verify('copied_package:'.$package, !is_link($app.'/vendor/ronaldtebrake/'.$package));
    }

    $doctor = new Process([PHP_BINARY, 'bin/console', 'code-mode:doctor', '--json'], $app,
        array_replace(array_fill_keys(array_keys(getenv()), false), $environment), timeout: 60);
    $doctor->run();
    $prerequisites = json_decode($doctor->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $verify('prerequisites_ready', $doctor->isSuccessful() && true === ($prerequisites['ready'] ?? false));
    $verify('bounded_defaults', ['wallTimeMs' => 5000, 'toolTimeMs' => 1000, 'memoryMiB' => 128, 'phpMemoryMiB' => 32, 'maxToolCalls' => 10, 'executionAttempts' => 2] === $prerequisites['limits']);
    $verify('gvisor_profile', \CodeMode\ExecutionProfile::Gvisor->metadata() === ($prerequisites['runtime'] ?? null));
    $docker = new DockerEngine(json_decode($environment['CODE_MODE_DOCKER_COMMAND'] ?? '["docker"]', true, flags: JSON_THROW_ON_ERROR));

    $client->connect(new StdioTransport(PHP_BINARY, ['bin/console', 'code-mode:serve'], $app, $environment, maxBufferSize: 65536));
    $verify('three_code_mode_tools', ['tools_search', 'tools_describe', 'php_execute'] === array_column($client->listTools()->tools, 'name'));
    $search = $client->callTool('tools_search', ['query' => 'publication']);
    $verify('only_explicitly_approved_tool_discovered', !$search->isError && ['publication_read'] === array_column($search->structuredContent, 'name'));
    $unapproved = $client->callTool('tools_describe', ['names' => ['publication_count']]);
    $verify('unapproved_tool_hidden', $unapproved->isError && 'tool_denied' === ($unapproved->structuredContent['error']['reason'] ?? null));
    $describe = $client->callTool('tools_describe', ['names' => ['publication_read']]);
    $verify('approved_schema_described', !$describe->isError && ['publication_read'] === array_column($describe->structuredContent, 'name'));

    $cases = [];
    foreach (['authorized' => 101, 'restricted' => 202] as $name => $id) {
        $php = sprintf('return $tools->call("publication_read", ["id" => %d]);', $id);
        $result = $client->callTool('php_execute', ['code' => $php]);
        $trace = $result->structuredContent;
        $runId = $trace['runId'] ?? '';
        $verify($name.':real_execution', is_string($runId) && 1 === preg_match('/\Acode-mode-[a-f0-9]{32}\z/D', $runId));
        $removed = '' === $docker->control(['ps', '-aq', '--filter', 'name=^/'.$runId.'$']);
        $verify($name.':container_removed', $removed);
        $verify($name.':one_host_observed_call', ['publication_read'] === array_column($trace['toolCalls'] ?? [], 'name'));
        if (101 === $id) {
            $verify('authorized:value', !$result->isError && ['id' => 101, 'title' => 'A Symfony application owns its tools'] === ($trace['value'] ?? null));
            $verify('authorized:host_status', 'succeeded' === $trace['toolCalls'][0]['status']);
        } else {
            $verify('restricted:denied', $result->isError && 'tool_denied' === ($trace['error']['reason'] ?? null) && 'denied' === $trace['toolCalls'][0]['status']);
            $verify('restricted:no_value', !array_key_exists('value', $trace) && !isset($trace['toolCalls'][0]['resultBytes']));
        }
        $cases[] = ['case' => $name, 'php' => $php, 'isError' => $result->isError, 'trace' => $trace, 'containerRemoved' => $removed];
    }
    $verify('separate_containers_same_session', $cases[0]['trace']['runId'] !== $cases[1]['trace']['runId']);
    $exhausted = $client->callTool('php_execute', ['code' => 'return 1;']);
    $verify('failure_consumes_attempt', $exhausted->isError && 'execution_limit' === ($exhausted->structuredContent['error']['reason'] ?? null)
        && array_key_exists('runId', $exhausted->structuredContent) && null === $exhausted->structuredContent['runId'] && [] === $exhausted->structuredContent['toolCalls']);
    $wire = json_encode([$search, $describe, $unapproved, $cases, $exhausted], JSON_THROW_ON_ERROR);
    $verify('restricted_fixture_not_returned', !str_contains($wire, 'PRIVATE_FIXTURE_DO_NOT_RETURN'));

    $versions = [];
    foreach (['ronaldtebrake/php-code-mode', 'ronaldtebrake/symfony-code-mode', 'symfony/framework-bundle', 'symfony/ai-agent', 'mcp/sdk'] as $package) {
        $versions[$package] = InstalledVersions::getPrettyVersion($package);
    }
    $report = [
        'format' => 'fresh-symfony-adoption/v1', 'capturedAt' => gmdate(DATE_ATOM),
        'status' => 'passed', 'scope' => 'Independent Symfony app, mirrored local Composer packages, real MCP STDIO and gVisor. Operator-written PHP; no model call. Not a Packagist or remote-authentication proof.',
        'installation' => ['versions' => $versions, 'composerLockSha256' => hash_file('sha256', $app.'/composer.lock'), 'symlink' => false,
            'pathRepositorySourcesPresent' => is_dir($app.'/../../packages'), 'rootAutoloaderPresent' => is_file($app.'/../../vendor/autoload.php')],
        'runtime' => ['image' => $environment['CODE_MODE_IMAGE'], 'hostPhp' => PHP_VERSION, 'prerequisites' => $prerequisites],
        'discovery' => ['search' => $search->structuredContent, 'describe' => $describe->structuredContent, 'unapproved' => $unapproved->structuredContent],
        'cases' => $cases, 'thirdAttempt' => $exhausted->structuredContent, 'checks' => $checks,
    ];
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    // Never serialize upstream exception messages or stderr into public evidence.
    fwrite(STDERR, "Adoption proof failed (".$error::class."). Run code-mode:doctor and composer check.\n");
    fwrite(STDERR, json_encode(['checks' => $checks], JSON_THROW_ON_ERROR)."\n");
    $exitCode = 1;
} finally {
    $client->disconnect();
}
exit($exitCode);
