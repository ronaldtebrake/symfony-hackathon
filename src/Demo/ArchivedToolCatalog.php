<?php

declare(strict_types=1);

namespace App\Demo;

use CodeMode\ToolDefinition;

/** Pure replay of archived search experiments. Not exposed by MCP or Symfony AI. */
final class ArchivedToolCatalog
{
    public const SEARCH_PROTOCOL = 'schema-fields-v2';
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

    /** Bounded agent-facing discovery. Counts include only this host-approved catalog. */
    public function searchResult(string $query, bool $schemaFields = true): array
    {
        if (strlen($query) > 256) { throw new \InvalidArgumentException('Search exceeds its supported bounds.'); }
        $query = strtolower(trim($query));
        $terms = array_unique(preg_split('/[^a-z0-9]+/', $query, flags: PREG_SPLIT_NO_EMPTY));
        $fields = []; $knownFields = [];
        if ($schemaFields) {
            foreach ($this->definitions as $name => $definition) {
                $fields[$name] = $this->fieldTerms($definition->inputSchema) + $this->fieldTerms($definition->outputSchema);
                $knownFields += $fields[$name];
            }
        }
        $matches = [];
        foreach ($this->definitions as $name => $definition) {
            if ($query === strtolower($name)) { $matches = [$definition]; break; }
            $text = strtolower($name.' '.$definition->description);
            if ([] === array_filter($terms, static fn ($term) => isset($knownFields[$term])
                ? !isset($fields[$name][$term]) && !str_contains(strtolower($name), $term)
                : !str_contains($text, $term))) { $matches[] = $definition; }
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

    /** Index declared property names only; never resolve references or inspect tool data. */
    private function fieldTerms(mixed $schema): array
    {
        if (!$schema instanceof \stdClass) { return []; }
        $terms = [];
        foreach ((array) ($schema->properties ?? []) as $name => $property) {
            foreach (preg_split('/[^a-z0-9]+/', strtolower((string) $name), flags: PREG_SPLIT_NO_EMPTY) as $term) { $terms[$term] = true; }
            $terms += $this->fieldTerms($property);
        }
        $terms += $this->fieldTerms($schema->items ?? null);
        foreach (['anyOf', 'oneOf', 'allOf'] as $union) {
            foreach (is_array($schema->$union ?? null) ? $schema->$union : [] as $part) { $terms += $this->fieldTerms($part); }
        }
        return $terms;
    }

}
