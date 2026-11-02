<?php

declare(strict_types=1);

namespace App\Demo;

use Symfony\AI\Platform\Model;
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
        return $this->inner->invoke($model, $input, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->inner->getModelCatalog();
    }
}
