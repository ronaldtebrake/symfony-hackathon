<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Http;

use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\IdentityProviderInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

/** Permissions come from Symfony Security, never headers, prompts or MCP arguments. */
final readonly class SecurityIdentityProvider implements IdentityProviderInterface
{
    /** @param array<string, string> $permissions Code Mode permission => Symfony security attribute. */
    public function __construct(private TokenStorageInterface $tokens, private AuthorizationCheckerInterface $authorization, private array $permissions)
    {
    }

    public function identity(): ExecutionIdentity
    {
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof UserInterface || !$this->authorization->isGranted('IS_AUTHENTICATED_FULLY')) {
            throw new AccessDeniedException('Authentication is required.');
        }
        $granted = [];
        foreach ($this->permissions as $permission => $attribute) {
            if ($this->authorization->isGranted($attribute)) {
                $granted[] = $permission;
            }
        }

        return new ExecutionIdentity($user->getUserIdentifier(), $granted);
    }
}
