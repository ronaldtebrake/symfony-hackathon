<?php

declare(strict_types=1);

namespace CodeMode\Symfony;

use CodeMode\ExecutionIdentity;

interface IdentityProviderInterface
{
    /** Authenticate/select the operator on the trusted host. Never derive permissions from a prompt. */
    public function identity(): ExecutionIdentity;
}
