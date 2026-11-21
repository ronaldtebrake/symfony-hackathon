<?php

declare(strict_types=1);

use Adoption\DemoReaderAccess;
use CodeMode\Symfony\Profiler\CatalogDataCollector;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\Process\Process;

$app = dirname(__DIR__);
require $app.'/vendor/autoload.php';

$directory = sys_get_temp_dir().'/symfony-direct-'.bin2hex(random_bytes(10));
$files = new Filesystem();
$files->mkdir($directory, 0700);
$access = new DemoReaderAccess($directory.'/grant');
$readerToken = bin2hex(random_bytes(32));
$viewerToken = bin2hex(random_bytes(32));
$environment = array_replace(array_fill_keys(array_keys(getenv()), false), [
    'PATH' => getenv('PATH'), 'SystemRoot' => getenv('SystemRoot') ?: false,
    'APP_ENV' => 'direct', 'APP_CACHE_DIR' => $directory, 'APP_LOG_DIR' => $directory,
    'ADOPTION_MCP_TOKEN' => $readerToken, 'ADOPTION_VIEWER_TOKEN' => $viewerToken,
    'ADOPTION_READER_ACCESS_FILE' => $directory.'/grant',
]);
$server = new Process([\PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:0', '-t', 'public', 'public/index.php'], $app, $environment);
$check = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException('Direct tool proof failed: '.$label);
    }
};

