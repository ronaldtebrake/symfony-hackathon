<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Profiler;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

final class CatalogDataCollector extends DataCollector
{
    public function __construct(private readonly CatalogTrace $trace, private readonly array $reviewedExchange = [])
    {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = $this->trace->snapshot();
        $this->data['exchange'] = $this->reviewedExchange;
    }

    public function getName(): string
    {
        return 'code_mode';
    }

    public function getOperations(): array
    {
        return $this->data['operations'] ?? [];
    }

    public function getExchange(): array
    {
        return $this->data['exchange'] ?? [];
    }

    public function getDropped(): int
    {
        return $this->data['dropped'] ?? 0;
    }

    public function reset(): void
    {
        $this->data = [];
        $this->trace->reset();
    }
}
