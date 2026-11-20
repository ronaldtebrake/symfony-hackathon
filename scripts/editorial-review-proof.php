<?php

declare(strict_types=1);

use App\Demo\AuditDataset;
use App\Demo\EditorialReview\Gateway;
use App\Demo\EditorialReview\PublicationFindings;
use App\Demo\FieldSelection\BodyMeter;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

function editorialCheck(bool $passed, string $message): void
{
    if (!$passed) {
        throw new RuntimeException($message);
    }
}

/** Pure client-side follow-up, with only the returned findings as input. Preserve every tie. */
function mostAffectedArticles(array $findings): array
{
    $byImage = [];
    foreach ($findings as $finding) {
        if (in_array('missing_alt', $finding['issues'], true)) {
            $byImage[$finding['image_id']][] = $finding['article_id'];
        }
    }
    $highest = $byImage ? max(array_map('count', $byImage)) : 0;
    ksort($byImage);
    $result = [];
    foreach ($byImage as $image => $articles) {
        if (count($articles) === $highest) {
            $result[] = ['image_id' => $image, 'article_ids' => $articles];
        }
    }

    return $result;
}

umask(0077);
$root = dirname(__DIR__);
$fs = new Filesystem();
$capture = sys_get_temp_dir().'/editorial-proof-'.bin2hex(random_bytes(12));
$host = new Process([\PHP_BINARY, $root.'/scripts/claude-audit-host.php', 'reviewer', $capture], $root, timeout: 45);
$client = Client::builder()->setClientInfo('Symfony editorial reviewer proof', '1')->setInitTimeout(10)->setRequestTimeout(10)->setMaxRetries(0)->build();
$host->start();
try {
    editorialCheck($host->waitUntil(static fn (string $type, string $text): bool => str_contains($text, 'Disposable audit HTTP app ready.')), 'Cannot launch the disposable Symfony application.');
    $config = json_decode(file_get_contents($capture.'/private.json'), true, flags: \JSON_THROW_ON_ERROR);
    $until = hrtime(true) + 3_000_000_000;
    do {
        $socket = @fsockopen('127.0.0.1', (int) parse_url($config['endpoint'], \PHP_URL_PORT), timeout: 0.1);
        if (false !== $socket) {
            fclose($socket);
            break;
        }
        usleep(10000);
    } while (hrtime(true) < $until && $host->isRunning());
    editorialCheck(false !== $socket, 'The loopback endpoint did not start.');
    $meter = new BodyMeter(new Psr18Client(HttpClient::create(['max_redirects' => 0, 'timeout' => 10])));
    $client->connect(new HttpTransport($config['endpoint'], ['Authorization' => 'Bearer '.$config['token']], $meter));
    $tools = $client->listTools()->tools;
    editorialCheck(Gateway::NAMES === array_column($tools, 'name'), 'Expected all three real direct contracts.');
    $report = $client->callTool(PublicationFindings::NAME);
    $before = count($meter->calls);
    editorialCheck(!$report->isError && isset($report->structuredContent['findings']), 'No permitted report returned.');
    $impact = mostAffectedArticles($report->structuredContent['findings']);
    $followUpCalls = count($meter->calls) - $before;
    $client->callTool('cms_get_articles', ['ids' => [7]]);
    $client->callTool('cms_get_media', ['ids' => [7]]);
    $fs->dumpFile($config['directory'].'/permission', "revoked\n");
    $client->callTool(PublicationFindings::NAME);
    $calls = [];
    $labels = ['discover', 'findings', 'raw_articles', 'raw_media', 'refresh_after_revocation'];
    foreach (file($capture.'/http.jsonl', \FILE_SKIP_EMPTY_LINES | \FILE_IGNORE_NEW_LINES) as $line) {
        $row = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        if (!in_array($row['request']['method'], ['tools/list', 'tools/call'], true)) {
            continue;
        }
        $wire = json_decode($line, flags: \JSON_THROW_ON_ERROR);
        $calls[] = ['label' => $labels[count($calls)] ?? 'unexpected', 'request' => $wire->request, 'response' => $wire->response, 'httpStatus' => $row['httpStatus'], 'trace' => $row['trace']];
    }
    editorialCheck($labels === array_column($calls, 'label') && [200] === array_values(array_unique(array_column($calls, 'httpStatus'))), 'Missing or extra MCP exchanges.');
    $dataset = new AuditDataset();
    $oracle = $dataset->expected();
    $expected = [];
    foreach ($oracle['findings'] as $finding) {
        $row = ['article_id' => $finding['id'], 'issues' => $finding['issues']];
        if (in_array('missing_alt', $row['issues'], true)) {
            $row['image_id'] = $dataset->articles()[$finding['id']]['image_id'];
        }
        $expected[] = $row;
    }
    $expectedReport = ['audited' => $oracle['audited'], 'findings' => $expected];
    editorialCheck($expectedReport === $report->structuredContent && $impact === $dataset->expectedImpact()['shared_images'] && 0 === $followUpCalls, 'Incomplete findings, excessive fields, or incorrect follow-up.');
    foreach (array_slice($calls, 1) as $index => $call) {
        $result = $call['response']->result;
        $name = $call['request']->params->name;
        $content = json_decode(json_encode($result->structuredContent, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        $operation = $call['trace']['operations'][0];
        $budget = $operation['budget'];
        $target = 0 === $index ? $expectedReport : ['error' => ['reason' => 'tool_denied', 'tool' => $name, 'index' => 0], 'completedCalls' => 0];
        editorialCheck($target === $content && 1 === count($result->content) && 'text' === $result->content[0]->type && $target === json_decode($result->content[0]->text, true, flags: \JSON_THROW_ON_ERROR), 'Unexpected MCP result or text fallback.');
        editorialCheck($index === $budget[0]['reservedCalls'] && $index + 1 === end($budget)['reservedCalls'], 'Every attempt must reserve the caller budget.');
        editorialCheck($operation['operation'] === $name && 0 === $call['trace']['dropped'], 'Incomplete invocation trace.');
        if (0 === $index) {
            editorialCheck(!($result->isError ?? false) && 'succeeded' === $operation['status'] && [['name' => $name, 'phase' => 'requested'], ['name' => $name, 'phase' => 'succeeded']] === $operation['nativeEvents'], 'The native Symfony AI handler did not run.');
            $reads = $call['trace']['sourceReads'];
            editorialCheck(range(1, 10) === array_column($reads, 'reservedReads') && ['publication.findings'] === array_values(array_unique(array_column($reads, 'permission'))), 'Internal reads must be independently authorized and charged.');
            editorialCheck(['list_published' => 4, 'published_article_metadata' => 4, 'referenced_public_media' => 2] === array_count_values(array_column($reads, 'operation')) && [12] === array_values(array_unique(array_column($reads, 'rows'))), 'Unexpected source coverage.');
        } else {
            editorialCheck(true === $result->isError && 'failed' === $operation['status'] && [] === $operation['nativeEvents'] && [] === $call['trace']['sourceReads'] && ['denied'] === array_column($operation['calls'], 'status'), 'A denied read reached a handler.');
        }
    }
    // Provision a separate test identity after the reviewer capture. Verify the raw handlers
    // return real records to an exporter. Neither these records nor this credential are published.
    $exporterToken = bin2hex(random_bytes(32));
    $fs->dumpFile($config['directory'].'/exporter-token', $exporterToken);
    $control = Client::builder()->setClientInfo('Exporter positive control', '1')->setMaxRetries(0)->build();
    try {
        $control->connect(new HttpTransport($config['endpoint'], ['Authorization' => 'Bearer '.$exporterToken], $meter));
        foreach (['cms_get_articles' => $dataset->articles()[7], 'cms_get_media' => $dataset->media()[7]] as $name => $record) {
            $raw = $control->callTool($name, ['ids' => [7]]);
            editorialCheck(!$raw->isError && ['items' => [$record]] === $raw->structuredContent, 'The separate exporter must be able to use the real raw reader.');
        }
        $draft = $control->callTool('cms_get_articles', ['ids' => [49]]);
        editorialCheck(true === $draft->isError && ['error', 'completedCalls'] === array_keys($draft->structuredContent), 'The exporter may only read published records.');
    } finally {
        $control->disconnect();
    }
    $manifest = json_decode(file_get_contents($capture.'/host.json'), true, flags: \JSON_THROW_ON_ERROR);
    $evidence = ['format' => 'symfony-editorial-review/v1', 'verified' => true,
        'protocol' => 'Real PHP MCP SDK client and server over authenticated loopback HTTP. One findings-only user and session. An operator revokes access between the raw reads and refresh. The follow-up runs in the deterministic PHP client. No model is called.',
        'sourceSha256' => $manifest['sourceSha256'], 'datasetSha256' => $manifest['datasetSha256'], 'calls' => $calls,
        'followUp' => ['question' => 'Which missing image description affects the most published articles?', 'highestImpact' => $impact, 'additionalMcpCalls' => $followUpCalls],
        'checks' => ['oracleMatches' => true, 'onlyApprovedFieldsInBothResultRepresentations' => true, 'sourceReadsAuthorizedAndCharged' => true, 'rawArticleAndMediaDeniedBeforeHandler' => true, 'sameSessionRefreshDeniedAfterRevocation' => true, 'allTiesPreserved' => true, 'separateExporterCanReadPublicRecordsOnly' => true]];
} finally {
    try {
        $client->disconnect();
    } finally {
        if (is_dir($capture)) {
            touch($capture.'/stop');
        }
        try {
            $host->wait();
            editorialCheck($host->isSuccessful() && is_file($capture.'/cleanup.json') && true === json_decode(file_get_contents($capture.'/cleanup.json'), true)['applicationRemoved'], 'Disposable application did not exit cleanly.');
        } finally {
            $fs->remove($capture);
        }
    }
}
echo json_encode($evidence, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n";
