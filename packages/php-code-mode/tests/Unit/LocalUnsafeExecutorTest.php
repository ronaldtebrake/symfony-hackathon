<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\{CodeModeSession, DockerEngine, ExecutionIdentity, ExecutionLimits, ExecutionProfile, ExecutionTimeline, GvisorSandbox, LocalUnsafeExecutor, SandboxException, Tool, ToolBroker, ToolCatalog, ToolDefinition};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Real local PHP, synthetic data only. These are behavior checks, not containment proofs. */
final class LocalUnsafeExecutorTest extends TestCase
{
    private string $marker;

    protected function setUp(): void
    {
        $this->marker = tempnam(sys_get_temp_dir(), 'code-mode-local-test-');
    }

    protected function tearDown(): void { @unlink($this->marker); }

    private function broker(): ToolBroker
    {
        $marker = $this->marker;
        return new ToolBroker([new Tool('read', json_decode('{"type":"object","properties":{"id":{"type":"integer"}},"required":["id"],"additionalProperties":false}'),
            static fn (ExecutionIdentity $identity, \stdClass $args): bool => $identity->allows('public') && 1 === ($args->id ?? null),
            static function () use ($marker): array { file_put_contents($marker, 'invoked'); return ['title' => 'Public article']; },
        )]);
    }

    private function runPhp(string $code, ?ExecutionLimits $limits = null, ?ExecutionTimeline $timeline = null): \CodeMode\ExecutionResult
    {
        return (new LocalUnsafeExecutor($timeline))->execute($code, $this->broker(), new ExecutionIdentity('reader', ['public']), ['read'], $limits);
    }

    public function testRealPhpAndAuthorizedBrokerCallCarryExplicitUnsafeProvenance(): void
    {
        self::assertTrue(LocalUnsafeExecutor::available());
        $timeline = new ExecutionTimeline();
        $result = $this->runPhp('$item = $tools->call("read", ["id" => 1]); return ["title" => $item->title, "sum" => array_sum([20, 22]), "pid" => getmypid(), "cwd" => getcwd()];', timeline: $timeline);
        self::assertSame('Public article', $result->value->title);
        self::assertSame(42, $result->value->sum);
        self::assertSame('invoked', file_get_contents($this->marker));
        self::assertSame('succeeded', $result->toolCalls[0]['status']);
        self::assertSame(ExecutionProfile::LocalUnsafe, $result->profile);
        self::assertSame('none', $result->profile->metadata()['isolation']);
        self::assertStringStartsWith('code-mode-local-', $result->runId);
        self::assertFalse(posix_kill($result->value->pid, 0), 'PHP process survived cleanup.');
        self::assertDirectoryDoesNotExist($result->value->cwd);
        self::assertSame(['local_setup', 'execution', 'broker', 'cleanup'], array_column($timeline->snapshot(), 'stage'));
        self::assertSame('none', $timeline->snapshot()[0]['evidence']['runtime']['isolation']);
    }

    public function testNoInheritedEnvironmentOrApplicationBootstrapButHostFileRemainsReadable(): void
    {
        file_put_contents($this->marker, 'synthetic host sentinel');
        putenv('CODE_MODE_LOCAL_TEST_SECRET=synthetic-environment-sentinel');
        try {
            $result = $this->runPhp('return ["secret" => getenv("CODE_MODE_LOCAL_TEST_SECRET"), "autoload" => class_exists("CodeMode\\\\ToolBroker"), "file" => file_get_contents('.var_export($this->marker, true).'), "user" => getmyuid()];');
        } finally {
            putenv('CODE_MODE_LOCAL_TEST_SECRET');
        }
        self::assertFalse($result->value->secret);
        self::assertFalse($result->value->autoload);
        self::assertSame('synthetic host sentinel', $result->value->file, 'Local mode must never be advertised as host file isolation.');
        self::assertSame(getmyuid(), $result->value->user);
    }

