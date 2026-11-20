<?php

declare(strict_types=1);

use App\Demo\AuditVerifier;
use App\Demo\FieldSelection\Audit;
use App\Demo\FieldSelection\BodyMeter;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

/** Bind the measurements to actual authorization, budget and Symfony handler events. */
function checkTraces(string $path): array
{
    $reads = $ledger = [];
    $executions = $reserved = $denials = 0;
    foreach (file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) as $line) {
        $row = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        $params = $row['request']['params'] ?? [];
        if ('execute_tools' !== ($params['name'] ?? null)) {
            continue;
        }
        ++$executions;
        $calls = $params['arguments']['calls'];
        $operation = $row['trace']['operations'][0];
        $before = $operation['budget'][0];
        $after = $operation['budget'][array_key_last($operation['budget'])];
        $reserved += count($calls);
        if ($before['attempts'] !== $executions - 1 || $before['reservedCalls'] !== $reserved - count($calls)
            || $after['attempts'] !== $executions || $after['reservedCalls'] !== $reserved || 0 !== $row['trace']['dropped']) {
            throw new RuntimeException('Unexpected budget accounting or incomplete trace.');
        }
        if ($row['response']['result']['isError'] ?? false) {
            if ('failed' !== $operation['status'] || 'tool_denied' !== $operation['reason'] || [] !== $operation['nativeEvents']
                || ['denied'] !== array_column($operation['calls'], 'status')) {
                throw new RuntimeException('A denied read must stop before handler dispatch.');
            }
            ++$denials;
            continue;
        }
        $events = [];
        foreach ($calls as $call) {
            $name = $call['name'];
            unset($call['arguments']['fields']);
            $reads[] = $call;
            $events[] = ['name' => $name, 'phase' => 'requested'];
            $events[] = ['name' => $name, 'phase' => 'succeeded'];
        }
        if ($operation['nativeEvents'] !== $events || array_column($operation['calls'], 'name') !== array_column($calls, 'name')
            || array_column($operation['calls'], 'status') !== array_fill(0, count($calls), 'succeeded')) {
            throw new RuntimeException('The individual handler invocations did not match the batch.');
        }
        array_push($ledger, ...$operation['calls']);
    }
    if (10 !== count($reads) || 6 !== $executions || 2 !== $denials || 12 !== $reserved) {
        throw new RuntimeException('Expected four measured executions and two denied controls.');
    }

    return ['ledger' => $ledger, 'readArgumentsSha256' => hash('sha256', json_encode($reads, \JSON_THROW_ON_ERROR)),
        'executionSubmissions' => $executions - $denials, 'handlerCalls' => count($reads), 'postControlReservedCalls' => $reserved];
}

