<?php

declare(strict_types=1);

use CodeMode\BatchSession;
use CodeMode\Symfony\SessionFactory;
use Composer\InstalledVersions;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Symfony\Component\Process\Process;

$app = dirname(__DIR__);
require $app.'/vendor/autoload.php';
$checks = [];
$calls = [];
$exit = 0;
$verify = static function (string $name, bool $ok) use (&$checks): void {
    $checks[$name] = $ok;
    if (!$ok) {
        throw new RuntimeException('Adoption check failed.');
    }
};
// The stock server gets no runner settings, inference key or inherited application credentials.
$environment = ['PATH' => '/usr/bin:/bin', 'ADOPTION_MODE' => 'catalog', 'APP_ENV' => 'operator'];
$client = Client::builder()->setClientInfo('fresh-symfony-catalog-proof', '1')->setInitTimeout(10)->setRequestTimeout(10)->setMaxRetries(0)->build();
$report = ['format' => 'fresh-symfony-catalog/v1', 'capturedAt' => gmdate(\DATE_ATOM), 'status' => 'running',
    'scope' => 'Independent Symfony app, copied local Composer packages, own approved native tool and policy, real stock MCP STDIO server. Literal operator requests; no model or generated PHP. No Docker settings passed. Not a Packagist or remote-authentication proof.'];
try {
    foreach ([BatchSession::class, SessionFactory::class] as $class) {
        $source = realpath((new ReflectionClass($class))->getFileName());
        $verify('own_vendor:'.$class, is_string($source) && str_starts_with($source, realpath($app.'/vendor').'/'));
    }
    $verify('no_demo_autoloader', !class_exists('App\\Kernel'));
    foreach (['php-code-mode', 'symfony-code-mode'] as $package) {
        $verify('copied_package:'.$package, !is_link($app.'/vendor/ronaldtebrake/'.$package));
    }
    $doctor = new Process([\PHP_BINARY, 'bin/console', 'code-mode:doctor', '--json'], $app,
        array_replace(array_fill_keys(array_keys(getenv()), false), $environment), timeout: 30);
    $doctor->run();
    $report['prerequisites'] = json_decode($doctor->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
    $verify('doctor_without_runner', $doctor->isSuccessful() && true === $report['prerequisites']['ready'] && 'json-batch' === $report['prerequisites']['runtime']['executor']);
    $verify('no_generated_code', false === $report['prerequisites']['runtime']['generatedCode']);
    $client->connect(new StdioTransport(\PHP_BINARY, ['bin/console', 'code-mode:serve'], $app, $environment, maxBufferSize: 65536));
    $before = json_encode($client->listTools()->tools);
    $verify('two_entry_tools', ['search_tools', 'execute_tools'] === array_column($client->listTools()->tools, 'name'));
    $call = static function ($name, $arguments) use ($client, &$calls): array {
        $start = hrtime(true);
        $result = $client->callTool($name, $arguments);
        $calls[] = ['name' => $name, 'arguments' => $arguments, 'isError' => $result->isError,
            'result' => $result->structuredContent, 'elapsedMs' => (hrtime(true) - $start) / 1e6];

        return $result->structuredContent;
    };
    $search = $call('search_tools', ['query' => 'publication']);
    $verify('only_approved_contract', ['publication_read'] === array_column($search['tools'], 'name') && ['id'] === $search['tools'][0]['inputSchema']['required']);
    $read = static fn ($id) => ['calls' => [['name' => 'publication_read', 'arguments' => ['id' => $id]]]];
    $denied = $call('execute_tools', $read(202));
    $verify('restricted_read_denied', 'tool_denied' === ($denied['error']['reason'] ?? null) && 0 === $denied['completedCalls'] && !isset($denied['results']));
    $allowed = $call('execute_tools', $read(101));
    $verify('permitted_recovery', ['id' => 101, 'title' => 'A Symfony application owns its tools'] === ($allowed['results'][0]['value'] ?? null));
    $verify('lean_result', ['results'] === array_keys($allowed));
    $exhausted = $call('execute_tools', $read(101));
    $verify('failure_consumes_attempt', 'execution_limit' === ($exhausted['error']['reason'] ?? null));
    $verify('stable_catalog', $before === json_encode($client->listTools()->tools));
    $verify('private_fixture_absent', !str_contains(json_encode($calls), 'PRIVATE_FIXTURE_DO_NOT_RETURN'));
    $versions = [];
    foreach (['ronaldtebrake/php-code-mode', 'ronaldtebrake/symfony-code-mode', 'symfony/framework-bundle', 'symfony/ai-agent', 'mcp/sdk'] as $name) {
        $versions[$name] = InstalledVersions::getPrettyVersion($name);
    }
    $report['installation'] = ['versions' => $versions, 'composerLockSha256' => hash_file('sha256', $app.'/composer.lock'), 'symlink' => false,
        'pathRepositorySourcesPresent' => is_dir($app.'/../../packages'), 'rootAutoloaderPresent' => is_file($app.'/../../vendor/autoload.php')];
    $report['status'] = 'passed';
} catch (Throwable $error) {
    $report['status'] = 'failed';
    $report['errorType'] = $error::class;
    $exit = 1;
} finally {
    $client->disconnect();
}
$report['calls'] = $calls;
$report['checks'] = $checks;
echo json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
exit($exit);
