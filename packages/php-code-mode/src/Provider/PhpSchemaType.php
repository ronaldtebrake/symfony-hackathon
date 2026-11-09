<?php

declare(strict_types=1);

namespace CodeMode\Provider;

/** Conservative PHPDoc projection. JSON Schema remains the complete contract. */
final class PhpSchemaType
{
    public function render(?\stdClass $schema, bool $arguments = false, int $depth = 0): string
    {
        if (null === $schema || $depth >= 4 || isset($schema->{'$ref'}) || isset($schema->allOf) || isset($schema->oneOf) || isset($schema->anyOf) || !is_string($schema->type ?? null)) { return 'mixed'; }
        if ('object' === $schema->type) {
            $props = (array) ($schema->properties ?? []);
            // Never put arbitrary schema keys or descriptions into PHP source/comments.
            if (count($props) > 16 || array_filter(array_keys($props), static fn ($k) => !preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', (string) $k))) { return $arguments ? 'array<string, mixed>' : 'object'; }
            // Open objects cannot honestly be represented as a closed shape.
            if (false !== ($schema->additionalProperties ?? true)) { return $arguments ? 'array<string, mixed>' : 'object'; }
            $fields = [];
            foreach ($props as $key => $property) {
                $fields[] = $key.(in_array($key, $schema->required ?? [], true) ? '' : '?').': '.$this->render($property instanceof \stdClass ? $property : null, false, $depth + 1);
            }
            $shape = ($arguments ? 'array{' : 'object{').implode(', ', $fields).'}';
            return strlen($shape) <= 2048 ? $shape : ($arguments ? 'array<string, mixed>' : 'object');
        }
        return match ($schema->type) {
            'string' => 'string', 'integer' => 'int', 'number' => 'int|float', 'boolean' => 'bool', 'null' => 'null',
            'array' => 'list<'.$this->render(($schema->items ?? null) instanceof \stdClass ? $schema->items : null, false, $depth + 1).'>',
            default => 'mixed',
        };
    }
}
