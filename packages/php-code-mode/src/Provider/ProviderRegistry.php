<?php

declare(strict_types=1);

namespace CodeMode\Provider;

use CodeMode\{Tool, ToolBroker, ToolCatalog, ToolDefinition};

/** Freeze one bounded catalog. Aliases do not grant access or replace per-call policy. */
final readonly class ProviderRegistry
{
    private ToolCatalog $catalog;
    private ToolBroker $broker;
    private array $origins;
    private PhpToolInterface $php;
    private array $handlers;

    /** @param iterable<ToolProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        $definitions = $handlers = $origins = $map = $seen = [];
        foreach ($providers as $provider) {
            $namespace = $provider->name();
            PhpToolInterface::assertIdentifier($namespace);
            if (isset($seen[strtolower($namespace)]) || count($seen) >= 32) {
                throw new \InvalidArgumentException('Duplicate provider namespace or provider limit exceeded.');
            }
            $seen[strtolower($namespace)] = true;
            $methods = [];
            foreach ($provider->tools() as $binding) {
                PhpToolInterface::assertIdentifier($binding->method);
                if (isset($methods[strtolower($binding->method)]) || count($definitions) >= 128) {
                    throw new \InvalidArgumentException('Duplicate provider method or catalog limit exceeded.');
                }
                $methods[strtolower($binding->method)] = true;
                $name = $namespace.'.'.$binding->method;
                $original = $binding->definition;
                // Clone schema data: subsequent provider changes cannot silently change this session's contract.
                $definition = new ToolDefinition($name, $original->description,
                    self::copy($original->inputSchema), null === $original->outputSchema ? null : self::copy($original->outputSchema));
                $definitions[] = $definition;
                $handlers[] = new Tool($name, self::copy($definition->inputSchema), $binding->handler->authorize, $binding->handler->invoke);
                $map[$namespace][$binding->method] = $name;
                $origins[$name] = ['provider' => $namespace, 'method' => $binding->method, 'source' => $provider->source(), 'upstreamTool' => $original->name];
            }
            if ([] === $methods) { throw new \InvalidArgumentException('A provider must expose at least one approved tool.'); }
        }
        if ([] === $definitions) { throw new \InvalidArgumentException('Register at least one tool provider.'); }
        $this->catalog = new ToolCatalog($definitions);
        $this->broker = new ToolBroker($handlers);
        $this->handlers = $handlers;
        $this->origins = $origins;
        $this->php = new PhpToolInterface($map, $this->catalog);
    }

    public function catalog(): ToolCatalog { return $this->catalog; }
    public function broker(): ToolBroker { return $this->broker; }
    /** @return list<Tool> Trusted host assembly only. */
    public function handlers(): array { return $this->handlers; }
    public function php(): PhpToolInterface { return $this->php; }
    /** Host-only provenance. Endpoint URLs and credentials are intentionally absent. */
    public function origins(): array { return $this->origins; }
    private static function copy(\stdClass $schema): \stdClass { return json_decode(json_encode($schema, JSON_THROW_ON_ERROR, 32), false, 32, JSON_THROW_ON_ERROR); }
}
