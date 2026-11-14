<?php

declare(strict_types=1);

namespace Composition;

use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\IdentityProviderInterface;

final class LocalReader implements IdentityProviderInterface
{
    public function identity(): ExecutionIdentity
    {
        return new ExecutionIdentity('composition-reader', ['content.audit']);
    }
}
