<?php

declare(strict_types=1);

namespace App\Demo\EditorialReview;

use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\Ai\ToolAuthorizationInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/** The fixture grants one account a derived report about published content. */
final readonly class Access implements AccessTokenHandlerInterface, ToolAuthorizationInterface
{
    public function __construct(private string $directory)
    {
    }

    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        $expected = @file_get_contents($this->directory.'/token');
        if (false !== $expected && \strlen($expected) >= 32 && hash_equals($expected, $accessToken)) {
            return new UserBadge('editorial-reviewer', fn () => new InMemoryUser('editorial-reviewer', null, $this->granted() ? ['ROLE_REVIEWER'] : ['ROLE_VIEWER']));
        }
        // Only the independent positive-control test provisions an exporter.
        $exporter = @file_get_contents($this->directory.'/exporter-token');
        if (false !== $exporter && \strlen($exporter) >= 32 && hash_equals($exporter, $accessToken)) {
            return new UserBadge('publication-exporter', static fn () => new InMemoryUser('publication-exporter', null, ['ROLE_EXPORTER']));
        }
        throw new BadCredentialsException('Invalid bearer token.');
    }

    public function isGranted(ExecutionIdentity $identity, string $tool, \stdClass $arguments): bool
    {
        if (PublicationFindings::NAME === $tool) {
            return 'editorial-reviewer' === $identity->subject && $identity->allows('publication.findings') && $this->granted();
        }

        return 'publication-exporter' === $identity->subject && $identity->allows('publication.raw_published')
            && \in_array($tool, ['cms_get_articles', 'cms_get_media'], true);
    }

    public function assertFindings(ExecutionIdentity $identity): void
    {
        if (!$this->isGranted($identity, PublicationFindings::NAME, new \stdClass())) {
            throw new \RuntimeException('The findings grant is no longer available.');
        }
    }

    private function granted(): bool
    {
        return "granted\n" === @file_get_contents($this->directory.'/permission');
    }
}
