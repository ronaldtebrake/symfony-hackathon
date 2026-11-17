<?php

declare(strict_types=1);

namespace App\Demo\AuditMatrix;

use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\Ai\ToolAuthorizationInterface;
use CodeMode\ToolVisibilityInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/** Disposable benchmark authentication and read policy. Never used by the recording app. */
final readonly class Access implements AccessTokenHandlerInterface, ToolAuthorizationInterface, ToolVisibilityInterface
{
    public function __construct(private string $directory)
    {
    }

    public function granted(): bool
    {
        return "granted\n" === @file_get_contents($this->directory.'/permission');
    }

    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        $expected = @file_get_contents($this->directory.'/token');
        if (false === $expected || \strlen($expected) < 32 || !hash_equals($expected, $accessToken)) {
            throw new BadCredentialsException('Invalid bearer token.');
        }
        $roles = $this->granted() ? ['ROLE_AUDITOR'] : ['ROLE_VIEWER'];

        return new UserBadge('matrix-reader', static fn () => new InMemoryUser('matrix-reader', null, $roles));
    }

    public function isVisible(ExecutionIdentity $identity, string $tool): bool
    {
        return 'matrix-reader' === $identity->subject && $identity->allows('content.audit') && $this->granted()
            && \in_array($tool, Tools::NAMES, true);
    }

    public function isGranted(ExecutionIdentity $identity, string $tool, \stdClass $arguments): bool
    {
        if (!$this->isVisible($identity, $tool)) {
            return false;
        }
        if ('cms_list_articles' === $tool) {
            return true; // The shared schema validates pagination before invocation.
        }
        if (!\is_array($arguments->ids ?? null)) {
            return false;
        }
        foreach ($arguments->ids as $id) {
            if (!\is_int($id) || $id < 1 || $id > ('cms_get_articles' === $tool ? 48 : 24)) {
                return false;
            }
        }

        return true;
    }
}
