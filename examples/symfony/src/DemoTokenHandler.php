<?php

declare(strict_types=1);

namespace Adoption;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/** Small local demo authenticator. Real applications keep their own token validation. */
final readonly class DemoTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(private DemoReaderAccess $access, private string $readerToken, private string $viewerToken = '') {}

    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        if (strlen($this->readerToken) >= 32 && hash_equals($this->readerToken, $accessToken)) {
            $roles = $this->access->isGranted() ? ['ROLE_READER'] : ['ROLE_VIEWER'];
            return new UserBadge('demo-reader', static fn () => new InMemoryUser('demo-reader', null, $roles));
        }
        if (strlen($this->viewerToken) >= 32 && hash_equals($this->viewerToken, $accessToken)) {
            return new UserBadge('demo-viewer', static fn () => new InMemoryUser('demo-viewer', null, ['ROLE_VIEWER']));
        }
        throw new BadCredentialsException('Invalid bearer token.');
    }
}
