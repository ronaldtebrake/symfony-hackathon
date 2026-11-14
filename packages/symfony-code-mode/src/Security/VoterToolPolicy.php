<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Security;

use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\Ai\ToolAuthorizationInterface;
use CodeMode\ToolVisibilityInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** HTTP policy: use the application's voters without accepting an identity from tool arguments. */
final readonly class VoterToolPolicy implements ToolAuthorizationInterface, ToolVisibilityInterface
{
    /** @param array<string, array{discover: string, execute: string, subject?: ?string}> $rules */
    public function __construct(private TokenStorageInterface $tokens, private AuthorizationCheckerInterface $authorization, private array $rules)
    {
        foreach ($rules as $name => $rule) {
            if (!is_string($name) || !is_array($rule) || !is_string($rule['discover'] ?? null) || '' === $rule['discover']
                || !is_string($rule['execute'] ?? null) || '' === $rule['execute']
                || (isset($rule['subject']) && (!is_string($rule['subject']) || '' === $rule['subject']))) {
                throw new \InvalidArgumentException('Voter rules require a tool name, discovery and execution attributes, and an optional argument name.');
            }
        }
    }

    public function isVisible(ExecutionIdentity $identity, string $tool): bool
    {
        $user = $this->tokens->getToken()?->getUser();
        return isset($this->rules[$tool]) && $user instanceof UserInterface
            && $user->getUserIdentifier() === $identity->subject
            && $this->authorization->isGranted('IS_AUTHENTICATED_FULLY')
            && $this->authorization->isGranted($this->rules[$tool]['discover']);
    }

    public function isGranted(ExecutionIdentity $identity, string $tool, \stdClass $arguments): bool
    {
        if (!$this->isVisible($identity, $tool)) { return false; }
        $rule = $this->rules[$tool];
        $subject = null;
        if (null !== ($argument = $rule['subject'] ?? null)) {
            if (!property_exists($arguments, $argument)) { return false; }
            $subject = $arguments->{$argument};
        }
        // The voter validates its subject; the broker still validates the complete input schema.
        return $this->authorization->isGranted($rule['execute'], $subject);
    }
}
