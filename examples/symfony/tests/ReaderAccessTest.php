<?php

declare(strict_types=1);

use Adoption\{DemoReaderAccess, DemoTokenHandler, ReaderAccessCommand};
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
            self::assertNotContains('ROLE_READER', $handler->getUserBadgeFrom(str_repeat('r', 32))->getUser()->getRoles());
            self::assertSame(0, $command->execute(['action' => 'grant']));
            self::assertContains('ROLE_READER', $handler->getUserBadgeFrom(str_repeat('r', 32))->getUser()->getRoles());
            self::assertSame(0, $command->execute(['action' => 'revoke']));
            $user = $handler->getUserBadgeFrom(str_repeat('r', 32))->getUser();
            self::assertSame('demo-reader', $user->getUserIdentifier());
            self::assertNotContains('ROLE_READER', $user->getRoles());
            self::assertStringContainsString('revoked', $command->getDisplay());
        } finally { unlink($path); }
    }

    public function testMissingAndMalformedStateDenyAccess(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reader-access-');
        try {
            file_put_contents($path, 'true');
            self::assertFalse((new DemoReaderAccess($path))->isGranted());
        } finally { unlink($path); }
        self::assertFalse((new DemoReaderAccess($path))->isGranted());
    }
}
