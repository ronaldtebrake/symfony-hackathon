<?php

declare(strict_types=1);

namespace CodeMode;

/** Both callbacks are trusted application code; they never come from the guest. */
final readonly class Tool
{
    public \Closure $authorize;
    public \Closure $invoke;

    /**
     * @param callable(ExecutionIdentity, \stdClass): bool  $authorize
     * @param callable(\stdClass, ExecutionIdentity): mixed $invoke    returns JSON data
     */
    public function __construct(
        public string $name,
        public \stdClass $inputSchema,
        callable $authorize,
        callable $invoke,
    ) {
        if (!preg_match('/\A[a-zA-Z][a-zA-Z0-9_.-]{0,63}\z/D', $name)) {
            throw new \InvalidArgumentException('Invalid tool name.');
        }
        $this->authorize = \Closure::fromCallable($authorize);
        $this->invoke = \Closure::fromCallable($invoke);
    }
}
