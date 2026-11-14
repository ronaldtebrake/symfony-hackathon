<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Provider;

use CodeMode\Provider\ToolBinding;
use CodeMode\Provider\ToolProviderInterface;
use CodeMode\Symfony\Ai\ApprovedToolbox;

/** Preserve native Symfony AI execution and authorization; map only explicitly approved tools. */
final readonly class SymfonyAiProvider implements ToolProviderInterface
{
    /** @param array<string, string> $methods PHP method alias => approved original tool name. */
    public function __construct(private string $namespace, private ApprovedToolbox $toolbox, private array $methods)
    {
    }

    public function name(): string
    {
        return $this->namespace;
    }

    public function source(): string
    {
        return 'symfony-ai';
    }

    public function tools(): iterable
    {
        $tools = [];
        foreach ($this->toolbox->tools() as $tool) {
            $tools[$tool->definition()->name] = $tool;
        }
        if ([] === $this->methods || \count(array_unique($this->methods)) !== \count($this->methods)) {
            throw new \InvalidArgumentException('Map each approved tool at most once.');
        }
        foreach ($this->methods as $alias => $name) {
            $tool = $tools[$name] ?? throw new \InvalidArgumentException('Provider alias names an unapproved Symfony tool.');
            yield new ToolBinding($alias, $tool->definition(), $tool->authorize(...), $tool->invoke(...));
        }
    }
}
