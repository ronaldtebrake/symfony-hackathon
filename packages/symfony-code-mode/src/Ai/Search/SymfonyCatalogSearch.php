<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Ai\Search;

use CodeMode\CatalogSearchInterface;
use CodeMode\SandboxException;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/** Adapts Symfony AI's pure search contract; it never receives an executable toolbox. */
final readonly class SymfonyCatalogSearch implements CatalogSearchInterface
{
    /** @var \Closure(string, list<Tool>, int): list<Tool> */
    private \Closure $rank;
    private string $component;

    /** @param callable(string, list<Tool>, int): list<Tool>|null $search An upstream search object's first-class search callable. */
    public function __construct(?callable $search = null)
    {
        if (null === $search) {
            $class = 'Symfony\\AI\\Agent\\Toolbox\\ToolSearch\\Bm25ToolSearch';
            $interface = 'Symfony\\AI\\Agent\\Toolbox\\ToolSearch\\ToolSearchInterface';
            if (class_exists($class)) {
                if (!interface_exists($interface) || !is_subclass_of($class, $interface)) {
                    throw new \LogicException('The installed Symfony tool search does not implement the supported search contract.');
                }
                $component = new $class();
            } else {
                $class = Upstream\Bm25ToolSearch::class;
                $component = new Upstream\Bm25ToolSearch();
            }
            $search = [$component, 'search'];
            if (!\is_callable($search)) {
                throw new \LogicException('The tool search component is not callable.');
            }
            $this->component = $class;
        } else {
            $this->component = 'custom';
        }
        $this->rank = \Closure::fromCallable($search);
    }

    public function component(): string
    {
        return $this->component;
    }

    public function search(string $query, array $tools): array
    {
        $projections = [];
        foreach ($tools as $definition) {
            // The reference is inert: no toolbox or handler is passed to this component.
            $projections[$definition->name] = new Tool(new ExecutionReference(self::class, 'search'), $definition->name,
                $definition->description, json_decode(json_encode($definition->inputSchema, \JSON_THROW_ON_ERROR, 32), true, 32, \JSON_THROW_ON_ERROR));
        }
        // Ask for all bounded matches; the catalog owns counts, truncation and disclosure limits.
        $found = ($this->rank)($query, array_values($projections), \count($projections));
        if (!\is_array($found) || !array_is_list($found) || \count($found) > \count($projections)) {
            throw new SandboxException('discovery_failed', 'Search returned an invalid result.');
        }
        $names = [];
        foreach ($found as $tool) {
            if (!$tool instanceof Tool || ($projections[$tool->getName()] ?? null) !== $tool || \in_array($tool->getName(), $names, true)) {
                throw new SandboxException('discovery_failed', 'Search returned an invalid result.');
            }
            $names[] = $tool->getName();
        }

        return $names;
    }
}
