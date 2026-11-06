<?php

declare(strict_types=1);

namespace CodeMode;

/** Reuses the original input schema and PHP shape renderer; never builds a validation schema. */
final class CompactToolGuide
{
    public function describe(ToolDefinition $tool): array
    {
        $brief = ['name' => $tool->name, 'description' => $tool->description, 'requiresDescribe' => true];
        // Keep unusual contracts in the full-schema path. No reference resolution or code evaluation.
        if ($this->hasReference($tool->inputSchema) || (null !== $tool->outputSchema && !$this->hasRenderableShape($tool->outputSchema))) {
            return $brief;
        }
        $guide = (new PhpToolGuide())->describe($tool);
        if (str_contains($guide['returns'], 'guide size limit reached')) { return $brief; }
        $compact = ['name' => $tool->name, 'description' => $tool->description, 'requiresDescribe' => false,
            'inputSchema' => $tool->inputSchema, 'returns' => $guide['returns']];
        return strlen(json_encode($compact, JSON_THROW_ON_ERROR, 32)) <= 4096 ? $compact : $brief;
    }

    private function hasReference(mixed $value): bool
    {
        if (!is_array($value) && !$value instanceof \stdClass) { return false; }
        foreach ((array) $value as $key => $child) {
            if (in_array($key, ['$ref', '$dynamicRef', '$recursiveRef'], true) || $this->hasReference($child)) { return true; }
        }
        return false;
    }

    private function hasRenderableShape(mixed $schema, int $depth = 0): bool
    {
        if (!$schema instanceof \stdClass || $depth >= 4 || $this->hasReference($schema)) { return false; }
        if (isset($schema->allOf) || isset($schema->not) || isset($schema->if)) { return false; }
        $union = $schema->anyOf ?? $schema->oneOf ?? null;
        if (null !== $union) {
            if (!is_array($union) || [] === $union || count($union) > 6) { return false; }
            foreach ($union as $part) { if (!$this->hasRenderableShape($part, $depth + 1)) { return false; } }
            return true;
        }
        if (is_array($schema->type ?? null)) {
            if ([] === $schema->type || count($schema->type) > 6) { return false; }
            foreach ($schema->type as $type) {
                $part = clone $schema; $part->type = $type;
                if (!$this->hasRenderableShape($part, $depth + 1)) { return false; }
            }
            return true;
        }
        if ('object' === ($schema->type ?? null)) {
            if (isset($schema->patternProperties) || !($schema->properties ?? new \stdClass()) instanceof \stdClass
                || !is_array($schema->required ?? []) || count((array) ($schema->properties ?? [])) > 8) { return false; }
            foreach ((array) ($schema->properties ?? []) as $property) {
                if (!$this->hasRenderableShape($property, $depth + 1)) { return false; }
            }
            return true;
        }
        if ('array' === ($schema->type ?? null)) {
            return !isset($schema->prefixItems) && $this->hasRenderableShape($schema->items ?? null, $depth + 1);
        }
        return in_array($schema->type ?? null, ['integer', 'number', 'boolean', 'string', 'null'], true);
    }
}
