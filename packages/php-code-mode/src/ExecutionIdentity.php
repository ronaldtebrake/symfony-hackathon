<?php

declare(strict_types=1);

namespace CodeMode;

/** Created by the trusted application, never from a sandbox message. */
final readonly class ExecutionIdentity
{
    /** @param list<string> $permissions */
    public function __construct(public string $subject, public array $permissions = [])
    {
        if ('' === $subject) {
            throw new \InvalidArgumentException('A host-authenticated subject is required.');
        }
    }

    public function allows(string $permission): bool
    {
        return \in_array($permission, $this->permissions, true);
    }
}
