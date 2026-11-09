<?php

declare(strict_types=1);

namespace SurfaceProof;

use CodeMode\{BatchSession, BatchResult, CodeModeSession, ExecutionIdentity, ExecutionLimits, DockerEngine, GvisorSandbox, SandboxException};
use CodeMode\Provider\ProviderRegistry;

/** Reference execution comparison. No inference and no hidden transformations in the batch path. */
final class BatchComparison
{
    public static function calls(string ...$keywords): array
    {
        return array_map(static fn ($keyword) => ['name' => 'drupal.search', 'arguments' => ['values' => ['keywords' => $keyword, 'limit' => 10]]], $keywords);
    }

    public function run(Connection $connection, ?DockerEngine $docker = null, string $image = ''): array
    {
        $limits = new ExecutionLimits(wallTimeMs: 15000, toolTimeMs: 3000, maxToolCalls: 2);
        $report = ['version' => 1, 'kind' => 'data-surface-batch-comparison', 'createdAt' => gmdate(DATE_ATOM),
            'mode' => null === $docker ? 'batch-only' : 'paired', 'modelCalled' => false,
            'scope' => 'Operator-written JSON and PHP. Actual MCP reads and execution traces; no model token, request, cost or answer-quality comparison.',
            'limits' => get_object_vars($limits) + ['executionAttempts' => 2], 'runs' => []];
        $restore = false;
        $journal = tmpfile();
        if (false === $journal) { throw new \RuntimeException('Cannot open private observation journal.'); }
        try {
            $report['before'] = $connection->control('snapshot');
            if (!Verifier::fixture($report['before'])) { throw new \RuntimeException('Unexpected fixture.'); }
            $providers = new ProviderRegistry([new ObservedProvider($connection->provider(), $journal)]);
            $report['contracts'] = $providers->catalog()->all();
            $report['origins'] = $providers->origins();
            $identity = new ExecutionIdentity('surface-proof-reader', ['published.search']);
            $factories = ['batch' => static fn () => new BatchSession($providers->catalog(), $providers->broker(), $identity, $limits)];
            if (null !== $docker) {
                $docker->requireGvisor();
                $report['image'] = $image;
                $sandbox = new GvisorSandbox($image, $docker);
                $factories['php'] = static fn () => new CodeModeSession($providers->catalog(), $providers->broker(), $sandbox, $identity, $limits, phpInterface: $providers->php());
            }
            $report['task'] = 'Search SCMQ orchard and SCMQ garden, at most 10 matches each. Combine accessible results, deduplicate by ID and sort by ID; retain the per-search counts.';
            $composition = trim(file_get_contents(dirname(__DIR__).'/proof/compose.php.txt'));
            $recovery = [];
            foreach ($factories as $mode => $factory) {
                $session = $factory();
                $report['discovery'][$mode] = ['tools' => $session->toolDefinitions(), 'search' => $session->searchResult('search'), 'describe' => $session->describe(['drupal.search'])];
                $recovery[$mode] = $factory();
                $recovery[$mode]->describe(['drupal.search']);
                $capture = function (string $label, array $calls, string $php, ?object $existing = null) use (&$report, $mode, $factory, $journal, $docker): void {
                    $session = $existing ?? $factory();
                    if (null === $existing) { $session->describe(['drupal.search']); }
                    $report['runs'][$mode][$label] = $this->capture($session, $calls, $php, $journal, $docker);
                };
                $capture('composition', self::calls('SCMQ orchard', 'SCMQ garden'), $composition, $session);
                $capture('empty', self::calls('SCMQ no-such-result'), 'return $tools->drupal->search(["values" => ["keywords" => "SCMQ no-such-result", "limit" => 10]]);', $session);
                $capture('attempt-budget', self::calls('SCMQ orchard'), 'return 1;', $session);
                $capture('restricted', self::calls('PRIVATE_DRAFT_CANARY', 'PRIVATE_FIELD_CANARY'), 'return ["draft" => $tools->drupal->search(["values" => ["keywords" => "PRIVATE_DRAFT_CANARY", "limit" => 10]]), "field" => $tools->drupal->search(["values" => ["keywords" => "PRIVATE_FIELD_CANARY", "limit" => 10]])];');
                $capture('invalid-limit', [['name' => 'drupal.search', 'arguments' => ['values' => ['limit' => 11]]]], 'return $tools->drupal->search(["values" => ["limit" => 11]]);');
                $capture('unknown-input', [['name' => 'drupal.search', 'arguments' => ['values' => ['limit' => 3, 'include_unpublished' => true]]]], 'return $tools->drupal->search(["values" => ["limit" => 3, "include_unpublished" => true]]);');
                $capture('raw-name', [['name' => Connection::WIRE_TOOL, 'arguments' => ['values' => ['limit' => 3]]]], 'return $tools->call("'.Connection::WIRE_TOOL.'", ["values" => ["limit" => 3]]);');
            }
            // Both paths discovered before revocation; use those exact sessions afterward.
            $restore = true;
            $report['revoked'] = $connection->control('revoke');
            $read = 'return $tools->drupal->search(["values" => ["keywords" => "SCMQ orchard", "limit" => 10]]);';
            foreach ($recovery as $mode => $session) { $report['runs'][$mode]['revoked'] = $this->capture($session, self::calls('SCMQ orchard'), $read, $journal, $docker); }
            $connection->control('restore');
            $restore = false;
            foreach ($recovery as $mode => $session) { $report['runs'][$mode]['restored'] = $this->capture($session, self::calls('SCMQ orchard'), $read, $journal, $docker); }
        } catch (\Throwable $error) {
            $report['error'] = $error instanceof SandboxException ? $error->reason : 'comparison_unavailable';
        } finally {
            if ($restore) { try { $connection->control('restore'); } catch (\Throwable) { $report['restorationFailed'] = true; } }
            try { $report['after'] = $connection->control('snapshot'); } catch (\Throwable) { $report['snapshotFailed'] = true; }
            fclose($journal);
        }
        $report = json_decode(json_encode($report, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        $report['verification'] = BatchComparisonVerifier::check($report);
        $report['status'] = in_array(false, $report['verification'], true) ? 'failed' : 'complete';
        return $report;
    }

    private function capture(BatchSession|CodeModeSession $session, array $calls, string $php, mixed $journal, ?DockerEngine $docker): array
    {
        $batch = $session instanceof BatchSession;
        fwrite(STDERR, 'Running '.($batch ? 'JSON batch' : 'gVisor PHP')."…\n");
        fseek($journal, 0, SEEK_END); $offset = ftell($journal);
        $trace = $batch ? ['request' => ['calls' => $calls], 'runtime' => BatchResult::runtime()] : ['request' => ['code' => $php], 'executedPhp' => $session->executionCode($php), 'runtime' => $session->profile()->metadata()];
        $start = hrtime(true);
        try {
            if ($batch) {
                $result = $session->execute($calls);
                $trace += ['response' => $result->response()] + $result->trace();
            } else {
                $result = $session->execute($php);
                $trace += ['response' => ['value' => $result->value], 'toolCalls' => $result->toolCalls, 'durationMs' => $result->durationMs, 'runId' => $result->runId];
            }
            $trace['status'] = 'succeeded';
        } catch (\Throwable $error) {
            $trace += ['response' => $batch ? BatchResult::failure($error) : \CodeMode\AgentResponse::failure($error, $session->profile()),
                'toolCalls' => $error instanceof SandboxException ? $error->toolCalls : [], 'runId' => $error instanceof SandboxException ? $error->runId : null, 'status' => 'failed'];
        }
        $trace['elapsedMs'] = round((hrtime(true) - $start) / 1e6, 2); // Includes worker cleanup and, for PHP, sandbox setup/removal.
        if (!$batch && null !== ($trace['runId'] ?? null)) { $trace['containerRemoved'] = '' === $docker->control(['ps', '-aq', '--filter', 'name=^/'.$trace['runId'].'$']); }
        fseek($journal, $offset);
        $trace['upstream'] = [];
        while (false !== ($line = fgets($journal))) { $trace['upstream'][] = json_decode($line, true, 32, JSON_THROW_ON_ERROR); }
        foreach (['request', 'response'] as $key) {
            $text = json_encode($trace[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $trace[$key.'Bytes'] = strlen($text); $trace[$key.'Sha256'] = hash('sha256', $text);
        }
        if (!$batch) { $trace['sourceSha256'] = hash('sha256', $trace['executedPhp']); }
        return $trace;
    }
}
