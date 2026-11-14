<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class MakerTest extends TestCase
{
    public function testGeneratedToolsAreHiddenByDefaultAndDoNotReplaceApplicationPolicy(): void
    {
        $app = dirname(__DIR__);
        $directory = sys_get_temp_dir().'/code-mode-maker-'.bin2hex(random_bytes(8));
        $fs = new Filesystem();
        try {
            // An isolated consumer with its own copied autoloader, not the recording application.
            foreach (['src', 'config', 'bin', 'public', 'vendor'] as $path) { $fs->mirror($app.'/'.$path, $directory.'/'.$path); }
            $fs->copy($app.'/composer.json', $directory.'/composer.json');
            $fs->copy($app.'/tests/NativeIntegrationTest.php', $directory.'/tests/NativeIntegrationTest.php');
            $fs->dumpFile($directory.'/.env', "APP_SECRET=test-only\n");
            $run = static function (array $arguments) use ($directory): Process {
                $process = new Process([PHP_BINARY, '-d', 'variables_order=EGPCS', ...$arguments], $directory,
                    ['APP_ENV' => 'dev', 'ADOPTION_MODE' => 'catalog', 'SHELL_VERBOSITY' => '0'], timeout: 60);
                $process->run();
                return $process;
            };
            $services = file_get_contents($directory.'/config/services.php');
            $fs->dumpFile($directory.'/config/services.php', preg_replace("/^\s*'(?:ai_tool_services|ai_tools|ai_authorization|visibility_policy|voter_rules)' => .*\n/m", '', $services));
            $empty = $run(['bin/console', 'code-mode:doctor', '--json']);
            self::assertFalse($empty->isSuccessful(), 'An empty install must boot but cannot report a ready catalog.');
            self::assertJson($empty->getOutput(), $empty->getErrorOutput());
            self::assertFalse(json_decode($empty->getOutput(), true, flags: JSON_THROW_ON_ERROR)['ready']);
            foreach (['InventoryLookup', 'AccountSummaryTool'] as $name) {
                $process = $run(['bin/console', 'make:code-mode-tool', $name, '--no-interaction']);
                self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
                // Restore the application's existing approved reader after the first scaffold.
                $fs->dumpFile($directory.'/config/services.php', $services);
            }
            $toolPath = $directory.'/src/Tool/InventoryLookupTool.php';
            $original = file_get_contents($toolPath);
            foreach (['src/Tool/InventoryLookupTool.php', 'src/Security/InventoryLookupVoter.php', 'config/packages/code_mode_inventory_lookup.php', 'tests/Tool/InventoryLookupVoterTest.php'] as $path) {
                $lint = $run(['-l', $path]);
                self::assertTrue($lint->isSuccessful(), $lint->getOutput().$lint->getErrorOutput());
            }
            $duplicate = $run(['bin/console', 'make:code-mode-tool', 'InventoryLookup', '--no-interaction']);
            self::assertFalse($duplicate->isSuccessful());
            self::assertSame($original, file_get_contents($toolPath));
            $invalid = $run(['bin/console', 'make:code-mode-tool', '../Escape', '--no-interaction']);
            self::assertFalse($invalid->isSuccessful());
            self::assertFileDoesNotExist($directory.'/src/Escape.php');
            $container = $run(['bin/console', 'lint:container']);
            self::assertTrue($container->isSuccessful(), $container->getOutput().$container->getErrorOutput());
            // Exercise the generated deny-by-default voters and the original app's HTTP read,
            // revocation, budgets and profiler with both additional registrations loaded.
            $tests = $run(['vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', 'tests']);
            self::assertTrue($tests->isSuccessful(), $tests->getOutput().$tests->getErrorOutput());
        } finally { $fs->remove($directory); }
    }
}
