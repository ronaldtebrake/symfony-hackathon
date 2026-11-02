<?php

declare(strict_types=1);

namespace CodeMode\Symfony;

use CodeMode\CodeModeSession;
use CodeMode\DockerEngine;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\GvisorSandbox;
use CodeMode\Tool;
use CodeMode\ToolBroker;
use CodeMode\ToolCatalog;

final readonly class SessionFactory
{
    /** @param list<ReadToolInterface> $tools */
    public function __construct(private array $tools, private string $image, private DockerEngine $docker, private ExecutionLimits $limits = new ExecutionLimits()) {}

    /** Each host-owned conversation gets its own discovery state and two-attempt budget. */
    public function create(ExecutionIdentity $identity): CodeModeSession
    {
        $catalog = $this->catalog();
        $brokerTools = [];
        foreach ($this->tools as $index => $tool) {
            $definition = $catalog->all()[$index];
            $brokerTools[] = new Tool($definition->name, $definition->inputSchema, $tool->authorize(...), $tool->invoke(...));
        }

        return new CodeModeSession($catalog, new ToolBroker($brokerTools), new GvisorSandbox($this->image, $this->docker), $identity, $this->limits);
    }

    public function catalog(): ToolCatalog
    {
        if ([] === $this->tools) {
            throw new \LogicException('Configure at least one approved read tool.');
        }
        return new ToolCatalog(array_map(static fn (ReadToolInterface $tool) => $tool->definition(), $this->tools));
    }
}
