<?php

declare(strict_types=1);

namespace App\Demo;

use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class DemoRunner
{
    public function __construct(
        private DemoFactory $factory,
        #[Autowire(service: 'ai.platform.huggingface')] private PlatformInterface $platform,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {}

    public function run(string $mode, string $approach, string $backend = 'fixture', ?DemoContext $context = null, ?\Closure $progress = null, ?DemoQuestion $question = null, ?int $maxModelRequests = null, string $protocol = AuditPrompt::COVERAGE_PROTOCOL): array
    {
        if (null !== $maxModelRequests && ($maxModelRequests < 1 || $maxModelRequests > 20)) {
            throw new \InvalidArgumentException('Choose a bounded model request budget between one and twenty.');
        }
        if (!in_array($mode, ['reference', 'live'], true) || ('reference' === $mode && 'fixture' !== $backend)) {
            throw new \InvalidArgumentException('Use reference with the fixture, or live with either backend.');
        }
        $question ??= DemoQuestion::preset();
        $question->requireSupportedRun($mode, $backend);
        $context ??= $this->factory->create($approach, $backend, discovery: \CodeMode\DiscoveryMode::Full);
        $trace = $context->trace;
        $started = hrtime(true);
        $tokens = null;
        $answer = '';
        if ('reference' === $mode) {
            $trace->record('reference', 'Reference program; no model request');
            if ('code' === $approach) {
                $search = $this->call($context, 'tools_search', ['query' => '']);
                $found = $search->tools;
                $ready = array_map(static fn ($tool) => $tool->name, array_filter($found, static fn ($tool) => false === ($tool->requiresDescribe ?? null)));
                $missing = array_values(array_diff(BackendFactory::FIXTURE_TOOLS, $ready));
                if ([] !== $missing) { $this->call($context, 'tools_describe', ['names' => $missing]); }
                $this->call($context, 'php_execute', ['code' => file_get_contents($this->projectDir.'/resources/demo/audit.php.txt')]);
            } else {
                $trace->report = $this->referenceDirect($context);
            }
            $answer = 'Reference program completed. No AI inference or provider tokens were used.';
        } else {
            $prompt = AuditPrompt::build($approach, $question, $protocol);
            $platform = new BudgetedPlatform($this->platform, $trace, $maxModelRequests ?? 21);
            $agent = new Agent($platform, BackendFactory::env('HUGGINGFACE_MODEL', 'openai/gpt-oss-120b'), name: 'audit-'.$approach, toolbox: $context->toolbox, maxToolCalls: 20);
            $execution = $agent->call(new MessageBag(Message::forSystem($prompt), Message::ofUser($question->text)), AuditPrompt::options($protocol));
            $execution->onProgress(static function (Progress $update) use ($trace, $progress): void {
                $progress?->__invoke($trace);
            });
            $answer = (string) $execution->getContent();
            $usage = $execution->getMetadata()->get('token_usage');
            if ($usage instanceof TokenUsageInterface) {
                $tokens = ['prompt' => $usage->getPromptTokens(), 'completion' => $usage->getCompletionTokens(), 'total' => $usage->getTotalTokens()];
            }
            if ('coverage-v7' === $protocol) {
                // The same unconditional review for both paths, before the oracle runs.
                $initial = $answer;
                $review = $agent->call($execution->getMessages()->with(Message::ofUser(AuditPrompt::review())), AuditPrompt::options($protocol));
                $answer = (string) $review->getContent();
                $usage = $review->getMetadata()->get('token_usage');
                if (null !== $tokens && $usage instanceof TokenUsageInterface) {
                    $tokens['prompt'] += $usage->getPromptTokens();
                    $tokens['completion'] += $usage->getCompletionTokens();
                    $tokens['total'] += $usage->getTotalTokens();
                } else { $tokens = null; }
                $trace->review = ['instruction' => AuditPrompt::review(), 'initialAnswer' => $initial, 'finalAnswer' => $answer];
            }
            if ('direct' === $approach) {
                $json = preg_replace('/\A```(?:json)?\s*([\s\S]*?)\s*```\z/', '$1', trim($answer));
                try {
                    $trace->report = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $trace->report = null;
                }
            }
        }
        $verification = (new AuditVerifier())->verify($trace->report, $backend, $trace->brokerCalls, $question);
        return [
            'questionId' => $question->id, 'task' => $question->text,
            'approach' => $approach, 'mode' => $mode, 'discoveryMode' => 'code' === $approach ? $context->session->discoveryMode()->value : null, 'model' => 'live' === $mode ? BackendFactory::env('HUGGINGFACE_MODEL', 'openai/gpt-oss-120b') : null,
            'durationMs' => (hrtime(true) - $started) / 1_000_000, 'modelRequests' => $trace->modelRequests,
            'agentToolCalls' => $trace->agentToolCalls, 'toolPayloadBytes' => $trace->toolPayloadBytes,
            'providerTokens' => $tokens, 'brokerCalls' => $trace->brokerCalls, 'programs' => $trace->programs,
            'executions' => $trace->executions, 'agentCalls' => $trace->agentCalls,
            'requestEvidence' => $trace->requestEvidence, 'providerResponses' => $trace->providerResponses, 'review' => $trace->review,
            'report' => $trace->report, 'verification' => $verification, 'events' => $trace->events, 'answer' => $answer,
        ];
    }

    private function call(DemoContext $context, string $name, array $arguments): mixed
    {
        $result = $context->toolbox->execute(new ToolCall('reference-'.($context->trace->agentToolCalls + 1), $context->toolbox->aliasFor($name), $arguments));
        $value = json_decode($result->getResult(), false, 32, JSON_THROW_ON_ERROR);
        if ($value instanceof \stdClass && isset($value->error)) {
            throw new \RuntimeException('The reference run failed: '.$value->error->reason);
        }
        return $value;
    }

    /** Trusted reference algorithm, using the identical batched MCP calls as generated PHP. */
    private function referenceDirect(DemoContext $context): array
    {
        $articles = [];
        $offset = 0;
        do {
            $page = $this->call($context, 'cms_list_articles', ['offset' => $offset, 'limit' => 12]);
            $ids = array_map(static fn ($item) => $item->id, $page->items);
            if ([] !== $ids) {
                array_push($articles, ...$this->call($context, 'cms_get_articles', ['ids' => $ids])->items);
            }
            $offset = $page->next_offset;
        } while (null !== $offset);
        $media = [];
        $ids = array_values(array_unique(array_map(static fn ($item) => $item->image_id, $articles)));
        foreach (array_chunk($ids, 12) as $batch) {
            foreach ($this->call($context, 'cms_get_media', ['ids' => $batch])->items as $image) {
                $media[$image->id] = $image;
            }
        }
        $findings = [];
        foreach ($articles as $article) {
            $issues = [];
            if ('' === trim($article->meta_description)) {
                $issues[] = 'missing_description';
            }
            if ('' === trim($media[$article->image_id]->alt)) {
                $issues[] = 'missing_alt';
            }
            if ([] !== $issues) {
                $findings[] = ['id' => $article->id, 'title' => $article->title, 'issues' => $issues];
            }
        }
        return ['audited' => count($articles), 'findings' => $findings];
    }
}
