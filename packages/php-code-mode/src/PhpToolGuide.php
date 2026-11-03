<?php

declare(strict_types=1);

namespace CodeMode;

/** Bounded documentation only: never evaluated and never used for authorization. */
final class PhpToolGuide
{
    public function describe(ToolDefinition $tool): array
    {
        $arguments = [];
        foreach (array_slice($tool->inputSchema->required ?? [], 0, 16) as $name) {
            if (!is_string($name)) { continue; }
            $schema = $tool->inputSchema->properties->{$name} ?? new \stdClass();
            $arguments[] = var_export($name, true).' => '.$this->placeholder($schema);
        }
        $guide = [
            'example' => '$result = $tools->call('.var_export($tool->name, true).', ['.implode(', ', $arguments).']);',
            'arguments' => preg_replace('/\AstdClass\{/', 'array{', $this->shape($tool->inputSchema)),
            'returns' => null === $tool->outputSchema ? 'mixed (no output schema declared)' : $this->shape($tool->outputSchema),
            'notes' => 'Illustrative placeholders; pass arguments as an associative PHP array and obey inputSchema. Tool-result JSON objects become stdClass (->field); JSON lists become PHP arrays. Shapes are documentation, not PHP declarations or permission grants. Unknown/referenced schemas remain mixed; no external references are fetched.',
        ];
        if (strlen(json_encode($guide, JSON_THROW_ON_ERROR)) > 8192) {
            $guide['example'] = '$result = $tools->call('.var_export($tool->name, true).', $arguments);';
            $guide['arguments'] = 'mixed (see inputSchema; guide size limit reached)';
            $guide['returns'] = 'mixed (see outputSchema; guide size limit reached)';
        }
        return $guide;
    }

    private function placeholder(mixed $schema): string
    {
        if (!$schema instanceof \stdClass) { return 'null'; }
        return match ($schema->type ?? null) {
            'integer' => (string) max(0, (int) ($schema->minimum ?? 1)),
            'number' => '1.0', 'boolean' => 'false', 'array' => '[]', 'object' => '(object) []',
            'string' => "'replace_me'", default => 'null',
        };
    }

    private function shape(mixed $schema, int $depth = 0): string
    {
        if (!$schema instanceof \stdClass || $depth >= 4 || isset($schema->{'$ref'})) { return 'mixed'; }
        if (isset($schema->anyOf) || isset($schema->oneOf)) {
            return implode('|', array_unique(array_map(fn ($part) => $this->shape($part, $depth + 1), array_slice($schema->anyOf ?? $schema->oneOf, 0, 6))));
        }
        if (is_array($schema->type ?? null)) {
            return implode('|', array_map(function ($type) use ($schema, $depth) {
                $part = clone $schema;
                $part->type = $type;
                return $this->shape($part, $depth + 1);
            }, array_slice($schema->type, 0, 6)));
        }
        if ('object' === ($schema->type ?? null)) {
            $fields = [];
            foreach (array_slice((array) ($schema->properties ?? []), 0, 8, true) as $name => $property) {
                // JSON-encoded keys remain data even when a schema uses PHP punctuation.
                $fields[] = json_encode((string) $name, JSON_THROW_ON_ERROR).(in_array($name, $schema->required ?? [], true) ? '' : '?').': '.$this->shape($property, $depth + 1);
            }
            if (count((array) ($schema->properties ?? [])) > 8) { $fields[] = '…'; }
            return 'stdClass{'.implode(', ', $fields).'}';
        }
        return match ($schema->type ?? null) {
            'integer' => 'int', 'number' => 'int|float', 'boolean' => 'bool', 'string' => 'string', 'null' => 'null',
            'array' => 'list<'.$this->shape($schema->items ?? null, $depth + 1).'>', default => 'mixed',
        };
    }
}
