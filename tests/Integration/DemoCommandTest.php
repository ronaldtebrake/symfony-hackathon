<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\CodeModeDemoCommand;
use App\Demo\DemoFactory;
use App\Demo\DemoRunner;
use App\Demo\ReportStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DemoCommandTest extends TestCase
{
    #[DataProvider('invalidOptions')]
    public function testInvalidQuestionsFailBeforeStartingExecutionOrReplacingReports(array $options): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $platform->expects($this->never())->method('invoke');
        $root = \dirname(__DIR__, 2);
        $reportRoot = sys_get_temp_dir().'/code-mode-unused-'.bin2hex(random_bytes(8));
        $tester = new CommandTester(new CodeModeDemoCommand(new DemoRunner(new DemoFactory($root), $platform, $root), new ReportStore($reportRoot)));
        $this->assertSame(Command::INVALID, $tester->execute($options));
        $this->assertDirectoryDoesNotExist($reportRoot);
    }

    public static function invalidOptions(): iterable
    {
        yield 'reference custom question' => [['--question' => 'Count articles.']];
        yield 'reference impact' => [['--scenario' => 'impact']];
        yield 'external backend' => [['--mode' => 'live', '--backend' => 'http', '--question' => 'Count articles.']];
        yield 'ambiguous selection' => [['--mode' => 'live', '--scenario' => 'impact', '--question' => 'Count articles.']];
        yield 'oversized' => [['--mode' => 'live', '--question' => str_repeat('a', 2001)]];
        yield 'control character' => [['--mode' => 'live', '--question' => "Count\x1b articles."]];
    }
}
