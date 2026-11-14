<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\Security\VoterToolPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class VoterToolPolicyTest extends TestCase
{
    public function testDiscoveryDoesNotGrantResourceAccessAndRevocationIsImmediate(): void
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('reader', null), 'main'));
        $granted = true;
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static function ($attribute, $subject = null) use (&$granted): bool {
            return $granted && ('READ' !== $attribute || 101 === $subject);
        });
        $policy = new VoterToolPolicy($tokens, $authorization, ['read' => ['discover' => 'DISCOVER', 'execute' => 'READ', 'subject' => 'id']]);
        $reader = new ExecutionIdentity('reader');
        $this->assertTrue($policy->isVisible($reader, 'read'));
        $this->assertTrue($policy->isGranted($reader, 'read', (object) ['id' => 101]));
        foreach ([202, '101', ['id' => 101], null] as $id) {
            $this->assertFalse($policy->isGranted($reader, 'read', (object) ['id' => $id]));
        }
        $this->assertFalse($policy->isGranted($reader, 'read', new \stdClass()));
        $this->assertFalse($policy->isVisible($reader, 'unconfigured'));
        $this->assertFalse($policy->isVisible(new ExecutionIdentity('admin'), 'read'));
        $granted = false;
        $this->assertFalse($policy->isVisible($reader, 'read'));
        $this->assertFalse($policy->isGranted($reader, 'read', (object) ['id' => 101]));
        $granted = true;
        $tokens->setToken(null);
        $this->assertFalse($policy->isGranted($reader, 'read', (object) ['id' => 101]));
    }
}
