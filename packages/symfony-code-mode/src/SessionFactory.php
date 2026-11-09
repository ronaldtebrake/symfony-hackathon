<?php

declare(strict_types=1);

namespace CodeMode\Symfony;

use CodeMode\CodeModeSession;
use CodeMode\DiscoveryMode;
use CodeMode\DockerEngine;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\ExecutionProfile;
use CodeMode\GvisorSandbox;
use CodeMode\LocalUnsafeExecutor;
use CodeMode\Tool;
use CodeMode\ToolBroker;
use CodeMode\ToolCatalog;
use CodeMode\Symfony\Ai\ApprovedToolbox;
use CodeMode\Provider\{ProviderRegistry, ToolProviderInterface};

final readonly class SessionFactory
{
    /** @param list<ReadToolInterface> $tools @param list<ToolProviderInterface> $providers */
    public function __construct(private array $tools, private string $image = '', private DockerEngine $docker = new DockerEngine(), private ExecutionLimits $limits = new ExecutionLimits(), private ?ApprovedToolbox $aiToolbox = null, private ExecutionProfile $executor = ExecutionProfile::Gvisor, private DiscoveryMode $discovery = DiscoveryMode::Full, private array $providers = [])
    {
        if (ExecutionProfile::Unknown === $executor) {
            throw new \InvalidArgumentException('Select gvisor or explicitly opt in to local-unsafe.');
        }
    }

    public function profile(): ExecutionProfile { return $this->executor; }

    /** Each host-owned conversation gets its own discovery state and two-attempt budget. */
    public function create(ExecutionIdentity $identity, ?DiscoveryMode $discovery = null): CodeModeSession
    {
        $tools = $this->approvedTools();
        $providers = [] === $this->providers ? null : new ProviderRegistry($this->providers);
        $catalog = new ToolCatalog([...array_map(static fn (ReadToolInterface $tool) => $tool->definition(), $tools), ...($providers?->catalog()->all() ?? [])]);
        $brokerTools = [];
        foreach ($tools as $index => $tool) {
            $definition = $catalog->all()[$index];
            $brokerTools[] = new Tool($definition->name, $definition->inputSchema, $tool->authorize(...), $tool->invoke(...));
        }

        $executor = match ($this->executor) {
            ExecutionProfile::Gvisor => new GvisorSandbox($this->image, $this->docker),
            ExecutionProfile::LocalUnsafe => new LocalUnsafeExecutor(),
        };
        return new CodeModeSession($catalog, new ToolBroker([...$brokerTools, ...($providers?->handlers() ?? [])]), $executor, $identity, $this->limits, discovery: $discovery ?? $this->discovery, phpInterface: $providers?->php());
    }

    public function catalog(): ToolCatalog
    {
        return new ToolCatalog([...array_map(static fn (ReadToolInterface $tool) => $tool->definition(), $this->approvedTools()), ...([] === $this->providers ? [] : (new ProviderRegistry($this->providers))->catalog()->all())]);
    }

    private function approvedTools(): array
    {
        $tools = [...$this->tools, ...($this->aiToolbox?->tools() ?? [])];
        if ([] === $tools && [] === $this->providers) {
            throw new \LogicException('Configure at least one approved read tool.');
        }
        return $tools;
    }
}
