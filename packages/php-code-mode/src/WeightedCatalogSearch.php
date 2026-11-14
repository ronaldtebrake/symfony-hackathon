<?php

declare(strict_types=1);

namespace CodeMode;

/** Standalone fallback for consumers without Symfony AI. */
final class WeightedCatalogSearch implements CatalogSearchInterface
{
    public function search(string $query, array $tools): array
    {
        $query = strtolower($query);
        $terms = array_unique(preg_split('/[^a-z0-9]+/', $query, flags: \PREG_SPLIT_NO_EMPTY));
        $ranked = [];
        foreach ($tools as $definition) {
            $name = strtolower($definition->name);
            $description = strtolower($definition->description);
            $schema = strtolower(json_encode($definition->inputSchema, \JSON_THROW_ON_ERROR));
            if ($query === $name) {
                return [$definition->name];
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

        return array_map(static fn ($entry): string => $entry['definition']->name, $ranked);
    }
}
