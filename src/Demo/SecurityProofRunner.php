<?php

declare(strict_types=1);

namespace App\Demo;

use CodeMode\DockerEngine;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\GvisorSandbox;
use CodeMode\SandboxException;
use CodeMode\ToolBroker;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Operator-run fixed probes. No model, user PHP or runtime settings arrive through HTTP/MCP. */
final readonly class SecurityProofRunner
{
    private const CASES = [
        ['id' => 'authorized_read', 'title' => 'Authorized read', 'expected' => 'succeeded',
            'code' => 'return $tools->call("cms_list_articles", ["offset" => 0, "limit" => 1])->total;'],
        ['id' => 'unauthorized_call', 'title' => 'Unauthorized tool', 'expected' => 'tool_denied',
            'code' => 'return $tools->call("cms_delete_article", ["id" => 1]);'],
        ['id' => 'infinite_loop', 'title' => 'Infinite loop', 'expected' => 'wall_timeout',
            'code' => '$tools->call("cms_list_articles", ["offset" => 0, "limit" => 1]);'."\n".'while (true) {}'],
    ];

    public function __construct(#[Autowire('%kernel.project_dir%')] private string $projectDir)
    {
    }

    public function run(?\Closure $progress = null): array
    {
        $report = ['version' => 1, 'kind' => 'security-proof', 'createdAt' => gmdate(\DATE_ATOM), 'status' => 'running',
            'runtime' => 'runsc', 'image' => BackendFactory::env('CODE_MODE_IMAGE'), 'cases' => []];
        $progress?->__invoke($report);
        try {
            $docker = new DockerEngine(json_decode(BackendFactory::env('CODE_MODE_DOCKER_COMMAND', '["docker"]'), true, flags: \JSON_THROW_ON_ERROR));
            $sandbox = new GvisorSandbox($report['image'], $docker);
            $gateway = (new BackendFactory($this->projectDir))->create('fixture');
            $catalog = $gateway->discover();
            $names = array_map(static fn ($tool) => $tool->name, $catalog->all());
            if ([] !== array_diff(BackendFactory::FIXTURE_TOOLS, $names) || \in_array('cms_delete_article', $names, true)) {
                throw new \RuntimeException('The proof requires the approved read-only fixture catalog.');
            }
            $broker = $gateway->broker($catalog);
            // Each fixed probe is a separate operator-authorized execution with a tighter time budget.
            $limits = new ExecutionLimits(wallTimeMs: 2000, toolTimeMs: 1000, maxToolCalls: 3);
            foreach (self::CASES as $case) {
                $report['cases'][] = $this->runCase($case, $sandbox, $docker, $broker, $limits);
                $progress?->__invoke($report);
            }
            $report['status'] = \count(self::CASES) === \count(array_filter($report['cases'], static fn ($case) => true === $case['passed'])) ? 'complete' : 'failed';
        } catch (\Throwable $error) {
            $report['status'] = 'failed';
            $report['error'] = $error instanceof SandboxException ? $error->reason : 'proof_unavailable';
        }
        $report['completedAt'] = gmdate(\DATE_ATOM);
        $progress?->__invoke($report);

        return $report;
    }

    private function runCase(array $case, GvisorSandbox $sandbox, DockerEngine $docker, ToolBroker $broker, ExecutionLimits $limits): array
    {
        $case += ['startedAt' => gmdate(\DATE_ATOM), 'wallTimeMs' => $limits->wallTimeMs, 'memoryMiB' => $limits->memoryMiB,
            'runId' => null, 'value' => null, 'reason' => null, 'toolCalls' => [], 'containerRemoved' => null];
        $started = hrtime(true);
        try {
            $result = $sandbox->execute($case['code'], $broker, new ExecutionIdentity('demo-editor', ['content.audit']), BackendFactory::FIXTURE_TOOLS, $limits);
            $case['runId'] = $result->runId;
            $case['value'] = $result->value;
            $case['toolCalls'] = $result->toolCalls;
        } catch (SandboxException $error) {
            $case['runId'] = $error->runId;
            $case['reason'] = $error->reason;
            $case['toolCalls'] = $error->toolCalls;
        }
        $case['durationMs'] = (hrtime(true) - $started) / 1_000_000;
        // Independently query the exact host-generated run ID after the runner's cleanup.
        if (\is_string($case['runId']) && preg_match('/\Acode-mode-[a-f0-9]{32}\z/D', $case['runId'])) {
            try {
                $case['containerRemoved'] = '' === $docker->control(['ps', '-aq', '--filter', 'name=^/'.$case['runId'].'$']);
                $case['cleanupCheckedAt'] = gmdate(\DATE_ATOM);
            } catch (SandboxException) {
                // A failed Docker query cannot prove that execution stopped.
            }
        }
        $case['passed'] = (new SecurityProofVerifier())->verify($case['id'], $case['value'], $case['reason'], $case['toolCalls'], $case['containerRemoved']);
        $case['completedAt'] = gmdate(\DATE_ATOM);

        return $case;
    }
}
