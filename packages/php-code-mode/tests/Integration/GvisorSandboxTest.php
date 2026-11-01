<?php

declare(strict_types=1);

namespace CodeMode\Tests\Integration;

use CodeMode\DockerEngine;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\ExecutionResult;
use CodeMode\GvisorSandbox;
use CodeMode\SandboxException;
use CodeMode\Tool;
use CodeMode\ToolBroker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Requires a dedicated Docker endpoint with runsc. Missing infrastructure FAILS this suite. */
final class GvisorSandboxTest extends TestCase
{
    private DockerEngine $docker;
    private GvisorSandbox $sandbox;
    private string $marker;

    protected function setUp(): void
    {
        $this->docker = new DockerEngine(json_decode(getenv('CODE_MODE_DOCKER_COMMAND') ?: '["docker"]', true, flags: JSON_THROW_ON_ERROR));
        $this->sandbox = new GvisorSandbox(getenv('CODE_MODE_IMAGE') ?: '', $this->docker);
        $this->marker = tempnam(sys_get_temp_dir(), 'code-mode-test-');
    }

    protected function tearDown(): void
    {
        if (isset($this->marker)) {
            @unlink($this->marker);
        }
        if (isset($this->docker)) {
            self::assertSame('', $this->docker->control(['ps', '-aq', '--filter', 'label=php-code-mode.managed=true']), 'An execution leaked a container.');
        }
    }

    private function runPhp(string $code, ?ExecutionLimits $limits = null, ?ToolBroker $broker = null, array $allowed = ['demo.add']): ExecutionResult
    {
        $marker = $this->marker;
        $broker ??= new ToolBroker([new Tool('demo.add', json_decode('{"type":"object","properties":{"a":{"type":"integer"},"b":{"type":"integer"}},"required":["a","b"],"additionalProperties":false}'),
            static fn (ExecutionIdentity $identity): bool => $identity->allows('calculate'),
            static function (\stdClass $args, ExecutionIdentity $identity) use ($marker): int {
                file_put_contents($marker, $identity->subject."\n", FILE_APPEND);
                return $args->a + $args->b;
            },
        )]);
        return $this->sandbox->execute($code, $broker, new ExecutionIdentity('alice', ['calculate']), $allowed, $limits);
    }

