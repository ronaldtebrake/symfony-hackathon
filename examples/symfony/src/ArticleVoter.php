<?php

declare(strict_types=1);

namespace Adoption;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** One application policy for controller access, catalog discovery and native AI tool execution. */
final class ArticleVoter extends Voter
{
    public const DISCOVER = 'PUBLICATION_DISCOVER';
    public const READ = 'PUBLICATION_READ';

    public function __construct(private readonly ArticleCatalog $articles, private readonly DemoReaderAccess $access)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Route attributes are strings; native tool arguments have already been typed.
        return (self::DISCOVER === $attribute && null === $subject)
            || (self::READ === $attribute && (\is_int($subject) || \is_string($subject))
                && false !== filter_var($subject, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]));
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        // Re-read the application's permission fixture even when a token/contract was already loaded.
        return 'demo-reader' === $token->getUserIdentifier() && \in_array('ROLE_READER', $token->getRoleNames(), true)
            && $this->access->isGranted() && (self::DISCOVER === $attribute || $this->articles->isPublished((int) $subject));
    }
}