try {
    $access->setGranted(true);
    $server->start();
    $endpoint = null;
    $server->waitUntil(static function (string $type, string $line) use (&$endpoint): bool {
        if (preg_match('~Development Server \((http://127\.0\.0\.1:\d+)\) started~', $line, $match)) {
            $endpoint = $match[1].'/mcp';

            return true;
        }

        return false;
    });
    $check(is_string($endpoint), 'Symfony server ready');

    // Keep only the current request's profiler token; raw authenticated headers never enter the receipt.
    $profileHeaders = new HeaderBag();
    $http = HttpClient::create(['no_proxy' => '*', 'max_redirects' => 0, 'timeout' => 10,
        'on_progress' => static function (int $received, int $size, array $info) use ($profileHeaders): void {
            foreach ($info['response_headers'] ?? [] as $header) {
                if (preg_match('/^x-debug-token: ([a-f0-9]+)$/iD', $header, $match)) {
                    $profileHeaders->set('x-debug-token', $match[1]);
                }
            }
        }]);
    $connect = static function (string $token) use ($endpoint, $http): Client {
        $client = Client::builder()->setClientInfo('Symfony direct tool verifier', '1')->setMaxRetries(0)->build();
        $client->connect(new HttpTransport($endpoint, ['Authorization' => 'Bearer '.$token], new Psr18Client($http)));

        return $client;
    };
    $reader = $connect($readerToken);
    $viewer = $connect($viewerToken);
    $storage = new FileProfilerStorage('file:'.$directory.'/direct/profiler');
    $receipt = ['format' => 'symfony-direct-tool/v1', 'scope' => 'PHP MCP SDK over authenticated HTTP in the independent Symfony app. Synthetic articles. No model calls.', 'calls' => []];
    $list = static fn (Client $client): array => array_column($client->listTools()->tools, 'name');
    $reset = static function () use ($app, $environment, &$receipt): void {
        (new Process([\PHP_BINARY, 'bin/console', 'code-mode:reset-budget', 'demo-reader'], $app, $environment))->mustRun();
        $receipt['calls'][] = ['operator' => 'reset reader budget'];
    };
    $call = static function (Client $client, string $name, array $args, ?string $error, array $events) use ($profileHeaders, &$receipt, $storage, $check, $reader): array {
        $profileHeaders->remove('x-debug-token');
        $response = $client->callTool($name, $args);
        $value = $response->structuredContent;
        $check(is_array($value) && $response->isError === (null !== $error) && ($value['error']['reason'] ?? null) === $error, $name.' response '.($error ?? 'success'));
        $profileToken = $profileHeaders->get('x-debug-token') ?? throw new RuntimeException('Missing profiler token for '.$name);
        // Kernel termination may finish just after the HTTP response has arrived.
        $deadline = microtime(true) + 3;
        while (null === ($profile = $storage->read($profileToken)) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $collector = $profile?->getCollector('code_mode');
        if (!$collector instanceof CatalogDataCollector) {
            throw new RuntimeException('Missing catalog profiler data for '.$name);
        }
        $operations = $collector->getOperations();
        $check(1 === count($operations) && $name === $operations[0]['operation'], $name.' operation');
        $operation = $operations[0];
        $check($events === array_column($operation['nativeEvents'], 'phase'), $name.' Symfony AI events');
        $receipt['calls'][] = ['subject' => $client === $reader ? 'demo-reader' : 'demo-viewer', 'tool' => $name, 'arguments' => $args,
            'result' => 'search_tools' === $name ? ['tools' => array_column($value['tools'], 'name')] : $value,
            'nativeEvents' => $operation['nativeEvents'], 'budget' => end($operation['budget']) ?: null];

        return $value;
    };
    $names = ['search_tools', 'execute_tools'];
    $tools = $reader->listTools()->tools;
    $receipt['advertised'] = ['reader' => array_column($tools, 'name'), 'viewer' => $list($viewer)];
    $check([...$names, 'publication_read'] === $receipt['advertised']['reader'] && $names === $receipt['advertised']['viewer'], 'per-user tool inventory');
    $direct = $tools[array_search('publication_read', array_column($tools, 'name'), true)];
    $receipt['contract'] = ['name' => $direct->name, 'inputSchema' => $direct->inputSchema, 'outputSchema' => $direct->outputSchema];
    $id = ['id' => 101];
    $batch = ['calls' => [['name' => 'publication_read', 'arguments' => $id]]];
    $events = ['requested', 'succeeded'];
    $article = ['id' => 101, 'title' => 'A Symfony application owns its tools'];

    $check($article === $call($reader, 'publication_read', $id, null, $events), 'direct read without discovery');
    $directBudget = end($receipt['calls'])['budget'];
    $check([1, 1, 0] === [$directBudget['attempts'], $directBudget['reservedCalls'], $directBudget['knownContracts']], 'direct call reserves one attempt and one call without adding a discovery grant');
    $contracts = $call($reader, 'search_tools', ['query' => 'publications'], null, [])['tools'];
    $check(['publication_read'] === array_column($contracts, 'name') && $direct->inputSchema === $contracts[0]['inputSchema']
        && $direct->outputSchema === $contracts[0]['outputSchema'], 'identical input and output schemas');
    $result = $call($reader, 'execute_tools', $batch, null, $events);
    $check($article === $result['results'][0]['value'], 'same handler and value');
    $mixedBudget = end($receipt['calls'])['budget'];
    $check([2, 2] === [$mixedBudget['attempts'], $mixedBudget['reservedCalls']], 'mixed calls share both counters');
    $call($reader, 'publication_read', $id, 'execution_limit', []);
    $call($reader, 'execute_tools', $batch, 'execution_limit', []);
    $call($viewer, 'publication_read', $id, 'tool_denied', []);
    $viewerBudget = end($receipt['calls'])['budget'];
    $check([1, 1] === [$viewerBudget['attempts'], $viewerBudget['reservedCalls']], 'other caller has an independent budget');

    $reset();
    $call($reader, 'publication_read', ['id' => 202], 'tool_denied', []);
    $call($reader, 'execute_tools', $batch, 'discovery_required', []);

    $reset();
    $call($reader, 'publication_read', $id, null, $events);
    $access->setGranted(false);
    $receipt['calls'][] = ['operator' => 'revoke reader permission'];
    $receipt['advertised']['readerAfterRevocation'] = $list($reader);
    $check($names === $receipt['advertised']['readerAfterRevocation'], 'revocation hides direct contract');
    $denied = $call($reader, 'publication_read', $id, 'tool_denied', []);
    $check(0 === $denied['completedCalls'] && !isset($denied['results']), 'known direct read denied before handler');
    $access->setGranted(true);
    $receipt['calls'][] = ['operator' => 'restore reader permission'];
    $call($reader, 'publication_read', $id, 'execution_limit', []);

    $encoded = json_encode($receipt, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    $check(!str_contains($encoded, $readerToken) && !str_contains($encoded, $viewerToken) && !str_contains($encoded, 'PRIVATE_FIXTURE_DO_NOT_RETURN'), 'sanitized capture');
    $options = getopt('', ['compare:']);
    if (isset($options['compare'])) {
        $expected = json_decode(file_get_contents($options['compare']), true, flags: \JSON_THROW_ON_ERROR);
        $check(json_decode($encoded, true, flags: \JSON_THROW_ON_ERROR) === $expected, 'matches retained receipt');
    }
    echo $encoded."\n";
} finally {
    $server->stop();
    $files->remove($directory);
}
