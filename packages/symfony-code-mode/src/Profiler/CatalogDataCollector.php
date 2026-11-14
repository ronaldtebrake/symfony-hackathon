<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Profiler;

use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

final class CatalogDataCollector extends DataCollector
{
    public function __construct(private readonly CatalogTrace $trace) {}
    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void { $this->data = $this->trace->snapshot(); }
    public function getName(): string { return 'code_mode'; }
    public function getOperations(): array { return $this->data['operations'] ?? []; }
    public function getDropped(): int { return $this->data['dropped'] ?? 0; }
    public function reset(): void { $this->data = []; $this->trace->reset(); }
}
