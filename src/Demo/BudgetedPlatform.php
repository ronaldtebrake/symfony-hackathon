<?php

declare(strict_types=1);

namespace App\Demo;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\FinishReason\FinishReason;
use Symfony\AI\Platform\Result\ResultInterface;
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
            $tools = [];
            foreach ($input as $message) {
                if ($message instanceof ToolCallMessage) {
                    $text = $message->asText() ?? '';
                    $tools[] = ['bytes' => strlen($text), 'sha256' => hash('sha256', $text)];
                }
            }
            // Only tool-message digests; no headers, credentials, assistant reasoning or transport debug.
            $this->trace->requestEvidence[] = ['request' => $index, 'toolMessages' => $tools];
        }
        $deferred = $this->inner->invoke($model, $input, $options);
        if ($this->trace->captureDetails) {
            $deferred->onConvert(function (ResultInterface $result) use ($index): ResultInterface {
                $usage = $result->getMetadata()->get('token_usage');
                $finish = $result->getMetadata()->get('finish_reason');
                $this->trace->providerResponses[] = ['request' => $index,
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
