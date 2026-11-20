<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Ai;

use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\Symfony\ReadToolInterface;
use CodeMode\ToolDefinition;

/** Adds a literal field list to an approved reader returning an object with an items array. */
final readonly class SelectToolFields implements ReadToolInterface
{
    private ToolDefinition $definition;

    /** @param non-empty-list<string> $fields Selectable publicly documented item keys. */
    public function __construct(private ReadToolInterface $reader, private array $fields, private ExecutionLimits $limits = new ExecutionLimits())
    {
        if (!array_is_list($fields) || [] === $fields || \count($fields) > 64) {
            throw new \InvalidArgumentException('Configure between one and 64 selectable fields.');
        }
        foreach ($fields as $field) {
            if (!\is_string($field) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $field)) {
                throw new \InvalidArgumentException('Selectable fields must be top-level field names.');
            }
        }
        if (\count($fields) !== \count(array_unique($fields))) {
            throw new \InvalidArgumentException('Selectable field names must be unique.');
        }

        $original = $reader->definition();
        $input = clone $original->inputSchema;
        // A flat argument map lets the added parameter leave all original constraints intact.
        if ('object' !== ($input->type ?? null) || !(($input->properties ?? null) instanceof \stdClass)
            || property_exists($input->properties, 'fields')
            || [] !== array_diff(array_keys(get_object_vars($input)), ['type', 'properties', 'required', 'additionalProperties', 'title', 'description', '$schema'])) {
            throw new \InvalidArgumentException('Field selection requires a flat object argument schema with an available fields parameter.');
        }
        $input->properties = clone $input->properties;
        $input->properties->fields = (object) [
            'type' => 'array', 'description' => 'Return only these keys in each items row. Omit fields to return the full rows. Pagination is preserved.',
            'minItems' => 1, 'maxItems' => \count($fields), 'uniqueItems' => true,
            'items' => (object) ['type' => 'string', 'enum' => $fields],
        ];

        // The underlying reader owns its full output contract. Selected rows may omit required keys.
        $output = (object) ['type' => 'object', 'required' => ['items'], 'properties' => (object) [
            'items' => (object) ['type' => 'array', 'items' => (object) ['type' => 'object']],
        ]];
        $this->definition = new ToolDefinition($original->name, $original->description, $input, $output);
    }

    public function definition(): ToolDefinition
    {
        return $this->definition;
    }

    public function authorize(ExecutionIdentity $identity, \stdClass $arguments): bool
    {
        return $this->reader->authorize($identity, $this->readerArguments($arguments));
    }

    public function invoke(\stdClass $arguments, ExecutionIdentity $identity): mixed
    {
        $selected = null;
        if (property_exists($arguments, 'fields')) {
            $fields = $arguments->fields;
            if (!\is_array($fields) || !array_is_list($fields) || [] === $fields || \count($fields) > \count($this->fields)) {
                throw new \InvalidArgumentException('Select a nonempty list of permitted fields.');
            }
            $selected = [];
            foreach ($fields as $field) {
                if (!\in_array($field, $this->fields, true) || isset($selected[$field])) {
                    throw new \InvalidArgumentException('Select each permitted field at most once.');
                }
                $selected[$field] = true;
            }
        }

        $value = $this->reader->invoke($this->readerArguments($arguments), $identity);
        $raw = json_encode($value, \JSON_THROW_ON_ERROR, 32);
        if (\strlen($raw) > $this->limits->maxToolResultBytes) {
            throw new \LengthException('The full reader result exceeded the per-tool byte limit.');
        }
        $envelope = json_decode($raw, false, 32, \JSON_THROW_ON_ERROR);
        if (!$envelope instanceof \stdClass || !\is_array($envelope->items ?? null)) {
            throw new \UnexpectedValueException('The reader must return an object with an items list.');
        }
        foreach ($envelope->items as $index => $row) {
            if (!$row instanceof \stdClass) {
                throw new \UnexpectedValueException('Each reader item must be an object.');
            }
            if (null !== $selected) {
                $envelope->items[$index] = (object) array_intersect_key(get_object_vars($row), $selected);
            }
        }

        return $envelope;
    }

    private function readerArguments(\stdClass $arguments): \stdClass
    {
        $original = clone $arguments;
        unset($original->fields);

        return $original;
    }
}
