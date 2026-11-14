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
            if (isset($this->definitions[$definition->name]) || \count($this->definitions) >= 128) {
                throw new \InvalidArgumentException('The catalog contains duplicates or exceeds its tool limit.');
            }
            $this->definitions[$definition->name] = $definition;
        }
    }

    /** Returns compact summaries, not full input schemas. @return list<array{name: string, description: string}> */
    public function search(string $query, int $limit = 8): array
    {
        if (\strlen($query) > 256 || $limit < 1 || $limit > 16) {
            throw new \InvalidArgumentException('Search exceeds its supported bounds.');
        }
        $terms = array_unique(preg_split('/[^a-z0-9]+/', strtolower($query), flags: \PREG_SPLIT_NO_EMPTY));
        $scores = [];
        foreach ($this->definitions as $name => $definition) {
            $text = strtolower($name.' '.$definition->description);
            $score = array_sum(array_map(static fn (string $term): int => str_contains($text, $term) ? 1 : 0, $terms));
            if ([] === $terms || $score > 0) {
                $scores[$name] = $score;
            }
        }
        arsort($scores, \SORT_NUMERIC);

        return array_map(fn (string $name): array => ['name' => $name, 'description' => $this->definitions[$name]->description], \array_slice(array_keys($scores), 0, $limit));
    }

    /** Bounded agent-facing discovery. Counts include only this host-approved catalog. */
    public function searchResult(string $query): array
    {
        if (\strlen($query) > 256) {
            throw new \InvalidArgumentException('Search exceeds its supported bounds.');
        }
        $query = strtolower(trim($query));
        $terms = array_unique(preg_split('/[^a-z0-9]+/', $query, flags: \PREG_SPLIT_NO_EMPTY));
        $matches = [];
        foreach ($this->definitions as $name => $definition) {
            if ($query === strtolower($name)) {
                $matches = [$definition];
                break;
            }
            $text = strtolower($name.' '.$definition->description);
            if ([] === array_filter($terms, static fn ($term) => !str_contains($text, $term))) {
                $matches[] = $definition;
            }
        }
        $total = \count($matches);
        $result = ['tools' => array_map(static fn ($tool) => ['name' => $tool->name, 'description' => $tool->description], \array_slice($matches, 0, 8)),
            'matched' => $total, 'returned' => min(8, $total), 'limit' => 8, 'truncated' => $total > 8];
        if ($total > 8) {
            $result['hint'] = 'More matching tools exist. If the needed capability is missing, search again with a specific action or field before saying it is unavailable. All query terms must match; an exact tool name selects one tool.';
        } elseif (0 === $total) {
            $result['hint'] = 'No tool matched all query terms. Try fewer terms or a synonym. This is not evidence that the capability is unavailable.';
        }

        return $result;
    }

    /** Full, bounded contracts for the separate two-tool catalog; never truncates a schema. */
    public function searchContracts(string $query): array
    {
        if (\strlen($query) > 256) {
            throw new \InvalidArgumentException('Search exceeds its supported bounds.');
        }
        $query = strtolower(trim($query));
        $terms = array_unique(preg_split('/[^a-z0-9]+/', $query, flags: \PREG_SPLIT_NO_EMPTY));
        $ranked = [];
        foreach ($this->definitions as $definition) {
            $name = strtolower($definition->name);
            $description = strtolower($definition->description);
            $schema = strtolower(json_encode($definition->inputSchema, \JSON_THROW_ON_ERROR));
            if ($query === $name) {
                $ranked = [['definition' => $definition, 'score' => \PHP_INT_MAX]];
                break;
            }
            $score = 0;
            foreach ($terms as $term) {
                $score += (str_contains($name, $term) ? 4 : 0) + (str_contains($description, $term) ? 2 : 0) + (str_contains($schema, $term) ? 1 : 0);
            }
            if ([] === $terms || $score > 0) {
                $ranked[] = ['definition' => $definition, 'score' => $score];
            }
        }
        // PHP's stable sort preserves the host catalog order for equal scores.
        usort($ranked, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $result = ['tools' => [], 'matched' => \count($ranked), 'returned' => 0, 'limit' => 8, 'truncated' => \count($ranked) > 8];
        foreach (\array_slice($ranked, 0, 8) as $entry) {
            $candidate = $result;
            $candidate['tools'][] = $entry['definition']->jsonSerialize();
            ++$candidate['returned'];
            // Reserve room for bounded refinement guidance. Never return partial schemas.
            if (\strlen(json_encode($candidate, \JSON_THROW_ON_ERROR, 32)) > 32000) {
                if ([] === $result['tools']) {
                    throw new SandboxException('discovery_limit', 'The contract exceeds the disclosure limit.');
                }
                $result['truncated'] = true;
                break;
            }
            $result = $candidate;
        }
        if ($result['truncated']) {
            $result['hint'] = 'More contracts match. Narrow the query by tool name, action or input field. An exact name selects one tool.';
        } elseif ([] === $result['tools']) {
            $result['hint'] = 'No approved contract matched. Try another name, action or input field.';
        }

        return $result;
    }

    /** @param list<string> $names @return list<ToolDefinition> */
    public function describe(array $names): array
    {
        if (!array_is_list($names) || \count($names) > 16) {
            throw new \InvalidArgumentException('Describe at most sixteen tool names.');
        }
        $result = [];
        foreach ($names as $name) {
            if (!\is_string($name) || !isset($this->definitions[$name])) {
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

    /** Filter before ranking/counting/limiting. Never cache a principal's visibility here. */
    public function visibleTo(ExecutionIdentity $identity, ?ToolVisibilityInterface $policy): self
    {
        if (null === $policy) {
            return $this;
        }

        return new self(array_filter($this->all(), static function (ToolDefinition $tool) use ($identity, $policy): bool {
            try {
                return $policy->isVisible($identity, $tool->name);
            } catch (\Throwable) {
                return false;
            }
        }));
    }
}
