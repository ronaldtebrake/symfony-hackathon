<?php

declare(strict_types=1);

namespace CodeMode\Provider;

use CodeMode\ToolCatalog;
use CodeMode\ToolDefinition;

/** Generated documentation plus a fixed guest-only proxy. No schema text is evaluated on the host. */
final readonly class PhpToolInterface
{
    public function __construct(private array $map, private ToolCatalog $catalog)
    {
        if ([] === $map || \count($map) > 32) {
            throw new \InvalidArgumentException('Invalid provider map.');
        }
        $seen = [];
        $count = 0;
        $names = array_column($catalog->all(), 'name');
        foreach ($map as $provider => $methods) {
            self::assertIdentifier((string) $provider);
            if (isset($seen[strtolower($provider)]) || !\is_array($methods) || [] === $methods) {
                throw new \InvalidArgumentException('Invalid provider map.');
            }
            $seen[strtolower($provider)] = true;
            $aliases = [];
            foreach ($methods as $method => $name) {
                self::assertIdentifier((string) $method);
                if (isset($aliases[strtolower($method)]) || ++$count > 128 || $name !== $provider.'.'.$method || !\in_array($name, $names, true)) {
                    throw new \InvalidArgumentException('Invalid provider mapping.');
                }
                $aliases[strtolower($method)] = true;
            }
        }
    }

    public static function assertIdentifier(string $name): void
    {
        $reserved = ['call', 'class', 'interface', 'trait', 'enum', 'function', 'namespace', 'use', 'new', 'clone', 'return', 'throw', 'try', 'catch', 'finally', 'if', 'else', 'elseif', 'for', 'foreach', 'while', 'do', 'switch', 'case', 'default', 'match', 'break', 'continue', 'echo', 'print', 'eval', 'exit', 'die', 'isset', 'empty', 'unset', 'include', 'include_once', 'require', 'require_once', 'yield', 'fn', 'public', 'protected', 'private', 'static', 'abstract', 'final', 'readonly', 'const', 'var', 'extends', 'implements', 'instanceof', 'global', 'as', 'and', 'or', 'xor', 'self', 'parent', 'true', 'false', 'null', 'list', 'array', 'callable'];
        if (!preg_match('/\A[a-z][A-Za-z0-9_]{0,29}\z/D', $name) || \in_array(strtolower($name), $reserved, true)) {
            throw new \InvalidArgumentException('Provider and method aliases must be safe PHP identifiers of at most thirty characters.');
        }
    }

    public function describe(ToolDefinition $definition): array
    {
        foreach ($this->map as $provider => $methods) {
            foreach ($methods as $method => $name) {
                if ($name !== $definition->name) {
                    continue;
                }
                $type = new PhpSchemaType();

                return ['call' => '$tools->'.$provider.'->'.$method.'($arguments)',
                    'arguments' => $type->render($definition->inputSchema, true), 'returns' => $type->render($definition->outputSchema),
                    'notes' => 'PHPDoc guidance only. Full schemas and host policy remain authoritative. Constraints, normalization and dependent choices are not implied by these types. Objects in results use ->field; lists use array syntax.'];
            }
        }

        return [];
    }

    /** Editor/static-analysis stubs only. Never load these declarations into the host or runner. */
    public function stubs(): string
    {
        $out = "<?php\n\nnamespace CodeMode\\Generated;\n\n// Generated from approved schemas. Documentation only; not whole-program verification.\n";
        foreach ($this->map as $provider => $methods) {
            $out .= 'interface '.ucfirst($provider)."Tools\n{\n";
            foreach ($methods as $method => $name) {
                $guide = $this->describe($this->catalog->describe([$name])[0]);
                $out .= '    /** @param '.$guide['arguments']." \$arguments\n     * @return ".$guide['returns']." */\n    public function ".$method."(array \$arguments = []): mixed;\n";
            }
            $out .= "}\n\n";
        }
        $out .= "/**\n";
        foreach ($this->map as $provider => $_) {
            $out .= ' * @property-read '.ucfirst($provider).'Tools $'.$provider."\n";
        }

        return $out." */\ninterface Tools\n{\n    public function call(string \$name, array \$arguments = []): mixed;\n}\n";
    }

    /** The complete emitted program is subject to the existing executor's source-byte limit. */
    public function wrap(string $code, ?array $allowedTools = null): string
    {
        $map = $this->map;
        if (null !== $allowedTools) {
            foreach ($map as $namespace => $methods) {
                $map[$namespace] = array_filter($methods, static fn ($name) => \in_array($name, $allowedTools, true));
                if ([] === $map[$namespace]) {
                    unset($map[$namespace]);
                }
            }
        }
        $map = var_export($map, true);

        return <<<'PHP_GUEST'
return (static function ($tools) {
PHP_GUEST
            ."\n".$code."\n".<<<'PHP_GUEST'
})(new class($tools) {
    public function __construct(private object $broker) {}
    public function call(string $name, array $arguments = []): mixed { return $this->broker->call($name, $arguments); }
    public function __get(string $namespace): object {
PHP_GUEST
            ."\n        \$map = ".$map.";\n".<<<'PHP_GUEST'
        if (!isset($map[$namespace])) { throw new \RuntimeException('Unknown provider.'); }
        return new class($this->broker, $map[$namespace]) {
            public function __construct(private object $broker, private array $methods) {}
            public function __call(string $method, array $arguments): mixed {
                if (!isset($this->methods[$method]) || !array_is_list($arguments) || count($arguments) > 1 || (array_key_exists(0, $arguments) && !is_array($arguments[0]))) { throw new \RuntimeException('Invalid provider call.'); }
                return $this->broker->call($this->methods[$method], $arguments[0] ?? []);
            }
        };
    }
});
PHP_GUEST;
    }
}