    public function testDeniedRestrictedReadDoesNotInvokeHandlerAndPreservesFailureTrace(): void
    {
        $timeline = new ExecutionTimeline();
        try {
            $this->runPhp('return $tools->call("read", ["id" => 2]);', timeline: $timeline);
            self::fail('Restricted read succeeded.');
        } catch (SandboxException $error) {
            self::assertSame('tool_denied', $error->reason);
            self::assertSame(ExecutionProfile::LocalUnsafe, $error->profile);
            self::assertSame('denied', $error->toolCalls[0]['status']);
            self::assertSame('', file_get_contents($this->marker));
            self::assertDirectoryDoesNotExist(sys_get_temp_dir().'/'.$error->runId);
            self::assertSame('succeeded', $timeline->snapshot()[3]['status']);
        }
    }

    #[DataProvider('failures')]
    public function testBoundedFailures(string $code, string $reason, array $limits = []): void
    {
        $start = microtime(true);
        try {
            $this->runPhp($code, new ExecutionLimits(...$limits));
            self::fail('Execution unexpectedly succeeded.');
        } catch (SandboxException $error) {
            self::assertSame($reason, $error->reason);
            self::assertSame(ExecutionProfile::LocalUnsafe, $error->profile);
            self::assertDirectoryDoesNotExist(sys_get_temp_dir().'/'.$error->runId);
            self::assertLessThan(5, microtime(true) - $start);
        }
    }

    public static function failures(): iterable
    {
        yield 'CPU loop' => ['while (true) {}', 'wall_timeout', ['wallTimeMs' => 200, 'toolTimeMs' => 100]];
        yield 'sleep' => ['sleep(60);', 'wall_timeout', ['wallTimeMs' => 200, 'toolTimeMs' => 100]];
        yield 'PHP heap limit' => ['return str_repeat("x", 128 * 1024 * 1024);', 'guest_failed'];
        yield 'stderr flood' => ['while (true) { fwrite(STDERR, str_repeat("x", 8192)); }', 'output_limit'];
        yield 'stdout flood' => ['while (true) { fwrite(STDOUT, str_repeat("x", 8192)); }', 'frame_limit'];
        yield 'oversized result' => ['return str_repeat("x", 20000);', 'frame_limit'];
        yield 'forged runtime' => ['fwrite(STDOUT, "{\"type\":\"result\",\"value\":42,\"runtime\":\"gvisor\"}\n");', 'invalid_protocol'];
        yield 'forged identity' => ['return $tools->call("read", ["id" => 1, "identity" => "admin"]);', 'invalid_arguments'];
        yield 'unknown tool' => ['return $tools->call("delete");', 'tool_denied'];
        yield 'tool budget' => ['$tools->call("read", ["id" => 1]); $tools->call("read", ["id" => 1]);', 'tool_call_limit', ['maxToolCalls' => 1]];
        yield 'no result' => ['exit(0);', 'guest_failed'];
        yield 'source limit' => [str_repeat(' ', 32769), 'code_limit'];
    }

    public function testLoopAfterReadRetainsCallAndTerminatesPhp(): void
    {
        // The PID is synthetic test data, recorded through a trusted handler before the loop.
        $marker = $this->marker;
        $broker = new ToolBroker([new Tool('pid', (object) ['type' => 'object'], static fn (): bool => true,
            static function (\stdClass $args) use ($marker): void { file_put_contents($marker, (string) $args->pid); })]);
        try {
            (new LocalUnsafeExecutor())->execute('$tools->call("pid", ["pid" => getmypid()]); while (true) {}', $broker, new ExecutionIdentity('test'), ['pid'], new ExecutionLimits(wallTimeMs: 300, toolTimeMs: 100));
            self::fail('Loop was not terminated.');
        } catch (SandboxException $error) {
            self::assertSame('wall_timeout', $error->reason);
            self::assertSame('succeeded', $error->toolCalls[0]['status']);
            $pid = (int) file_get_contents($marker);
            self::assertGreaterThan(0, $pid);
            // The killed supervisor cannot reap its child; the OS may briefly retain a zombie.
            $deadline = microtime(true) + 1;
            while (posix_kill($pid, 0) && microtime(true) < $deadline) { usleep(10000); }
            self::assertFalse(posix_kill($pid, 0), 'PHP process survived cancellation.');
        }
    }

