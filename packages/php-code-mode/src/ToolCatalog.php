<?php

declare(strict_types=1);

namespace CodeMode;

/** Progressive discovery over a host-approved, bounded catalog. */
final class ToolCatalog
{
    public const SEARCH_PROTOCOL = 'bounded-refinement-v1';
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

    /** Bounded agent-facing discovery. Counts include only this host-approved catalog. */
    public function searchResult(string $query): array
    {
        if (strlen($query) > 256) { throw new \InvalidArgumentException('Search exceeds its supported bounds.'); }
        $query = strtolower(trim($query));
        $terms = array_unique(preg_split('/[^a-z0-9]+/', $query, flags: PREG_SPLIT_NO_EMPTY));
        $matches = [];
        foreach ($this->definitions as $name => $definition) {
            if ($query === strtolower($name)) { $matches = [$definition]; break; }
            $text = strtolower($name.' '.$definition->description);
            if ([] === array_filter($terms, static fn ($term) => !str_contains($text, $term))) { $matches[] = $definition; }
        }
        $total = count($matches);
        $result = ['tools' => array_map(static fn ($tool) => ['name' => $tool->name, 'description' => $tool->description], array_slice($matches, 0, 8)),
            'matched' => $total, 'returned' => min(8, $total), 'limit' => 8, 'truncated' => $total > 8];
        if ($total > 8) {
            $result['hint'] = 'More matching tools exist. If the needed capability is missing, search again with a specific action or field before saying it is unavailable. All query terms must match; an exact tool name selects one tool.';
        } elseif (0 === $total) {
            $result['hint'] = 'No tool matched all query terms. Try fewer terms or a synonym. This is not evidence that the capability is unavailable.';
        }
        return $result;
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
