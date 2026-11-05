<?php

declare(strict_types=1);

namespace App\Demo\Performance;

use App\Demo\BackendFactory;
use CodeMode\DockerEngine;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ProofRunner
{
    public const QUESTIONS = [
        'Investigate the slowest approved baseline request. Correlate its query fingerprints with its logs and identify repeated work. Do not change code or data.',
        'Now compare the before and after catalogue implementations. Did the joined version return identical content with fewer queries? Keep the same read permissions.',
    ];
    public const INVESTIGATE = <<<'PHP'
$requests = $tools->call("perf_requests", []);
$baseline = array_values(array_filter($requests->items, fn($r) => $r->variant !== "after"));
usort($baseline, fn($a, $b) => $b->durationMs <=> $a->durationMs);
$slow = $baseline[0];
$profile = $tools->call("perf_profile", ["requestId" => $slow->requestId]);
$logs = $tools->call("perf_logs", ["requestId" => $slow->requestId]);
return ["profile" => $profile,
    "repeatedQueries" => array_values(array_filter($profile->queries, fn($q) => $q->count > 1)),
    "events" => $logs->events];
PHP;
    public const COMPARE = <<<'PHP'
$requests = $tools->call("perf_requests", []);
$comparison = [];
foreach ($requests->items as $request) {
    if (!in_array($request->variant, ["before", "after"], true)) { continue; }
    $profile = $tools->call("perf_profile", ["requestId" => $request->requestId]);
    $logs = $tools->call("perf_logs", ["requestId" => $request->requestId]);
    $comparison[$request->variant] = ["profile" => $profile, "events" => $logs->events];
}
$before = $comparison["before"]["profile"];
$after = $comparison["after"]["profile"];
return $comparison + ["sameOutput" => $before->outputSha256 === $after->outputSha256,
    "queriesSaved" => $before->queryCount - $after->queryCount];
PHP;

    public function __construct(#[Autowire('%kernel.project_dir%')] private string $projectDir) {}

    public function run(): array
    {
        $report = ['version' => 1, 'kind' => 'performance-proof', 'mode' => 'reference', 'status' => 'running', 'createdAt' => gmdate(DATE_ATOM),
            'runtime' => 'runsc', 'image' => BackendFactory::env('CODE_MODE_IMAGE'),
            'source' => 'Synthetic Symfony HttpKernel requests, persisted/reloaded Symfony Profiler custom collector, real PDO SQLite queries and PSR-3 log events. Raw profiles are deleted before tools are exposed.',
            'projection' => 'Public fields only before the broker starts; explicit bounded output schemas on all three readers.',
            'simulatedLatencyPerQueryMs' => RequestCollector::QUERY_DELAY_US / 1000, 'modelRequests' => 0,
            'limits' => ['wallTimeMs' => 5000, 'memoryMiB' => 128, 'maxToolCalls' => 10, 'attemptsPerSession' => 2],
            'discovery' => [], 'turns' => [], 'denials' => []];
        try {
            $docker = new DockerEngine(json_decode(BackendFactory::env('CODE_MODE_DOCKER_COMMAND', '["docker"]'), true, flags: JSON_THROW_ON_ERROR));
            $docker->requireGvisor();
            $this->session(function (Client $client) use (&$report, $docker): void {
                $report['discovery'] = $this->discover($client);
                foreach ([self::INVESTIGATE, self::COMPARE] as $index => $php) {
                    $report['turns'][] = ['question' => self::QUESTIONS[$index]] + $this->execute($client, $docker, $php);
                }
                $report['exhausted'] = $client->callTool('php_execute', ['code' => 'return 1;'])->structuredContent;
            });
            // A separate operator-started security session; denials do not reset the investigation budget.
            $this->session(function (Client $client) use (&$report, $docker): void {
                $this->discover($client);
                foreach (['perf_profile', 'perf_logs'] as $name) {
                    $report['denials'][] = $this->execute($client, $docker, 'return $tools->call("'.$name.'", ["requestId" => "profile-04"]);');
                }
            });
        } catch (\Throwable $error) {
            $report['error'] = 'performance_proof_failed';
            $report['errorType'] = $error::class;
        }
        $report['verification'] = (new ProofVerifier())->verify($report);
        $report['status'] = !isset($report['error']) && $report['verification']['passed'] ? 'complete' : 'failed';
        $report['completedAt'] = gmdate(DATE_ATOM);
        return $report;
    }

    private function session(\Closure $run): void
    {
        $env = ['APP_ENV' => 'test', 'APP_DEBUG' => '0']; // Dotenv skips the main .env.local; no inference token is needed.
        foreach (['PATH', 'HOME', 'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_CONFIG', 'SSH_AUTH_SOCK', 'XDG_RUNTIME_DIR', 'TMPDIR', 'CODE_MODE_IMAGE', 'CODE_MODE_DOCKER_COMMAND'] as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
            if (is_string($value)) { $env[$key] = $value; }
        }
        $client = Client::builder()->setClientInfo('performance-proof', '1')->setInitTimeout(10)->setRequestTimeout(30)->setMaxRetries(0)->build();
        try {
            $client->connect(new StdioTransport(PHP_BINARY, ['bin/console', 'code-mode:performance-mcp', '--no-debug'], $this->projectDir, $env, maxBufferSize: 65536));
            $run($client);
        } finally { $client->disconnect(); }
    }

    private function discover(Client $client): array
    {
        $search = $client->callTool('tools_search', ['query' => 'perf']);
        $describe = $client->callTool('tools_describe', ['names' => ReadTools::NAMES]);
        if ($search->isError || $describe->isError) { throw new \RuntimeException('Discovery failed.'); }
        return ['search' => $search->structuredContent, 'describe' => $describe->structuredContent];
    }

    private function execute(Client $client, DockerEngine $docker, string $php): array
    {
        $result = $client->callTool('php_execute', ['code' => $php]);
        $trace = $result->structuredContent;
        $runId = $trace['runId'] ?? null;
        $removed = is_string($runId) && 1 === preg_match('/\Acode-mode-[a-f0-9]{32}\z/D', $runId)
            && '' === $docker->control(['ps', '-aq', '--filter', 'name=^/'.$runId.'$']);
        return ['php' => $php, 'isError' => $result->isError, 'trace' => $trace, 'containerRemoved' => $removed];
    }
}
