<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Provider;

use CodeMode\ExecutionLimits;
use CodeMode\Provider\ToolBinding;
use CodeMode\Provider\ToolProviderInterface;
use CodeMode\Symfony\Ai\ApprovedToolbox;
use CodeMode\Symfony\Ai\SelectToolFields;

/** Preserve native Symfony AI execution and authorization; map only explicitly approved tools. */
final readonly class SymfonyAiProvider implements ToolProviderInterface
{
    /**
     * @param array<string, string>                 $methods          PHP method alias => approved original tool name
     * @param array<string, non-empty-list<string>> $selectableFields Original tool name => selectable keys in its result rows
     */
    public function __construct(private string $namespace, private ApprovedToolbox $toolbox, private array $methods, private array $selectableFields = [], private ExecutionLimits $limits = new ExecutionLimits())
    {
        if ([] !== array_diff(array_keys($selectableFields), $methods)) {
            throw new \InvalidArgumentException('Selectable fields require an explicitly mapped reader.');
        }
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
            if (\array_key_exists($name, $this->selectableFields)) {
                $tool = new SelectToolFields($tool, $this->selectableFields[$name], $this->limits);
            }
            yield new ToolBinding($alias, $tool->definition(), $tool->authorize(...), $tool->invoke(...));
        }
    }
}
