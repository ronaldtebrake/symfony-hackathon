<?php

declare(strict_types=1);

use Adoption\DemoReaderAccess;
use Adoption\DemoTokenHandler;
use Adoption\ReaderAccessCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ReaderAccessTest extends TestCase
{
    public function testSameTokenAndSubjectLoseRoleWithoutRestart(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reader-access-');
        try {
            $access = new DemoReaderAccess($path);
            $handler = new DemoTokenHandler($access, str_repeat('r', 32));
            $command = new CommandTester(new ReaderAccessCommand($access));
            $this->assertNotContains('ROLE_READER', $handler->getUserBadgeFrom(str_repeat('r', 32))->getUser()->getRoles());
            $this->assertSame(0, $command->execute(['action' => 'grant']));
            $this->assertContains('ROLE_READER', $handler->getUserBadgeFrom(str_repeat('r', 32))->getUser()->getRoles());
            $this->assertSame(0, $command->execute(['action' => 'revoke']));
            $user = $handler->getUserBadgeFrom(str_repeat('r', 32))->getUser();
            $this->assertSame('demo-reader', $user->getUserIdentifier());
            $this->assertNotContains('ROLE_READER', $user->getRoles());
            $this->assertStringContainsString('revoked', $command->getDisplay());
        } finally {
            unlink($path);
        }
    }

    public function testMissingAndMalformedStateDenyAccess(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reader-access-');
        try {
            file_put_contents($path, 'true');
            $this->assertFalse((new DemoReaderAccess($path))->isGranted());
        } finally {
            unlink($path);
        }
        $this->assertFalse((new DemoReaderAccess($path))->isGranted());
    }
}
