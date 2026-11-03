<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Ai;

use CodeMode\Symfony\ReadToolInterface;
use CodeMode\ToolDefinition;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;

/** Adapts only explicitly approved read tools. Attributes and discovery never grant access. */
final readonly class ApprovedToolbox
{
    /** @param list<string> $names */
    public function __construct(private ToolboxInterface $toolbox, private array $names, private ToolAuthorizationInterface $authorization)
    {
        if (!array_is_list($names) || [] === $names || count($names) > 128 || count(array_unique($names)) !== count($names)) {
            throw new \InvalidArgumentException('Approve a non-empty, unique list of at most 128 tool names.');
        }
    }

    /** @return list<ReadToolInterface> */
    public function tools(): array
    {
        $selected = [];
        foreach ($this->toolbox->getTools() as $tool) {
            $name = $tool->getName();
            if (!in_array($name, $this->names, true)) {
                continue;
            }
            if (isset($selected[$name]) || !$tool->isReadOnly()) {
                throw new \LogicException('Approved tools must have unique names and an explicit read-only hint. Review their handlers separately.');
            }
            $input = $tool->getParameters() ?? ['type' => 'object', 'properties' => new \stdClass()];
            // Do not silently accept arguments absent from the reflected contract.
            $input['additionalProperties'] = false;
            if ([] === ($input['properties'] ?? null)) { $input['properties'] = new \stdClass(); }
            $output = $tool->getMetadataValue('code_mode.output_schema');
            $definition = new ToolDefinition($name, $tool->getDescription(), $this->schema($input), null === $output ? null : $this->schema($output));
            $selected[$name] = new ApprovedTool($this->toolbox, $definition, $this->authorization);
        }
        if (count($selected) !== count($this->names)) {
            throw new \LogicException('An explicitly approved Symfony AI tool is missing.');
        }
        return array_map(static fn (string $name) => $selected[$name], $this->names);
    }

    private function schema(mixed $value): \stdClass
    {
        $schema = json_decode(json_encode($value, JSON_THROW_ON_ERROR, 32), false, 32, JSON_THROW_ON_ERROR);
        if (!$schema instanceof \stdClass) {
            throw new \InvalidArgumentException('Tool schemas must be JSON objects.');
        }
        return $schema;
    }
}
