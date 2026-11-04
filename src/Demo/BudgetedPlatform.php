<?php

declare(strict_types=1);

namespace App\Demo;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\{AssistantMessage, ToolCallMessage, UserMessage};
use Symfony\AI\Platform\FinishReason\FinishReason;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

/** Admission happens before invoke(), which may already start HTTP I/O before progress is emitted. */
final readonly class BudgetedPlatform implements PlatformInterface
{
    public function __construct(private PlatformInterface $inner, private RunTrace $trace, private int $limit) {}

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        if ($this->trace->modelRequests >= $this->limit) {
            throw new \RuntimeException('Provider request budget exhausted.');
        }
        ++$this->trace->modelRequests;
        $this->trace->record('model_request', 'Hugging Face inference request '.$this->trace->modelRequests);
        $index = $this->trace->modelRequests;
        if ($this->trace->captureDetails && $input instanceof MessageBag) {
            $tools = $users = $assistants = [];
            foreach ($input as $message) {
                if ($message instanceof ToolCallMessage) {
                    $text = $message->asText() ?? '';
                    $tools[] = ['bytes' => strlen($text), 'sha256' => hash('sha256', $text)];
                }
                if ($message instanceof UserMessage) { $users[] = hash('sha256', $message->asText() ?? ''); }
                if ($message instanceof AssistantMessage && null !== $message->asText()) { $assistants[] = hash('sha256', $message->asText()); }
            }
            // Digests bind questions, draft and tool evidence without persisting provider reasoning or credentials.
            $this->trace->requestEvidence[] = ['request' => $index, 'toolMessages' => $tools,
                'systemPromptSha256' => hash('sha256', (string) $input->getSystemMessage()?->getContent()),
                'userMessageSha256' => $users, 'assistantMessageSha256' => $assistants, 'toolsAvailable' => [] !== ($options['tools'] ?? [])];
        }
        $span = $this->trace->timeline?->begin('model', 'Model request '.$index, ['request' => $index]);
        try {
            $deferred = $this->inner->invoke($model, $input, $options);
        } catch (\Throwable $error) {
            $this->trace->timeline?->end($span, 'failed');
            throw $error;
        }
        $deferred->onConvert(function (ResultInterface $result) use ($span): ResultInterface {
            $this->trace->timeline?->end($span);
            return $result;
        });
        if ($this->trace->captureDetails) {
            $deferred->onConvert(function (ResultInterface $result) use ($index): ResultInterface {
                $usage = $result->getMetadata()->get('token_usage');
                $finish = $result->getMetadata()->get('finish_reason');
                $this->trace->providerResponses[] = ['request' => $index,
                    'answerSha256' => $result instanceof TextResult ? hash('sha256', $result->getContent()) : null,
                    'finishReason' => $finish instanceof FinishReason ? $finish->getCase()->value : null,
                    'tokens' => $usage instanceof TokenUsageInterface ? ['prompt' => $usage->getPromptTokens(), 'completion' => $usage->getCompletionTokens(), 'total' => $usage->getTotalTokens()] : null];
                return $result;
            });
        }
        return $deferred;
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->inner->getModelCatalog();
    }
}
