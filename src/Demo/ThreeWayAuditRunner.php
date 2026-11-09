<?php

declare(strict_types=1);

namespace App\Demo;

use CodeMode\{BatchSession, CodeModeSession, DockerEngine, ExecutionIdentity, ExecutionLimits, GvisorSandbox, ToolBroker, SandboxException};
use CodeMode\Symfony\Ai\BatchToolbox;
use Composer\InstalledVersions;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Platform\Bridge\HuggingFace\Factory;
use Symfony\AI\Platform\Message\{Message, MessageBag};
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A new experiment; does not overwrite the reviewed two-way comparison. */
final readonly class ThreeWayAuditRunner
{
    public const PROTOCOL = 'audit-three-way-v1';
    public const MODEL = 'openai/gpt-oss-120b';
    public function __construct(private HttpClientInterface $httpClient, #[Autowire('%kernel.project_dir%')] private string $projectDir) {}

    public function run(string $approach, \Closure $save, string $protocol = ThreeWayAuditPrompt::CURRENT): array
    {
        if (!in_array($approach, ['direct', 'batch', 'code'], true)) { throw new \InvalidArgumentException('Unknown audit path.'); }
        $model = BackendFactory::env('HUGGINGFACE_MODEL', self::MODEL);
        if ($model !== self::MODEL || '' === trim(BackendFactory::env('HUGGINGFACE_API_KEY'))) { throw new \LogicException('Keep the configured audit model and supply the host inference key.'); }
        $trace = new RunTrace(); $trace->captureDetails = true;
        $journal = new AuditReadJournal();
        $limits = new ExecutionLimits(wallTimeMs: 15000, toolTimeMs: 3000, maxToolCalls: 30);
        $identity = new ExecutionIdentity('demo-editor', ['content.audit']);
        $dataset = new AuditDataset();
        $prompt = ThreeWayAuditPrompt::build($approach, $protocol);
        $withReview = ThreeWayAuditPrompt::ORIGINAL !== $protocol;
        $report = ['version' => 1, 'kind' => self::PROTOCOL, 'approach' => $approach, 'status' => 'running', 'createdAt' => gmdate(DATE_ATOM),
            'protocol' => $protocol, 'toolResultEncoding' => ThreeWayAuditPrompt::literalToolResults($protocol) ? 'literal-utf8' : 'escaped-json',
            'model' => $model, 'provider' => 'Hugging Face Inference Providers', 'endpoint' => 'https://router.huggingface.co/v1/chat/completions',
            'task' => AuditDataset::TASK, 'systemPrompt' => $prompt, 'sharedPromptSha256' => hash('sha256', ThreeWayAuditPrompt::shared($protocol)),
            'generationSettings' => AuditPrompt::options(AuditPrompt::COVERAGE_PROTOCOL) + ['providerRouting' => 'auto'],
            'identity' => ['subject' => $identity->subject, 'capabilities' => ['content.audit']],
            'datasetSha256' => hash('sha256', json_encode([$dataset->articles(), $dataset->media()], JSON_THROW_ON_ERROR)),
            'limits' => ['maxModelRequests' => 14, 'maxUnderlyingCalls' => 60, 'toolTimeMs' => 3000, 'brokerMemoryMiB' => 64,
                'maxToolResultBytes' => $limits->maxToolResultBytes, 'maxFrameBytes' => $limits->maxFrameBytes,
                'maxAggregateOutputBytes' => $limits->maxOutputBytes, 'wallTimeMs' => 'direct' === $approach ? null : 15000,
                'maxCallsPerSubmission' => 'direct' === $approach ? 1 : 30, 'maxSubmissions' => match ($approach) { 'direct' => 60, 'batch' => 14, 'code' => 2 }],
            'runtime' => 'code' === $approach ? 'gvisor' : 'approved-handlers', 'components' => []];
        foreach (['symfony/ai-agent', 'symfony/ai-hugging-face-platform', 'mcp/sdk', 'ronaldtebrake/php-code-mode'] as $package) { $report['components'][$package] = InstalledVersions::getPrettyVersion($package); }
        $answer = ''; $usage = null; $started = hrtime(true); $docker = null;
        $snapshot = static function () use (&$report, &$answer, &$usage, $trace, $journal, $save, $started): void {
            $report['elapsedMs'] = (hrtime(true) - $started) / 1e6;
            $report['result'] = ['approach' => $report['approach'], 'modelRequests' => $trace->modelRequests, 'agentToolCalls' => $trace->agentToolCalls,
                'toolPayloadBytes' => $trace->toolPayloadBytes, 'providerTokens' => $usage, 'answer' => $answer, 'report' => $trace->report,
                'programs' => $trace->programs, 'executions' => $trace->executions, 'brokerCalls' => $trace->brokerCalls, 'agentCalls' => $trace->agentCalls,
                'requestEvidence' => $trace->requestEvidence, 'providerResponses' => $trace->providerResponses, 'upstream' => $journal->events()];
            $save($report);
        };
        $snapshot();
        try {
            $gateway = (new BackendFactory($this->projectDir))->create('fixture');
            $catalog = $gateway->discover();
            $broker = new ToolBroker($journal->wrap($gateway->approvedTools($catalog)));
            $report['approvedTools'] = $catalog->all();
            if ('batch' === $approach) {
                $session = new BatchSession($catalog, $broker, $identity, $limits, maxBatches: 14, maxSessionToolCalls: 60);
                $toolbox = new RecordingBatchToolbox(new BatchToolbox($session, catalog: ThreeWayAuditPrompt::usesCatalog($protocol), literalUnicode: ThreeWayAuditPrompt::literalToolResults($protocol)), $trace);
            } else {
                // The direct adapter never executes its PHP session. Only Code Mode checks/uses Docker.
                $docker = new DockerEngine(json_decode(BackendFactory::env('CODE_MODE_DOCKER_COMMAND', '["docker"]'), true, flags: JSON_THROW_ON_ERROR));
                $sandbox = new GvisorSandbox(BackendFactory::env('CODE_MODE_IMAGE'), $docker);
                if ('code' === $approach) {
                    $docker->requireGvisor();
                    $docker->control(['image', 'inspect', '--format', '{{.Id}}', BackendFactory::env('CODE_MODE_IMAGE')]);
                    $report['image'] = BackendFactory::env('CODE_MODE_IMAGE');
                }
                $session = new CodeModeSession($catalog, $broker, $sandbox, $identity, $limits);
                $toolbox = new AuditToolbox($approach, $catalog, $broker, $session, $trace, literalUnicode: ThreeWayAuditPrompt::literalToolResults($protocol));
                if ('direct' === $approach) {
                    foreach ($catalog->all() as $definition) { $report['toolAliases'][$toolbox->aliasFor($definition->name)] = $definition->name; }
                }
            }
            $report['entryTools'] = array_map(static fn ($tool) => $tool->getName(), $toolbox->getTools());
            $snapshot();
            $platform = Factory::createPlatform(BackendFactory::env('HUGGINGFACE_API_KEY'), httpClient: $this->httpClient->withOptions(['timeout' => 45, 'max_duration' => 60, 'max_redirects' => 0]));
            $agent = new Agent(new BudgetedPlatform($platform, $trace, $withReview ? 13 : 14), $model, name: 'audit-'.$approach, toolbox: $toolbox, maxToolCalls: 20);
            $execution = $agent->call(new MessageBag(Message::forSystem($prompt), Message::ofUser(AuditDataset::TASK)), AuditPrompt::options(AuditPrompt::COVERAGE_PROTOCOL));
            $execution->onProgress(static function (Progress $update) use ($snapshot): void { $snapshot(); });
            $draft = (string) $execution->getContent();
            $draftUsage = self::usage($execution->getMetadata()->get('token_usage'));
            if ($withReview) {
                $report['draft'] = ['answer' => $draft, 'modelRequest' => $trace->modelRequests, 'providerTokens' => $draftUsage];
                $report['review'] = ['instruction' => ThreeWayAuditPrompt::reviewInstruction($protocol), 'modelRequest' => $trace->modelRequests + 1,
                    'agentCallStart' => count($trace->agentCalls), 'toolsAvailable' => false, 'oracleFeedback' => false, 'costIncluded' => true, 'status' => 'running'];
                $reviewSystem = ThreeWayAuditPrompt::reviewSystem($protocol, $prompt);
                $reviewOptions = ThreeWayAuditPrompt::reviewOptions($protocol);
                $messages = $execution->getMessages()->withSystemMessage(Message::forSystem($reviewSystem));
                if (ThreeWayAuditPrompt::dedicatedReview($protocol)) {
                    $report['review'] += ['systemPrompt' => $reviewSystem, 'generationSettings' => $reviewOptions, 'explicitUserTurn' => true];
                    $messages = $messages->with(Message::ofUser($report['review']['instruction']));
                }
                $snapshot();
                $reviewer = new Agent(new BudgetedPlatform($platform, $trace, 14), $model, name: 'audit-review');
                $review = $reviewer->call($messages, $reviewOptions);
                $result = $review->getResult();
                if (!$result instanceof TextResult) { throw new \UnexpectedValueException('Review must return text without tool execution.'); }
                // Apply only the recorded model decision; never pick an answer using the verifier.
                $answer = $result->getContent();
                $reviewUsage = self::usage($review->getMetadata()->get('token_usage'));
                $report['review']['providerTokens'] = $reviewUsage;
                if (ThreeWayAuditPrompt::DECISION_REVIEW === $protocol) {
                    $report['review']['response'] = $answer;
                    $decision = ThreeWayAuditReview::apply($answer, $draft);
                    $report['review']['decision'] = $decision['decision'];
                    $answer = $decision['answer'];
                }
                $report['review'] += ['answer' => $answer, 'providerTokens' => $reviewUsage];
                $report['review']['status'] = 'complete';
                if (null !== $draftUsage && null !== $reviewUsage) {
                    $usage = [];
                    foreach (['prompt', 'completion', 'total'] as $key) { $usage[$key] = $draftUsage[$key] + $reviewUsage[$key]; }
                }
            } else { $answer = $draft; $usage = $draftUsage; }
            if ('code' !== $approach) {
                try { $trace->report = json_decode($answer, false, 32, JSON_THROW_ON_ERROR); } catch (\JsonException) { $trace->report = null; }
            }
        } catch (\Throwable $error) {
            $httpStatus = $error instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface ? $error->getResponse()->getStatusCode() : null;
            $report['error'] = match (true) {
                429 === $httpStatus, $error instanceof \Symfony\AI\Platform\Exception\RateLimitExceededException => 'provider_rate_limited',
                401 === $httpStatus, 403 === $httpStatus, $error instanceof \Symfony\AI\Platform\Exception\AuthenticationException => 'provider_authentication_failed',
                $error instanceof \RuntimeException && 'Provider request budget exhausted.' === $error->getMessage() => 'model_request_limit',
                $error instanceof \Symfony\AI\Platform\Exception\BadRequestException => 'provider_request_rejected',
                $error instanceof \UnexpectedValueException && in_array($error->getMessage(), ['Review must return text without tool execution.', 'Invalid answer review decision.'], true) => 'invalid_review_response',
                $error instanceof SandboxException => $error->reason,
                default => 'live_execution_failed',
            };
            $report['errorType'] = $error::class;
            if (null !== $httpStatus) { $report['httpStatus'] = $httpStatus; }
        }
        if ('code' === $approach && null !== $docker) {
            foreach ($trace->executions as &$execution) {
                $execution['containerRemoved'] = null;
                if (preg_match('/\Acode-mode-[a-f0-9]{32}\z/D', $execution['runId'] ?? '')) {
                    try { $execution['containerRemoved'] = '' === $docker->control(['ps', '-aq', '--filter', 'name=^/'.$execution['runId'].'$']); }
                    catch (\Throwable) {}
                }
            }
            unset($execution);
        }
        $snapshot();
        $report = json_decode(json_encode($report, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        $report['verification'] = ThreeWayAuditVerifier::verify($report);
        $report['status'] = $report['verification']['passed'] ? 'complete' : 'failed';
        $report['completedAt'] = gmdate(DATE_ATOM);
        $save($report);
        return $report;
    }

    private static function usage(mixed $tokens): ?array
    {
        return $tokens instanceof TokenUsageInterface ? ['prompt' => $tokens->getPromptTokens(), 'completion' => $tokens->getCompletionTokens(), 'total' => $tokens->getTotalTokens()] : null;
    }
}
