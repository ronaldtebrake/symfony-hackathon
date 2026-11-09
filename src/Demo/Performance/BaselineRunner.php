<?php

declare(strict_types=1);

namespace App\Demo\Performance;

use App\Demo\{BackendFactory, BudgetedPlatform, RunTrace};
use CodeMode\{ExecutionIdentity, ExecutionLimits, SandboxException};
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Bridge\HuggingFace\Factory;
use Symfony\AI\Platform\Message\{Message, MessageBag};
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Operator-only paired capture. Replays the exact public snapshot from the PHP conversation. */
final readonly class BaselineRunner
{
    public const PROTOCOL = 'performance-direct-v1';

    public function __construct(private HttpClientInterface $httpClient) {}

    public static function prompt(): string
    {
        return str_replace([
            'Discover the relevant tools with tools_search and tools_describe before executing PHP. ',
            'Use one PHP execution for each user turn; this conversation has only two execution attempts, including failures. Each execution permits ten tool calls. JSON objects returned by tools are stdClass; lists are arrays. Submit PHP statements without PHP tags, using $tools->call with the exact discovered name and arguments. ',
            'Return the complete PHP result verbatim as the final JSON',
        ], ['', 'Call the approved readers directly with their exact names and arguments. Each user turn permits ten tool calls, including failures. ', 'Return the complete result as the final JSON'], ConversationRunner::PROMPT_V1);
    }

    public function run(string $source, ?\Closure $progress = null): array
    {
        $code = json_decode($source, true, flags: JSON_THROW_ON_ERROR);
        if (!(new ConversationVerifier())->verify($code)['passed']) { throw new \LogicException('A verified Code Mode conversation is required.'); }
        if ('' === trim(BackendFactory::env('HUGGINGFACE_API_KEY'))) { throw new \LogicException('Set HUGGINGFACE_API_KEY in ignored .env.local first.'); }
        $data = new FrozenDataset($code['dataset']);
        $identity = new ExecutionIdentity($code['identity']['subject'], $code['identity']['capabilities']);
        $trace = new RunTrace(); $trace->captureDetails = true;
        $toolbox = new DirectToolbox($data, $trace, $identity);
        $report = ['version' => 1, 'kind' => 'performance-baseline', 'protocol' => self::PROTOCOL, 'mode' => 'live', 'status' => 'running',
            'createdAt' => gmdate(DATE_ATOM), 'sourceCaptureSha256' => hash('sha256', $source), 'datasetSha256' => $code['datasetSha256'],
            'provider' => $code['provider'], 'providerRouting' => $code['providerRouting'], 'model' => $code['model'],
            'generationSettings' => $code['generationSettings'], 'systemPrompt' => self::prompt(), 'questions' => $code['questions'],
            'identity' => $code['identity'], 'limits' => get_object_vars(new ExecutionLimits()) + ['turns' => 2, 'modelRequests' => ConversationRunner::REQUEST_LIMIT],
            'runtime' => 'supervised-native-readers', 'approvedTools' => $toolbox->definitions(),
            'entryTools' => array_map(static fn ($t) => ['type' => 'function', 'function' => ['name' => $t->getName(), 'description' => $t->getDescription(), 'parameters' => $t->getParameters()]], $toolbox->getTools()),
            'turns' => [], 'trace' => []];
        $start = hrtime(true); $progress?->__invoke($report);
        try {
            // Separate preflight calls are never counted as model work or inserted in its context.
            $probeTrace = new RunTrace(); $probeTrace->captureDetails = true;
            $probe = new DirectToolbox($data, $probeTrace, $identity); $probe->beginTurn(0);
            $calls = [['perf_profile', ['requestId' => 'profile-01']], ['perf_profile', ['requestId' => 'profile-04']], ['perf_logs', ['requestId' => 'profile-04']], ['unapproved_reader', []]];
            while (count($calls) < 11) { $calls[] = ['perf_requests', []]; }
            foreach ($calls as $i => [$name, $args]) { $probe->execute(new ToolCall('preflight-'.$i, $name, $args)); }
            $report['securityProof'] = ['calls' => $probeTrace->agentCalls, 'ledger' => $probeTrace->brokerCalls];
            if (!BaselineVerifier::security($report['securityProof'], $data)) { throw new \RuntimeException('Direct reader preflight failed.'); }
            $platform = Factory::createPlatform(BackendFactory::env('HUGGINGFACE_API_KEY'), httpClient: $this->httpClient->withOptions(['timeout' => 45, 'max_duration' => 60, 'max_redirects' => 0]));
            $agent = new Agent(new BudgetedPlatform($platform, $trace, ConversationRunner::REQUEST_LIMIT), $code['model'], name: 'symfony-performance-direct', toolbox: $toolbox, maxToolCalls: 12);
            $messages = new MessageBag(Message::forSystem(self::prompt()));
            foreach ($code['questions'] as $index => $question) {
                $toolbox->beginTurn($index);
                $turn = ['question' => $question, 'agentCallStart' => count($trace->agentCalls), 'requestStart' => $trace->modelRequests];
                $report['turns'][$index] = $turn; $progress?->__invoke($report);
                $answer = $agent->call($messages->with(Message::ofUser($question)), $code['generationSettings']);
                $messages = $answer->getMessages();
                $report['turns'][$index] = $turn + ['answer' => (string) $answer->getContent(), 'agentCallEnd' => count($trace->agentCalls), 'requestEnd' => $trace->modelRequests];
                $report['trace'] = self::snapshot($trace); $progress?->__invoke($report);
            }
        } catch (\Throwable $error) {
            $report['error'] = $error instanceof SandboxException ? $error->reason : 'performance_baseline_failed';
            $report['errorType'] = $error::class;
        }
        $report['trace'] = self::snapshot($trace); $report['completedAt'] = gmdate(DATE_ATOM);
        $report['elapsedMs'] = (hrtime(true) - $start) / 1_000_000;
        $report = json_decode(json_encode($report, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $report['verification'] = (new BaselineVerifier())->verify($report, $source);
        $report['status'] = $report['verification']['passed'] ? 'complete' : 'failed';
        $progress?->__invoke($report);
        return $report;
    }

    private static function snapshot(RunTrace $trace): array
    {
        $tokens = null;
        if ($trace->modelRequests > 0 && count($trace->providerResponses) === $trace->modelRequests && !in_array(null, array_column($trace->providerResponses, 'tokens'), true)) {
            foreach (['prompt', 'completion', 'total'] as $key) { $tokens[$key] = array_sum(array_column(array_column($trace->providerResponses, 'tokens'), $key)); }
        }
        return ['agentCalls' => $trace->agentCalls, 'brokerCalls' => $trace->brokerCalls, 'toolPayloadBytes' => $trace->toolPayloadBytes,
            'modelRequests' => $trace->modelRequests, 'providerTokens' => $tokens, 'providerResponses' => $trace->providerResponses, 'requestEvidence' => $trace->requestEvidence];
    }
}
