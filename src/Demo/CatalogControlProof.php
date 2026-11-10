<?php

declare(strict_types=1);

namespace App\Demo;

use CodeMode\{BatchSession, ExecutionIdentity, ExecutionLimits, Tool, ToolBroker, ToolCatalog, ToolDefinition};
use CodeMode\Symfony\Ai\BatchToolbox;
use Symfony\AI\Platform\Result\ToolCall;

/** Fixed operator probes with ordinary application calls; no provider call or generated program. */
final class CatalogControlProof
{
    public static function run(string $projectDir): array
    {
        $trace = new RunTrace(); $trace->captureDetails = true;
        $gateway = (new BackendFactory($projectDir))->create('fixture', evaluation: true);
        $catalog = $gateway->discover(); $journal = new AuditReadJournal();
        $identity = new ExecutionIdentity('demo-editor', ['content.audit']);
        $session = new BatchSession($catalog, new ToolBroker($journal->wrap($gateway->approvedTools($catalog))), $identity,
            new ExecutionLimits(wallTimeMs: 15000, toolTimeMs: 3000), maxBatches: 5);
        $toolbox = new RecordingBatchToolbox(new BatchToolbox($session, catalog: true), $trace);
        $invoke = static function (string $name, array $args) use ($toolbox, $trace): array {
            $result = $toolbox->execute(new ToolCall('probe-'.($trace->agentToolCalls + 1), $name, $args));
            return json_decode($result->getResult(), true, flags: JSON_THROW_ON_ERROR);
        };
        $invoke('search_tools', ['query' => '']);
        $cases = [];
        foreach ([['authorized', 'cms_get_articles', ['ids' => [1]]], ['restricted', 'cms_get_articles', ['ids' => [49]]],
            ['unapproved', 'cms_delete_article', ['id' => 1]], ['invalid_arguments', 'cms_list_articles', ['offset' => '0', 'limit' => 12]],
            ['recovery', 'cms_get_articles', ['ids' => [2]]], ['attempt_limit', 'cms_get_articles', ['ids' => [2]]]] as [$name, $tool, $args]) {
            $from = count($trace->executions);
            $result = $invoke('execute_tools', ['calls' => [['name' => $tool, 'arguments' => $args]]]);
            $cases[] = ['case' => $name, 'response' => $result, 'execution' => $trace->executions[$from] ?? null];
        }
        $forged = $invoke('execute_tools', ['calls' => [['name' => 'cms_get_articles', 'arguments' => ['ids' => [1]]]], 'identity' => 'admin', 'code' => 'return 1;']);

        // Finite slow handler: proves a cooperative check, never forced termination.
        $finished = false;
        $schema = (object) ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
        $slow = new Tool('probe_wait', $schema, static fn (ExecutionIdentity $id) => $id->allows('content.audit'), static function () use (&$finished): int {
            usleep(220000); $finished = true; return 1;
        });
        $slowTrace = new RunTrace(); $slowTrace->captureDetails = true;
        $slowToolbox = new RecordingBatchToolbox(new BatchToolbox(new BatchSession(new ToolCatalog([new ToolDefinition('probe_wait', 'Operator timeout fixture.', $schema)]),
            new ToolBroker([$slow]), $identity, new ExecutionLimits(wallTimeMs: 1000, toolTimeMs: 150)), catalog: true), $slowTrace);
        $slowToolbox->execute(new ToolCall('search', 'search_tools', ['query' => '']));
        $start = hrtime(true);
        $timeout = json_decode($slowToolbox->execute(new ToolCall('timeout', 'execute_tools', ['calls' => [['name' => 'probe_wait', 'arguments' => []]]]))->getResult(), true, flags: JSON_THROW_ON_ERROR);
        $elapsed = (hrtime(true) - $start) / 1e6;
        $report = ['kind' => 'catalog-controls', 'capturedAt' => gmdate(DATE_ATOM), 'mode' => 'operator', 'generatedCode' => false, 'execution' => 'in-process',
            'scope' => 'Authorized, denied, invalid and recovery reads use the real Symfony MCP fixture. A finite slow handler proves deadlines are checked after return, without hard termination. The fixture MCP transport starts an installed PHP server. No model, JSON execution worker, Docker or gVisor is called.',
            'cases' => $cases, 'forgedConfiguration' => $forged, 'trace' => CatalogProofRunner::snapshot($trace), 'upstream' => $journal->events(),
            'timeout' => ['response' => $timeout, 'trace' => CatalogProofRunner::snapshot($slowTrace), 'elapsedMs' => $elapsed, 'toolTimeMs' => 150, 'handlerReturned' => $finished, 'hardTermination' => false]];
        $report = json_decode(json_encode($report, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $report['verification'] = self::verify($report);
        return $report;
    }

    public static function verify(array $report): array
    {
        $cases = array_column($report['cases'] ?? [], null, 'case');
        $checks = ['allowedRead' => ($cases['authorized']['response']['results'][0]['value']['items'][0]['id'] ?? null) === 1,
            'restrictedReadDenied' => 'tool_denied' === ($cases['restricted']['response']['error']['reason'] ?? null) && 'denied' === ($cases['restricted']['execution']['toolCalls'][0]['status'] ?? null) && !isset($cases['restricted']['response']['results']),
            'unapprovedToolDenied' => 'tool_denied' === ($cases['unapproved']['response']['error']['reason'] ?? null),
            'schemaEnforced' => 'invalid_arguments' === ($cases['invalid_arguments']['response']['error']['reason'] ?? null),
            'sameSessionRecovery' => 2 === ($cases['recovery']['response']['results'][0]['value']['items'][0]['id'] ?? null),
            'attemptBudgetEnforced' => 'execution_limit' === ($cases['attempt_limit']['response']['error']['reason'] ?? null),
            'hostConfigurationRejected' => 'invalid_arguments' === ($report['forgedConfiguration']['error']['reason'] ?? null),
            'onlyAllowedCallsReachHandler' => array_column(array_column($report['upstream'] ?? [], 'arguments'), 'ids') === [[1], [2]],
            'deadlineObserved' => 'in-process' === ($report['execution'] ?? null)
                ? ('tool_deadline_exceeded' === ($report['timeout']['response']['error']['reason'] ?? null) && true === ($report['timeout']['handlerReturned'] ?? null) && false === ($report['timeout']['hardTermination'] ?? null))
                : 'tool_timeout' === ($report['timeout']['response']['error']['reason'] ?? null) && true === ($report['timeout']['workerReaped'] ?? null)
                && ($report['timeout']['elapsedMs'] ?? 0) >= 150 && $report['timeout']['elapsedMs'] < 2000,
            'noGeneratedCode' => false === ($report['generatedCode'] ?? null) && 0 === ($report['trace']['modelRequests'] ?? null)];
        return ['passed' => !in_array(false, $checks, true), 'checks' => $checks];
    }
}
