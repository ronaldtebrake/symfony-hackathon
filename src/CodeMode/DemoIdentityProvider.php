<?php

declare(strict_types=1);

namespace App\CodeMode;

use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\IdentityProviderInterface;

/** Local CLI demonstration identity, selected by the operator; not remote authentication. */
final class DemoIdentityProvider implements IdentityProviderInterface
{
    public function identity(): ExecutionIdentity
    {
        return new ExecutionIdentity('local-demo-reader', ['articles.read_published']);
    }
}