    public function testDiscoveryAndAttemptBudgetsStillApply(): void
    {
        $session = new CodeModeSession(new ToolCatalog([new ToolDefinition('read', 'Read public data', (object) ['type' => 'object'])]), $this->broker(), new LocalUnsafeExecutor(), new ExecutionIdentity('reader', ['public']));
        try { $session->execute('return 1;'); self::fail('Discovery was bypassed.'); }
        catch (SandboxException $e) { self::assertSame('discovery_required', $e->reason); }
        $session->describe(['read']);
        try { $session->execute('return $tools->call("read", ["id" => 2]);'); self::fail('Read was allowed.'); }
        catch (SandboxException $e) { self::assertSame('tool_denied', $e->reason); }
        self::assertSame('Public article', $session->execute('return $tools->call("read", ["id" => 1]);')->value->title);
        try { $session->execute('return 1;'); self::fail('Attempt budget was reset.'); }
        catch (SandboxException $e) { self::assertSame('execution_limit', $e->reason); }
    }

    public function testGvisorFailureNeverFallsBackToLocalPhp(): void
    {
        $sandbox = new GvisorSandbox('sha256:'.str_repeat('a', 64), new DockerEngine([PHP_BINARY, '-r', 'echo "null";']));
        try {
            $sandbox->execute('file_put_contents('.var_export($this->marker, true).', "must not execute"); return true;', $this->broker(), new ExecutionIdentity('test'));
            self::fail('Missing gVisor must fail closed.');
        } catch (SandboxException $e) {
            self::assertSame('isolation_unavailable', $e->reason);
            self::assertSame('', file_get_contents($this->marker));
        }
    }

    public function testGuestCannotOverrideHostRuntimeMetadata(): void
    {
        $result = $this->runPhp('return ["runtime" => ["executor" => "gvisor", "isolation" => "gvisor"], "toolCalls" => ["forged"]];');
        self::assertSame('gvisor', $result->value->runtime->executor); // Untrusted return value only.
        self::assertSame('none', $result->profile->metadata()['isolation']);
        self::assertSame([], $result->toolCalls);
    }

    public function testCleanupDoesNotSilentlySucceedOrFollowLinksLeftByPhp(): void
    {
        file_put_contents($this->marker, 'synthetic host sentinel');
        $directory = null;
        try {
            $this->runPhp('symlink('.var_export($this->marker, true).', "leftover"); return true;');
            self::fail('Nonempty scratch directory must fail cleanup.');
        } catch (SandboxException $error) {
            self::assertSame('cleanup_failed', $error->reason);
            self::assertSame(ExecutionProfile::LocalUnsafe, $error->profile);
            $directory = sys_get_temp_dir().'/'.$error->runId;
            self::assertTrue(is_link($directory.'/leftover'));
            self::assertSame('synthetic host sentinel', file_get_contents($this->marker));
        } finally {
            if (null !== $directory) { @unlink($directory.'/leftover'); @rmdir($directory); }
        }
    }

    public function testIndependentSupervisorStopsSleepingPhpAfterCoordinatorIsKilled(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/fixtures/local-abandon.php', $this->marker]);
        $state = null;
        try {
            $process->start();
            $deadline = microtime(true) + 2;
            while ('' === file_get_contents($this->marker) && microtime(true) < $deadline && $process->isRunning()) { usleep(10000); }
            $state = json_decode(file_get_contents($this->marker), true, flags: JSON_THROW_ON_ERROR);
            self::assertGreaterThan(0, $state['pid']);
            self::assertTrue(posix_kill($state['pid'], 0), 'The sleeping PHP witness must be alive before killing its coordinator.');
            $process->signal(SIGKILL);
            $deadline = microtime(true) + 5;
            do {
                $probe = new Process(['ps', '-o', 'stat=', '-p', (string) $state['pid']]);
                $probe->run();
                self::assertContains($probe->getExitCode(), [0, 1]);
                self::assertSame('', $probe->getErrorOutput(), 'Process inspection must work before absence can prove cleanup.');
                $status = trim($probe->getOutput());
                if ('' === $status || str_starts_with($status, 'Z')) { break; }
                usleep(50000);
            } while (microtime(true) < $deadline);
            self::assertTrue('' === $status || str_starts_with($status, 'Z'), 'Local PHP survived its independent watchdog.');
        } finally {
            $process->stop(0, SIGKILL);
            if (is_array($state)) { @rmdir($state['directory']); }
        }
    }
}
