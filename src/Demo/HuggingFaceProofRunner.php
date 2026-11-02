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

    public function run(?\Closure $progress = null, string $approach = 'code', string $protocol = 'legacy', ?string $codeCaptureJson = null): array
    {
        if (!in_array($approach, ['code', 'direct'], true)) {
            throw new \InvalidArgumentException('Choose code or direct.');
        }
        if ('' === trim(BackendFactory::env('HUGGINGFACE_API_KEY'))) {
            throw new \LogicException('Set HUGGINGFACE_API_KEY in ignored .env.local before running the live capture.');
        }
        $systemPrompt = AuditPrompt::build($approach, DemoQuestion::preset(), $protocol);
        $maxRequests = AuditPrompt::maxRequests($approach, $protocol);
        $report = ['version' => 1, 'kind' => 'direct' === $approach ? 'huggingface-baseline' : 'huggingface-proof', 'mode' => 'live', 'status' => 'running', 'createdAt' => gmdate(DATE_ATOM),
            'provider' => 'Hugging Face Inference Providers', 'endpoint' => 'https://router.huggingface.co/v1/chat/completions',
            'backend' => 'Symfony MCP fixture', 'model' => BackendFactory::env('HUGGINGFACE_MODEL', 'openai/gpt-oss-120b'),
            'task' => AuditDataset::TASK, 'runtime' => 'runsc', 'image' => BackendFactory::env('CODE_MODE_IMAGE'),
            'limits' => 'code' === $approach
                ? ['wallTimeMs' => 15000, 'toolTimeMs' => 3000, 'memoryMiB' => 128, 'maxToolCalls' => 30, 'executionAttempts' => 2, 'maxModelRequests' => $maxRequests]
                : ['toolTimeMs' => 3000, 'maxToolRounds' => 20, 'maxAdmittedToolCalls' => 60, 'executionAttempts' => 0, 'maxModelRequests' => $maxRequests],
            'components' => [], 'result' => null];
        $report['protocol'] = $protocol;
        $report['systemPrompt'] = $systemPrompt;
        $report['sharedPromptSha256'] = hash('sha256', AuditPrompt::shared($protocol));
        $report['generationSettings'] = AuditPrompt::options($protocol) + ['providerRouting' => 'auto'];
        $report['identity'] = ['subject' => 'demo-editor', 'capabilities' => ['content.audit']];
        $dataset = new AuditDataset();
        $report['datasetSha256'] = hash('sha256', json_encode([$dataset->articles(), $dataset->media()], JSON_THROW_ON_ERROR));
        if ('direct' === $approach) {
            $codeBytes = $codeCaptureJson ?? file_get_contents($this->projectDir.'/docs/evidence/huggingface-proof.json');
            $code = json_decode($codeBytes, true, flags: JSON_THROW_ON_ERROR);
            $validReference = 'legacy' === $protocol
                ? 'complete' === $code['status'] && (new HuggingFaceProofVerifier())->verify($code['result'], $maxRequests)['passed']
                : in_array($code['status'], ['complete', 'failed'], true) && ($code['protocol'] ?? null) === $protocol;
            if (!$validReference || $code['model'] !== $report['model'] || $code['task'] !== $report['task']) {
                throw new \LogicException('The baseline must match the reviewed Code Mode model and question.');
            }
            $report['codeCaptureSha256'] = hash('sha256', $codeBytes);
            $report['runtime'] = null;
            $report['image'] = null;
        }
        foreach (['symfony/ai-agent', 'symfony/ai-hugging-face-platform', 'mcp/sdk', 'ronaldtebrake/php-code-mode'] as $package) {
            $report['components'][$package] = InstalledVersions::getPrettyVersion($package);
        }
        $progress?->__invoke($report);
        $context = null;
        $started = hrtime(true);
        try {
            // Require gVisor and the pinned image before constructing/invoking the provider.
            $context = $this->factory->create($approach, 'fixture');
            $context->trace->captureDetails = true;
            $report['approvedTools'] = array_values($context->catalog->all());
            if ('direct' === $approach) {
                $report['toolAliases'] = [];
                foreach ($context->catalog->all() as $definition) {
                    $report['toolAliases'][$context->toolbox->aliasFor($definition->name)] = $definition->name;
                }
            }
            $platform = Factory::createPlatform(BackendFactory::env('HUGGINGFACE_API_KEY'), httpClient: $this->httpClient->withOptions(['timeout' => 45, 'max_duration' => 60, 'max_redirects' => 0]));
            $runner = new DemoRunner($this->factory, $platform, $this->projectDir);
            $report['result'] = $runner->run('live', $approach, context: $context, maxModelRequests: $maxRequests, protocol: $protocol);
        } catch (\Throwable $error) {
            // Never persist provider exception messages, HTTP headers or transport diagnostics.
            $report['errorType'] = $error::class;
            if ($error instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface) {
                $report['httpStatus'] = $error->getResponse()->getStatusCode();
            }
            $report['error'] = match (true) {
                $error instanceof AuthenticationException => 'provider_authentication_failed',
                $error instanceof RateLimitExceededException => 'provider_rate_limited',
                $error instanceof SandboxException => $error->reason,
                default => 'live_execution_failed',
            };
            if (null !== $context) {
                $trace = $context->trace;
                $report['result'] = ['approach' => $approach, 'mode' => 'live', 'modelRequests' => $trace->modelRequests, 'agentToolCalls' => $trace->agentToolCalls,
                    'toolPayloadBytes' => $trace->toolPayloadBytes, 'providerTokens' => null, 'brokerCalls' => $trace->brokerCalls, 'programs' => $trace->programs,
                    'executions' => $trace->executions, 'agentCalls' => $trace->agentCalls, 'report' => $trace->report, 'answer' => '', 'events' => $trace->events,
                    'requestEvidence' => $trace->requestEvidence, 'providerResponses' => $trace->providerResponses];
            }
        }
        if ('code' === $approach && null !== $report['result']) {
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
        $report['verification'] = 'code' === $approach ? (new HuggingFaceProofVerifier())->verify($report['result'] ?? [], $maxRequests) : (new HuggingFaceBaselineVerifier())->verify($report, $maxRequests);
        $report['status'] = !isset($report['error']) && $report['verification']['passed'] ? 'complete' : 'failed';
        $report['elapsedMs'] = (hrtime(true) - $started) / 1_000_000;
        $report['completedAt'] = gmdate(DATE_ATOM);
        $progress?->__invoke($report);
        return $report;
    }
}
