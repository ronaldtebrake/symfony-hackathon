<?= "<?php\n" ?>

declare(strict_types=1);

namespace <?= $root_namespace ?>\Tests\Tool;

use <?= $voter_class ?>;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class <?= $voter_short ?>Test extends TestCase
{
    public function testScaffoldDoesNotGrantDiscoveryOrReadAccess(): void
    {
        $voter = new <?= $voter_short ?>();
        $token = new UsernamePasswordToken(new InMemoryUser('reader', null, ['ROLE_USER', 'ROLE_ADMIN']), 'main', ['ROLE_USER', 'ROLE_ADMIN']);
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, null, [<?= $voter_short ?>::DISCOVER]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, 1, [<?= $voter_short ?>::READ]));
    }

    // When implementing the voter, replace this scaffold assertion with your own
    // allowed resource, denied resource and permission-revocation cases.
}