function measureFieldSelection(string $surface, bool $selected): array
{
    $root = dirname(__DIR__);
    $fs = new Filesystem();
    $capture = sys_get_temp_dir().'/field-selection-'.bin2hex(random_bytes(12));
    $process = new Process([\PHP_BINARY, $root.'/scripts/claude-audit-host.php', $surface, $capture], $root, timeout: 45);
    $process->start();
    $client = Client::builder()->setClientInfo('Symfony field selection proof', '1')->setInitTimeout(10)->setRequestTimeout(10)->setMaxRetries(0)->build();
    try {
        if (!$process->waitUntil(static fn (string $type, string $buffer): bool => str_contains($buffer, 'Disposable audit HTTP app ready.'))) {
            throw new RuntimeException('Unable to start the disposable HTTP app.');
        }
        $config = json_decode(file_get_contents($capture.'/private.json'), true, flags: \JSON_THROW_ON_ERROR);
        $until = hrtime(true) + 2_000_000_000;
        do {
            $socket = @fsockopen('127.0.0.1', (int) parse_url($config['endpoint'], \PHP_URL_PORT), timeout: 0.1);
            if (false !== $socket) {
                fclose($socket);
                break;
            }
            usleep(10000);
        } while (hrtime(true) < $until && $process->isRunning());
        if (false === $socket) {
            throw new RuntimeException('The disposable HTTP app did not accept a local connection.');
        }
        $meter = new BodyMeter(new Psr18Client(HttpClient::create(['timeout' => 10, 'max_duration' => 15, 'max_redirects' => 0])));
        $client->connect(new HttpTransport($config['endpoint'], ['Authorization' => 'Bearer '.$config['token']], $meter));
        $audit = new Audit($client, $selected);
        $meter->phase = 'discovery';
        $audit->discover('catalog-fields' === $surface);
        $meter->phase = 'execution';
        $result = $audit->run();
        $meter->phase = 'control';
        $draft = $audit->expectDenied(49);
        $fs->dumpFile($config['directory'].'/permission', "revoked\n");
        $revoked = $audit->expectDenied(1);
        $traces = checkTraces($capture.'/http.jsonl');
        $verdict = (new AuditVerifier())->verify($result['answer'], 'fixture', $traces['ledger']);
        if (true !== $verdict['passed']) {
            throw new RuntimeException($verdict['message']);
        }
        $calls = array_values(array_filter($meter->calls, static fn ($call) => 'control' !== $call['phase']));
        $totals = ['setup' => 0, 'discovery' => 0, 'execution' => 0];
        foreach ($calls as $call) {
            $totals[$call['phase']] += $call['requestBodyBytes'] + $call['responseBodyBytes'];
        }
        $host = json_decode(file_get_contents($capture.'/host.json'), true, flags: \JSON_THROW_ON_ERROR);

        return ['requestBodyBytes' => array_sum(array_column($calls, 'requestBodyBytes')), 'responseBodyBytes' => array_sum(array_column($calls, 'responseBodyBytes')),
            'totalBodyBytes' => array_sum($totals), 'setupBodyBytes' => $totals['setup'], 'discoveryBodyBytes' => $totals['discovery'], 'executionBodyBytes' => $totals['execution'],
            'handlerCalls' => $traces['handlerCalls'], 'executionSubmissions' => $traces['executionSubmissions'], 'coverage' => $result['coverage'],
            'findings' => count($result['answer']['findings']), 'answerSha256' => hash('sha256', json_encode($result['answer'], \JSON_THROW_ON_ERROR)),
            'readArgumentsSha256' => $traces['readArgumentsSha256'], 'datasetSha256' => $host['datasetSha256'],
            'calls' => $calls, 'security' => ['draft' => $draft, 'afterRevocation' => $revoked, 'deniedHandlerCalls' => 0, 'postControlReservedCalls' => $traces['postControlReservedCalls']]];
    } finally {
        try {
            $client->disconnect();
        } finally {
            if (is_dir($capture)) {
                touch($capture.'/stop');
            }
            try {
                $process->wait();
                if (!$process->isSuccessful() || !is_file($capture.'/cleanup.json') || !json_decode(file_get_contents($capture.'/cleanup.json'), true)['applicationRemoved']) {
                    throw new RuntimeException('The disposable HTTP app did not exit cleanly.');
                }
            } finally {
                $fs->remove($capture);
            }
        }
    }
}

umask(0077);
$paths = [
    'legacy' => measureFieldSelection('catalog', false),
    'full' => measureFieldSelection('catalog-fields', false),
    'selected' => measureFieldSelection('catalog-fields', true),
];
foreach (['answerSha256', 'readArgumentsSha256', 'datasetSha256'] as $key) {
    if (1 !== count(array_unique(array_column($paths, $key)))) {
        throw new RuntimeException('The three arms disagree on '.$key.'.');
    }
}
echo json_encode(['format' => 'symfony-field-selection/v1', 'verified' => true,
    'protocol' => 'Three disposable Symfony HTTP apps. The same PHP MCP SDK client browses both entry tools, discovers the three readers, and checks every published article and associated image. Actual HTTP entity bytes across setup, discovery and execution include text and structured results. HTTP headers, network framing and two subsequent denial probes are excluded. No model runs.',
    'paths' => $paths], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n";
