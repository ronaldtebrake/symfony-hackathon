<?php

declare(strict_types=1);

namespace CodeMode;

/** Progressive discovery over a host-approved, bounded catalog. */
final class ToolCatalog
{
    /** @var array<string, ToolDefinition> */
    private array $definitions = [];

    /** @param iterable<ToolDefinition> $definitions */
    public function __construct(iterable $definitions)
    {
        foreach ($definitions as $definition) {
            if (isset($this->definitions[$definition->name]) || count($this->definitions) >= 128) {
                throw new \InvalidArgumentException('The catalog contains duplicates or exceeds its tool limit.');
            }
            $this->definitions[$definition->name] = $definition;
        }
    }

    /** Returns compact summaries, not full input schemas. @return list<array{name: string, description: string}> */
    public function search(string $query, int $limit = 8): array
    {
        if (strlen($query) > 256 || $limit < 1 || $limit > 16) {
            throw new \InvalidArgumentException('Search exceeds its supported bounds.');
        }
        $terms = array_unique(preg_split('/[^a-z0-9]+/', strtolower($query), flags: PREG_SPLIT_NO_EMPTY));
        $scores = [];
        foreach ($this->definitions as $name => $definition) {
            $text = strtolower($name.' '.$definition->description);
            $score = array_sum(array_map(static fn (string $term): int => str_contains($text, $term) ? 1 : 0, $terms));
            if ([] === $terms || $score > 0) {
                $scores[$name] = $score;
            }
        }
        arsort($scores, SORT_NUMERIC);
        return array_map(fn (string $name): array => ['name' => $name, 'description' => $this->definitions[$name]->description], array_slice(array_keys($scores), 0, $limit));
    }

    /** @param list<string> $names @return list<ToolDefinition> */
    public function describe(array $names): array
    {
        if (!array_is_list($names) || count($names) > 16) {
            throw new \InvalidArgumentException('Describe at most sixteen tool names.');
        }
        $result = [];
        foreach ($names as $name) {
            if (!is_string($name) || !isset($this->definitions[$name])) {
                throw new SandboxException('tool_denied', 'Tool is not in this execution catalog.');
            }
            $result[$name] = $this->definitions[$name];
        }
        return array_values($result);
    }

    /** @return list<ToolDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }
}
