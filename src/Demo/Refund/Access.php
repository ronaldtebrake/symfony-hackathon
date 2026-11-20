<?php

declare(strict_types=1);

namespace App\Demo\Refund;

use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\Ai\ToolAuthorizationInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final readonly class Access implements AccessTokenHandlerInterface, ToolAuthorizationInterface
{
    public function __construct(private string $directory)
    {
    }

    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        foreach (['reader', 'viewer'] as $account) {
            $expected = file_get_contents($this->directory.'/'.$account.'.token');
            if (false !== $expected && \strlen($expected) >= 32 && hash_equals($expected, $accessToken)) {
                $roles = 'reader' === $account ? ['ROLE_REVIEWER'] : ['ROLE_VIEWER'];

                return new UserBadge($account, static fn () => new InMemoryUser($account, null, $roles));
            }
        }

        throw new BadCredentialsException('Invalid bearer token.');
    }

    public function canReview(ExecutionIdentity $identity): bool
    {
        return 'reader' === $identity->subject && $identity->allows('refund.review') && "granted\n" === file_get_contents($this->directory.'/permission');
    }

    public function isGranted(ExecutionIdentity $identity, string $tool, \stdClass $arguments): bool
    {
        return $this->canReview($identity) && match ($tool) {
            'refund_get_order' => 'R-1042' === ($arguments->orderId ?? null),
            'refund_get_policy' => 'standard-eu' === ($arguments->policyId ?? null),
            default => false,
        };
    }
}