    private function assertRejected(string $reason, \Closure $operation): void
    {
        try {
            $operation();
            self::fail('Execution unexpectedly succeeded.');
        } catch (SandboxException $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testRealPhpComposesAuthorizedTools(): void
    {
        $result = $this->runPhp(<<<'PHP'
$sum = static fn (array $numbers): int => array_sum($numbers);
$values = [];
foreach ([2, 4, 6] as $number) {
    $values[] = $tools->call('demo.add', ['a' => $number, 'b' => 10]);
}
return ['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'sum' => $sum($values)];
PHP);
        self::assertSame('cli', $result->value->sapi);
        self::assertStringStartsWith('8.4.', $result->value->php);
        self::assertSame(42, $result->value->sum);
        self::assertCount(3, $result->toolCalls);
        self::assertSame("alice\nalice\nalice\n", file_get_contents($this->marker));
    }

    public function testHostFilesSecretsAndWritableRootAreUnavailable(): void
    {
        file_put_contents($this->marker, 'host sentinel');
        putenv('CODE_MODE_HOST_SECRET=must-not-leak');
        try {
            $path = var_export($this->marker, true);
            $result = $this->runPhp(<<<PHP
return [
    'hostFile' => @file_get_contents($path),
    'secret' => getenv('CODE_MODE_HOST_SECRET'),
    'dockerSocket' => file_exists('/var/run/docker.sock'),
    'rootWrite' => @file_put_contents('/runner/escape', 'bad'),
    'tmpWrite' => file_put_contents('/tmp/private', 'ok'),
];
PHP);
        } finally {
            putenv('CODE_MODE_HOST_SECRET');
        }
        self::assertFalse($result->value->hostFile);
        self::assertFalse($result->value->secret);
        self::assertFalse($result->value->dockerSocket);
        self::assertFalse($result->value->rootWrite);
        self::assertSame(2, $result->value->tmpWrite);
        self::assertSame('host sentinel', file_get_contents($this->marker));
    }

    public function testGuestCannotRelaxPhpLimitsOrCreateHostProcesses(): void
    {
        $result = $this->runPhp('return array_map("function_exists", ["exec", "proc_open", "pcntl_fork", "ini_set", "set_time_limit", "dl", "putenv"]);');
        self::assertSame(array_fill(0, 7, false), $result->value);
        $fresh = $this->runPhp('return file_exists("/tmp/private");');
        self::assertFalse($fresh->value);
    }

    public function testTemporaryStorageIsBounded(): void
    {
        $result = $this->runPhp('$file = fopen("/tmp/fill", "w"); $bytes = 0; for ($i = 0; $i < 8; ++$i) { $bytes += (int) @fwrite($file, str_repeat("x", 1048576)); } return $bytes;');
        self::assertGreaterThan(0, $result->value);
        self::assertLessThanOrEqual(4 * 1048576, $result->value);
    }

    #[DataProvider('attacks')]
    public function testHostileProgramsFailAndAreRemoved(string $code, string $reason, array $limits = []): void
    {
        $this->assertRejected($reason, fn () => $this->runPhp($code, new ExecutionLimits(...$limits)));
    }

    public static function attacks(): iterable
    {
        yield 'CPU loop' => ['while (true) {}', 'wall_timeout', ['wallTimeMs' => 700, 'toolTimeMs' => 100]];
        yield 'sleep bypasses PHP CPU timer' => ['sleep(60);', 'wall_timeout', ['wallTimeMs' => 700, 'toolTimeMs' => 100]];
        yield 'memory exhaustion' => ['return str_repeat("x", 128 * 1024 * 1024);', 'guest_failed'];
        yield 'unterminated frame' => ['fwrite(STDOUT, str_repeat("x", 20000)); sleep(60);', 'frame_limit'];
        yield 'continuous stdout flood' => ['while (true) { fwrite(STDOUT, str_repeat("x", 8192)); }', 'frame_limit'];
        yield 'stderr flood' => ['while (true) { fwrite(STDERR, str_repeat("x", 8192)); }', 'output_limit'];
        yield 'oversized result' => ['return str_repeat("x", 20000);', 'frame_limit'];
        yield 'unknown command' => ['fwrite(STDOUT, "{\"type\":\"shell\",\"command\":\"id\"}\n");', 'invalid_protocol'];
        yield 'unregistered tool' => ['return $tools->call("system", ["command" => "id"]);', 'tool_denied'];
        yield 'schema invalid' => ['return $tools->call("demo.add", ["a" => "2", "b" => 3]);', 'invalid_arguments'];
        yield 'forged identity in arguments' => ['return $tools->call("demo.add", ["a" => 2, "b" => 3, "identity" => "admin"]);', 'invalid_arguments'];
        yield 'forged identity in frame' => ['fwrite(STDOUT, "{\"type\":\"tool_call\",\"id\":1,\"name\":\"demo.add\",\"arguments\":{\"a\":1,\"b\":2},\"identity\":\"admin\"}\n");', 'invalid_protocol'];
        yield 'no result' => ['exit(0);', 'guest_failed'];
        yield 'call budget' => ['for ($i = 0; $i < 3; ++$i) { $tools->call("demo.add", ["a" => 1, "b" => 2]); }', 'tool_call_limit', ['maxToolCalls' => 2]];
        yield 'source budget' => [str_repeat(' ', 32769), 'code_limit'];
    }

    public function testDeniedCallNeverReachesHostHandler(): void
    {
        try {
            $this->runPhp('return $tools->call("demo.add", ["a" => 2, "b" => 3]);', allowed: []);
            self::fail('The call must be denied.');
        } catch (SandboxException $error) {
            self::assertSame('tool_denied', $error->reason);
            self::assertMatchesRegularExpression('/\Acode-mode-[a-f0-9]{32}\z/', $error->runId);
            self::assertSame('denied', $error->toolCalls[0]['status']);
            self::assertSame('tool_denied', $error->toolCalls[0]['reason']);
        }
        self::assertSame('', file_get_contents($this->marker));
    }

    public function testFailedExecutionRetainsEarlierHostRecordedCalls(): void
    {
        try {
            $this->runPhp('$tools->call("demo.add", ["a" => 20, "b" => 22]); while (true) {}', new ExecutionLimits(wallTimeMs: 1000, toolTimeMs: 500));
            self::fail('The program should exceed its deadline.');
        } catch (SandboxException $error) {
            self::assertSame('wall_timeout', $error->reason);
            self::assertMatchesRegularExpression('/\Acode-mode-[a-f0-9]{32}\z/', $error->runId);
            self::assertCount(1, $error->toolCalls);
            self::assertSame('succeeded', $error->toolCalls[0]['status']);
            self::assertSame(2, $error->toolCalls[0]['resultBytes']);
            self::assertSame("alice\n", file_get_contents($this->marker));
        }
    }

    public function testCallsAfterResultNeverReachHandler(): void
    {
        $code = 'fwrite(STDOUT, "{\"type\":\"result\",\"value\":42}\n{\"type\":\"tool_call\",\"id\":1,\"name\":\"demo.add\",\"arguments\":{\"a\":1,\"b\":2}}\n"); exit(0);';
        $this->assertRejected('invalid_protocol', fn () => $this->runPhp($code));
        self::assertSame('', file_get_contents($this->marker));
    }

    public function testForgedGuestClaimsCannotForgeHostLedger(): void
    {
        $result = $this->runPhp('fwrite(STDOUT, "{\"type\":\"result\",\"value\":{\"toolCalls\":[\"forged\"]}}\n"); exit(0);');
        self::assertSame(['forged'], $result->value->toolCalls);
        self::assertSame([], $result->toolCalls);
    }

    public function testBlockingHandlerCannotDisableWatchdog(): void
    {
        $marker = $this->marker;
        $broker = new ToolBroker([new Tool('slow', (object) ['type' => 'object'], static fn (): bool => true,
            static function () use ($marker): void {
                file_put_contents($marker, (string) getmypid());
                sleep(60);
            },
        )]);
        $start = microtime(true);
        $this->assertRejected('tool_timeout', fn () => $this->runPhp('$tools->call("slow");', new ExecutionLimits(toolTimeMs: 100), $broker, ['slow']));
        self::assertLessThan(5, microtime(true) - $start);
        self::assertGreaterThan(0, (int) file_get_contents($marker));
        self::assertFalse(posix_kill((int) file_get_contents($marker), 0), 'Broker worker survived cancellation.');
    }

    public function testBrokerResponseSizeIsBounded(): void
    {
        $broker = new ToolBroker([new Tool('large', (object) ['type' => 'object'], static fn (): bool => true, static fn (): string => str_repeat('x', 20000))]);
        $this->assertRejected('tool_result_limit', fn () => $this->runPhp('$tools->call("large");', broker: $broker, allowed: ['large']));
    }

    public function testNetworkDenialAgainstAReachableControlServer(): void
    {
        $name = 'code-mode-'.bin2hex(random_bytes(16));
        $image = getenv('CODE_MODE_IMAGE');
        try {
            // Trusted test fixture and positive control; neither executes guest-supplied source.
            $this->docker->control(['run', '-d', '--name', $name, '--entrypoint', '/usr/local/bin/php', $image, '-n', '-S', '0.0.0.0:8000', '-t', '/runner']);
            $ip = $this->docker->control(['inspect', '-f', '{{.NetworkSettings.Networks.bridge.IPAddress}}', $name]);
            self::assertNotFalse(filter_var($ip, FILTER_VALIDATE_IP));
            $code = '$socket = @fsockopen('.var_export($ip, true).', 8000, $errno, $error, 0.2); echo is_resource($socket) ? "connected" : "denied";';
            $control = $this->docker->control(['run', '--rm', '--entrypoint', '/usr/local/bin/php', $image, '-n', '-r', $code]);
            self::assertSame('connected', $control, 'The network control endpoint is not reachable.');
            $result = $this->runPhp('$socket = @fsockopen('.var_export($ip, true).', 8000, $errno, $error, 0.2); return is_resource($socket);');
            self::assertFalse($result->value);
        } finally {
            $this->docker->remove($name);
        }
    }

    public static function crashScenarios(): iterable
    {
        yield 'guest is sleeping' => ['ready'];
        yield 'broker handler is blocked' => ['blocked'];
    }

    #[DataProvider('crashScenarios')]
    public function testIndependentWatchdogRemovesExecutionAfterHostIsKilled(string $mode): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/fixtures/abandon.php', $this->marker, $mode]);
        try {
            $process->start();
            $deadline = microtime(true) + 10;
            while ('' === file_get_contents($this->marker) && microtime(true) < $deadline && $process->isRunning()) {
                usleep(10000);
            }
            $workerPid = (int) file_get_contents($this->marker);
            self::assertGreaterThan(0, $workerPid, $process->getErrorOutput());
            self::assertNotSame('', $this->docker->control(['ps', '-q', '--filter', 'label=php-code-mode.managed=true']));
            $process->signal(SIGKILL); // No PHP finally block or destructor can run.
            $brokerDeadline = microtime(true) + 2;
            $deadline = microtime(true) + 6;
            do {
                usleep(50000);
                $containers = $this->docker->control(['ps', '-aq', '--filter', 'label=php-code-mode.managed=true']);
            } while ('' !== $containers && microtime(true) < $deadline);
            self::assertSame('', $containers, 'The independent watchdog did not remove the orphan.');
            // A hard-killed parent cannot waitpid(). The OS/subreaper owns the dead child.
            // A zombie is terminated; kill(pid, 0) alone cannot distinguish it from live code.
            // A disconnected guest can exit before the broker's one-second alarm expires.
            do {
                $state = new Process(['ps', '-o', 'stat=', '-p', (string) $workerPid]);
                $state->run();
                self::assertContains($state->getExitCode(), [0, 1]);
                self::assertSame('', $state->getErrorOutput());
                $status = trim($state->getOutput());
                if ('' === $status || str_starts_with($status, 'Z')) {
                    break;
                }
                usleep(50000);
            } while (microtime(true) < $brokerDeadline);
            self::assertTrue('' === $status || str_starts_with($status, 'Z'), 'Orphaned broker is still executing: '.$status);
        } finally {
            $process->stop(0, SIGKILL);
        }
    }
}
