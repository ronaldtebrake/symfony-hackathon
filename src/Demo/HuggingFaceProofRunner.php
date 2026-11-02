<?php

declare(strict_types=1);

namespace App\Demo;

use CodeMode\DockerEngine;
use CodeMode\SandboxException;
use Composer\InstalledVersions;
use Symfony\AI\Platform\Bridge\HuggingFace\Factory;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A fixed, operator-started Symfony fixture audit. No execution controls are exposed over HTTP. */
final readonly class HuggingFaceProofRunner
{
    public function __construct(private DemoFactory $factory, private HttpClientInterface $httpClient, #[Autowire('%kernel.project_dir%')] private string $projectDir) {}

    public function run(?\Closure $progress = null): array
    {
        if ('' === trim(BackendFactory::env('HUGGINGFACE_API_KEY'))) {
            throw new \LogicException('Set HUGGINGFACE_API_KEY in ignored .env.local before running the live capture.');
        }
        $report = ['version' => 1, 'kind' => 'huggingface-proof', 'mode' => 'live', 'status' => 'running', 'createdAt' => gmdate(DATE_ATOM),
            'provider' => 'Hugging Face Inference Providers', 'endpoint' => 'https://router.huggingface.co/v1/chat/completions',
            'backend' => 'Symfony MCP fixture', 'model' => BackendFactory::env('HUGGINGFACE_MODEL', 'openai/gpt-oss-120b'),
            'task' => AuditDataset::TASK, 'runtime' => 'runsc', 'image' => BackendFactory::env('CODE_MODE_IMAGE'),
            'limits' => ['wallTimeMs' => 15000, 'toolTimeMs' => 3000, 'memoryMiB' => 128, 'maxToolCalls' => 30, 'executionAttempts' => 2, 'maxModelRequests' => 8],
            'components' => [], 'result' => null];
        foreach (['symfony/ai-agent', 'symfony/ai-hugging-face-platform', 'mcp/sdk', 'ronaldtebrake/php-code-mode'] as $package) {
            $report['components'][$package] = InstalledVersions::getPrettyVersion($package);
        }
        $progress?->__invoke($report);
        $context = null;
        $started = hrtime(true);
        try {
            // Require gVisor and the pinned image before constructing/invoking the provider.
            $context = $this->factory->create('code', 'fixture');
            $context->trace->captureDetails = true;
            $platform = Factory::createPlatform(BackendFactory::env('HUGGINGFACE_API_KEY'), httpClient: $this->httpClient->withOptions(['timeout' => 45, 'max_duration' => 60, 'max_redirects' => 0]));
            $runner = new DemoRunner($this->factory, $platform, $this->projectDir);
            $report['result'] = $runner->run('live', 'code', context: $context, maxModelRequests: 8);
        } catch (\Throwable $error) {
            // Never persist provider exception messages, HTTP headers or transport diagnostics.
            $report['error'] = match (true) {
                $error instanceof AuthenticationException => 'provider_authentication_failed',
                $error instanceof RateLimitExceededException => 'provider_rate_limited',
                $error instanceof SandboxException => $error->reason,
                default => 'live_execution_failed',
            };
            if (null !== $context) {
                $trace = $context->trace;
                $report['result'] = ['approach' => 'code', 'mode' => 'live', 'modelRequests' => $trace->modelRequests, 'agentToolCalls' => $trace->agentToolCalls,
                    'toolPayloadBytes' => $trace->toolPayloadBytes, 'providerTokens' => null, 'brokerCalls' => $trace->brokerCalls, 'programs' => $trace->programs,
                    'executions' => $trace->executions, 'agentCalls' => $trace->agentCalls, 'report' => $trace->report, 'answer' => '', 'events' => $trace->events];
            }
        }
        if (null !== $report['result']) {
            $docker = new DockerEngine(json_decode(BackendFactory::env('CODE_MODE_DOCKER_COMMAND', '["docker"]'), true, flags: JSON_THROW_ON_ERROR));
            foreach ($report['result']['executions'] as &$execution) {
                $execution['containerRemoved'] = null;
                if (is_string($execution['runId']) && preg_match('/\Acode-mode-[a-f0-9]{32}\z/D', $execution['runId'])) {
                    try {
                        $execution['containerRemoved'] = '' === $docker->control(['ps', '-aq', '--filter', 'name=^/'.$execution['runId'].'$']);
                        $execution['cleanupCheckedAt'] = gmdate(DATE_ATOM);
                    } catch (\Throwable) {
                        // A failed daemon query cannot prove cleanup.
                    }
                }
            }
            unset($execution);
        }
        $report['verification'] = (new HuggingFaceProofVerifier())->verify($report['result'] ?? []);
        $report['status'] = !isset($report['error']) && $report['verification']['passed'] ? 'complete' : 'failed';
        $report['elapsedMs'] = (hrtime(true) - $started) / 1_000_000;
        $report['completedAt'] = gmdate(DATE_ATOM);
        $progress?->__invoke($report);
        return $report;
    }
}
