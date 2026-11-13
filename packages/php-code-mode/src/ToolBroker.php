<?php

declare(strict_types=1);

namespace CodeMode;

use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;

final class ToolBroker
{
    /** @var array<string, Tool> */
    private array $tools = [];
    private Validator $validator;

    /** @param iterable<Tool> $tools */
    public function __construct(iterable $tools, private readonly ?ToolVisibilityInterface $visibility = null)
    {
        // No external schema resolver: validation cannot fetch URLs or local files.
        $this->validator = new Validator(new SchemaLoader());
        foreach ($tools as $tool) {
            if (isset($this->tools[$tool->name])) {
                throw new \InvalidArgumentException('Duplicate tool name.');
            }
            $this->tools[$tool->name] = $tool;
        }
    }

    /** Preserve any existing policy when a session supplies an additional one. */
    public function withVisibility(?ToolVisibilityInterface $visibility): self
    {
        if (null === $visibility) { return $this; }
        $existing = $this->visibility;
        $combined = null === $existing ? $visibility : new class($existing, $visibility) implements ToolVisibilityInterface {
            public function __construct(private readonly ToolVisibilityInterface $first, private readonly ToolVisibilityInterface $second) {}
            public function isVisible(ExecutionIdentity $identity, string $tool): bool
            {
                return $this->first->isVisible($identity, $tool) && $this->second->isVisible($identity, $tool);
            }
        };
        return new self($this->tools, $combined);
    }

    /** @param list<string> $allowedTools */
    public function call(string $name, \stdClass $arguments, ExecutionIdentity $identity, array $allowedTools): mixed
    {
        $tool = $this->tools[$name] ?? null;
        if (null === $tool || !in_array($name, $allowedTools, true)) {
            throw new SandboxException('tool_denied', 'Tool is not authorized.');
        }
        try {
            if (null !== $this->visibility && !$this->visibility->isVisible($identity, $name)) {
                throw new SandboxException('tool_denied', 'Tool is not authorized.');
            }
            if (true !== ($tool->authorize)($identity, $arguments)) {
                throw new SandboxException('tool_denied', 'Tool is not authorized.');
            }
            if (!$this->validator->validate($arguments, $tool->inputSchema)->isValid()) {
                throw new SandboxException('invalid_arguments', 'Tool arguments do not match the input schema.');
            }
        } catch (SandboxException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new SandboxException('tool_denied', 'Tool authorization or validation failed.');
        }
        try {
            return ($tool->invoke)($arguments, $identity);
        } catch (\Throwable) {
            // Exception messages can contain credentials, SQL, or private paths.
            throw new SandboxException('tool_failed', 'Tool execution failed.');
        }
    }
}
