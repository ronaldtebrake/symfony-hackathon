<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Provider;

use CodeMode\Provider\{ToolBinding, ToolProviderInterface};
use CodeMode\Symfony\ReadToolInterface;

/** Compatibility adapter for existing explicitly configured read services. */
final readonly class ReadToolsProvider implements ToolProviderInterface
{
    /** @param array<string, ReadToolInterface> $tools PHP method alias => trusted service. */
    public function __construct(private string $namespace, private array $tools) {}
    public function name(): string { return $this->namespace; }
    public function source(): string { return 'read-service'; }
    public function tools(): iterable
    {
        foreach ($this->tools as $method => $tool) { yield new ToolBinding($method, $tool->definition(), $tool->authorize(...), $tool->invoke(...)); }
    }
}
