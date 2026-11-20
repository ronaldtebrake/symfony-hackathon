<?php

declare(strict_types=1);

namespace Adoption;

use CodeMode\Symfony\Http\SecurityIdentityProvider;
use CodeMode\Symfony\Security\VoterToolPolicy;
use CodeMode\Symfony\SessionFactory;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final readonly class SkillPresentation
{
    public function __construct(private SessionFactory $sessions, private SecurityIdentityProvider $identities, private VoterToolPolicy $visibility, private string $projectDir)
    {
    }

    /** The fixture supplies a prerelease builder. The example keeps the released SDK in its lockfile. */
    public function configure(object $builder): void
    {
        if (!method_exists($builder, 'addSkillsFromDirectory')) {
            throw new \LogicException('The Skills experiment requires the pinned PHP SDK draft.');
        }

        // Both the application allowlist and its discovery voter must admit the underlying tool.
        $visible = $this->sessions->catalog()->visibleTo($this->identities->identity(), $this->visibility);
        $directory = \in_array('publication_read', array_column($visible->all(), 'name'), true) ? '/skills' : '/var/empty-skills';
        // Both directories are prepared by the trusted fixture. The visible one contains only the reviewed SKILL.md.
        $builder->addSkillsFromDirectory($this->projectDir.$directory);
    }

    public function secureResponse(ResponseEvent $event): void
    {
        if ('/mcp' === $event->getRequest()->getPathInfo()) {
            $response = $event->getResponse();
            $response->headers->set('Cache-Control', 'private, no-store');
            if (!\in_array('authorization', array_map('strtolower', $response->getVary()), true)) {
                $response->setVary('Authorization', false);
            }
        }
    }
}
