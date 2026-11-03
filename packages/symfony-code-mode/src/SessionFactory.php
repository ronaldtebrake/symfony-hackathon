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
use CodeMode\Symfony\Ai\ApprovedToolbox;

final readonly class SessionFactory
{
    /** @param list<ReadToolInterface> $tools */
    public function __construct(private array $tools, private string $image, private DockerEngine $docker, private ExecutionLimits $limits = new ExecutionLimits(), private ?ApprovedToolbox $aiToolbox = null) {}

    /** Each host-owned conversation gets its own discovery state and two-attempt budget. */
    public function create(ExecutionIdentity $identity): CodeModeSession
    {
        $tools = $this->approvedTools();
        $catalog = new ToolCatalog(array_map(static fn (ReadToolInterface $tool) => $tool->definition(), $tools));
        $brokerTools = [];
        foreach ($tools as $index => $tool) {
            $definition = $catalog->all()[$index];
            $brokerTools[] = new Tool($definition->name, $definition->inputSchema, $tool->authorize(...), $tool->invoke(...));
        }

        return new CodeModeSession($catalog, new ToolBroker($brokerTools), new GvisorSandbox($this->image, $this->docker), $identity, $this->limits);
    }

    public function catalog(): ToolCatalog
    {
        return new ToolCatalog(array_map(static fn (ReadToolInterface $tool) => $tool->definition(), $this->approvedTools()));
    }

    private function approvedTools(): array
    {
        $tools = [...$this->tools, ...($this->aiToolbox?->tools() ?? [])];
        if ([] === $tools) {
            throw new \LogicException('Configure at least one approved read tool.');
        }
        return $tools;
    }
}
