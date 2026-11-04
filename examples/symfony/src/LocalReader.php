<?php

declare(strict_types=1);

namespace Adoption;

use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\IdentityProviderInterface;

/** Local operator identity, not remote authentication. Never take identity from guest arguments. */
final class LocalReader implements IdentityProviderInterface
{
    public function identity(): ExecutionIdentity
    {
        return new ExecutionIdentity('adoption-local-reader', ['publication.read_published']);
    }
}
