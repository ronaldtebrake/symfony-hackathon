<?php

declare(strict_types=1);

namespace Adoption;

use CodeMode\Symfony\Http\SecurityIdentityProvider;
use CodeMode\Symfony\Security\VoterToolPolicy;
use CodeMode\Symfony\SessionFactory;
use Symfony\Component\HttpFoundation\JsonResponse;

/** Host-side context for the experiment. Reading it never marks an MCP contract discovered. */
final readonly class CapabilityHintController
{
    public function __construct(private SessionFactory $sessions, private SecurityIdentityProvider $identities, private VoterToolPolicy $visibility)
    {
    }

    public function __invoke(): JsonResponse
    {
        $visible = $this->sessions->catalog()->visibleTo($this->identities->identity(), $this->visibility);
        $hints = \in_array('publication_read', array_column($visible->all(), 'name'), true) ? ['You can look up published articles.'] : [];

        return new JsonResponse(['hints' => $hints], headers: ['Cache-Control' => 'private, no-store', 'Vary' => 'Authorization']);
    }
}
