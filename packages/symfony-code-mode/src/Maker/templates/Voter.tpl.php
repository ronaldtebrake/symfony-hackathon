<?= "<?php\n" ?>

declare(strict_types=1);

namespace <?= $namespace ?>;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

final class <?= $class_name ?> extends Voter
{
    public const DISCOVER = '<?= $attribute ?>_DISCOVER';
    public const READ = '<?= $attribute ?>_READ';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return (self::DISCOVER === $attribute && null === $subject)
            || (self::READ === $attribute && is_int($subject));
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (!$token->getUser() instanceof UserInterface) { return false; }
        // Implement capability access for DISCOVER and resource access for READ.
        // A discovered contract never grants access to every record.
        return false;
    }
}
