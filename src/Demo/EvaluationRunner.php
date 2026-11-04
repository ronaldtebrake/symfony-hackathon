<?php

declare(strict_types=1);

namespace App\Demo;

use CodeMode\{ExecutionTimeline, SandboxException, SandboxExecutor};
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Bridge\HuggingFace\Factory;
use Symfony\AI\Platform\Message\{Message, MessageBag};
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\{TextResult, ToolCall};
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Operator-only evaluation. Each arm gets fresh state; follow-ups retain that state. */
final readonly class EvaluationRunner
{
    public function __construct(private DemoFactory $factory, private HttpClientInterface $httpClient) {}

    public function run(string $scenario, string $approach, bool $live, ?\Closure $progress = null, ?PlatformInterface $testPlatform = null, ?SandboxExecutor $testSandbox = null): array
    {
        $questions = EvaluationScenario::questions($scenario);
        $prompt = EvaluationScenario::prompt($approach);
        $trace = new RunTrace(new ExecutionTimeline()); $trace->captureDetails = true;
        $report = ['scenario' => $scenario, 'protocol' => EvaluationScenario::PROTOCOL, 'approach' => $approach, 'mode' => $live ? 'live' : 'reference', 'status' => 'running',
            'createdAt' => gmdate(DATE_ATOM), 'systemPrompt' => $prompt, 'turns' => [], 'trace' => null];
        $context = null;
        $setup = $trace->timeline->begin('preflight', 'Check runtime and discover approved MCP tools');
        try {
            $context = $this->factory->create($approach, sandbox: $testSandbox, evaluation: true, trace: $trace);
            $trace->timeline->end($setup);
            if ($live) {
                $platform = $testPlatform ?? Factory::createPlatform(BackendFactory::env('HUGGINGFACE_API_KEY'), httpClient: $this->httpClient->withOptions(['timeout' => 45, 'max_duration' => 60, 'max_redirects' => 0]));
                $budget = new BudgetedPlatform($platform, $trace, EvaluationScenario::REQUEST_LIMIT);
                $model = BackendFactory::env('HUGGINGFACE_MODEL', 'openai/gpt-oss-120b');
                $agent = new Agent($budget, $model, name: 'evaluation-'.$approach, toolbox: $context->toolbox, maxToolCalls: 60);
                // Same model, settings and request budget. No tool executor in the review phase.
                $reviewer = new Agent($budget, $model, name: 'evaluation-review');
                $messages = new MessageBag(Message::forSystem($prompt));
            } elseif ('code' === $approach) {
                $this->call($context, 'tools_search', ['query' => 'article metadata media']);
                $this->call($context, 'tools_describe', ['names' => BackendFactory::FIXTURE_TOOLS]);
            }
            foreach ($questions as $index => $question) {
                $turn = ['question' => $question, 'startMs' => $trace->timeline->now(), 'agentCallStart' => count($trace->agentCalls), 'executionStart' => count($trace->executions)];
                if ($live) {
                    $execution = $agent->call($messages->with(Message::ofUser($question)), EvaluationScenario::SETTINGS);
                    $answer = (string) $execution->getContent();
                    $messages = $execution->getMessages();
                    $turn['draft'] = ['answer' => $answer, 'endMs' => $trace->timeline->now(), 'modelRequest' => $trace->modelRequests];
                    $turn['review'] = ['instruction' => EvaluationScenario::REVIEW_PROMPT, 'modelRequest' => $trace->modelRequests + 1,
                        'agentCallStart' => count($trace->agentCalls), 'executionStart' => count($trace->executions), 'status' => 'running'];
                    // Checkpoint before the paid review, including a malformed draft or interrupted review.
                    $report['turns'][$index] = $turn + ['answer' => $answer, 'endMs' => $trace->timeline->now(), 'agentCallEnd' => count($trace->agentCalls), 'executionEnd' => count($trace->executions)];
                    $report['trace'] = $this->snapshot($trace);
                    $progress?->__invoke($report);
                    $review = $reviewer->call($messages->withSystemMessage(Message::forSystem($prompt.' '.EvaluationScenario::REVIEW_PROMPT)), EvaluationScenario::SETTINGS);
                    $result = $review->getResult();
                    if (!$result instanceof TextResult) { throw new \UnexpectedValueException('Review must return text without tool execution.'); }
                    $turn['review']['answer'] = $result->getContent();
                    $turn['review']['status'] = 'complete';
                    $turn['answerSource'] = EvaluationScenario::selectAnswer($answer, $result->getContent());
                    // Keep accepted history and restore the original prompt for the follow-up.
                    // Invalid reviews remain in the report without replacing a valid draft in history.
                    if ('review' === $turn['answerSource']) {
                        $answer = $result->getContent();
                        $messages = $review->getMessages()->withSystemMessage(Message::forSystem($prompt));
                    }
                } else {
                    if ('code' === $approach) {
                        $value = $this->call($context, 'php_execute', ['code' => EvaluationScenario::program($scenario, $index)]);
                        $value = 'boundary' === $scenario && 0 === $index && 'tool_denied' === ($value->error->reason ?? null)
                            ? ['status' => 'denied', 'alternative' => 'published_metadata_audit'] : ($value->result ?? null);
                    } else {
                        $value = EvaluationScenario::reference($scenario, $index, fn ($name, $args) => $this->call($context, $name, $args));
                    }
                    $answer = json_encode($value, JSON_THROW_ON_ERROR);
                }
                $report['turns'][$index] = $turn + ['answer' => $answer, 'endMs' => $trace->timeline->now(), 'agentCallEnd' => count($trace->agentCalls), 'executionEnd' => count($trace->executions)];
                $report['trace'] = $this->snapshot($trace);
                $progress?->__invoke($report);
            }
        } catch (\Throwable $error) {
            $trace->timeline->end($setup, 'failed');
            $report['error'] = $error instanceof SandboxException ? $error->reason : 'evaluation_failed';
            $report['errorType'] = $error::class; // Never persist transport or handler exception messages.
        }
        $report['elapsedMs'] = $trace->timeline->now();
        $report['trace'] = $this->snapshot($trace);
        $report['verification'] = (new EvaluationVerifier())->verify($report);
        $report['status'] = !isset($report['error']) && $report['verification']['passed'] ? 'passed' : 'failed';
        $progress?->__invoke($report);
        return $report;
    }

    private function call(DemoContext $context, string $name, array $arguments): mixed
    {
        $result = $context->toolbox->execute(new ToolCall('reference-'.($context->trace->agentToolCalls + 1), $context->toolbox->aliasFor($name), $arguments));
        return json_decode($result->getResult(), false, 32, JSON_THROW_ON_ERROR);
    }

    private function snapshot(RunTrace $trace): array
    {
        $tokens = null;
        if ([] !== $trace->providerResponses && count($trace->providerResponses) === $trace->modelRequests && !in_array(null, array_column($trace->providerResponses, 'tokens'), true)) {
            $tokens = [];
            foreach (['prompt', 'completion', 'total'] as $key) { $tokens[$key] = array_sum(array_column(array_column($trace->providerResponses, 'tokens'), $key)); }
        }
        return ['programs' => $trace->programs, 'executions' => $trace->executions, 'brokerCalls' => $trace->brokerCalls,
            'agentCalls' => $trace->agentCalls, 'toolPayloadBytes' => $trace->toolPayloadBytes, 'modelRequests' => $trace->modelRequests,
            'providerTokens' => $tokens, 'providerResponses' => $trace->providerResponses, 'requestEvidence' => $trace->requestEvidence,
            'timeline' => $trace->timeline->snapshot()];
    }

    public static function manifest(): array
    {
        return ['protocol' => EvaluationScenario::PROTOCOL, 'model' => BackendFactory::env('HUGGINGFACE_MODEL', 'openai/gpt-oss-120b'),
            'provider' => 'Hugging Face Inference Providers', 'providerRouting' => 'auto', 'generationSettings' => EvaluationScenario::SETTINGS,
            'fixtureSha256' => hash('sha256', json_encode([(new AuditDataset())->articles(), (new AuditDataset())->media(), EvaluationScenario::POISON], JSON_THROW_ON_ERROR)),
            'identity' => ['subject' => 'demo-editor', 'permissions' => ['content.audit']], 'approvedTools' => BackendFactory::FIXTURE_TOOLS,
            'limits' => ['modelRequestsPerConversation' => EvaluationScenario::REQUEST_LIMIT, 'agentCallsPerConversation' => 60, 'phpAttemptsPerConversation' => 2, 'wallTimeMsPerExecution' => 15000, 'toolTimeMs' => 3000, 'toolCallsPerExecution' => 30],
            'runtime' => 'runsc', 'image' => BackendFactory::env('CODE_MODE_IMAGE'),
            'questions' => array_combine(EvaluationScenario::IDS, array_map(EvaluationScenario::questions(...), EvaluationScenario::IDS)),
            'review' => ['perTurn' => 1, 'instruction' => EvaluationScenario::REVIEW_PROMPT, 'answerSelection' => EvaluationScenario::ANSWER_SELECTION, 'toolsAvailable' => false, 'oracleFeedback' => false, 'costIncluded' => true, 'questionsUnchangedFrom' => 'symfony-evaluation-v2'],
            'scope' => 'Synthetic Symfony MCP fixture. Reference runs check execution only, with no model. Live comparisons include all planned repetitions and failures. Provider routing is automatic; no billing claim. Follow-ups keep one bounded session. Timeline spans nest and must not be summed across stages.'];
    }
}
