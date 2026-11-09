<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\{AgentResponse, CodeModeSession, ExecutionIdentity, ExecutionLimits, ExecutionProfile, ExecutionResult, ExecutionTraceFile, SandboxException, SandboxExecutor, ToolBroker, ToolCatalog, ToolDefinition};
use PHPUnit\Framework\TestCase;

final class AgentResponseTest extends TestCase
{
    public function testHostEvidenceStaysSeparateFromUntrustedReturnValue(): void
    {
        $value = ['runtime' => 'forged', 'toolCalls' => ['forged'], 'runId' => 'forged'];
        $result = new ExecutionResult($value, [['name' => 'read', 'status' => 'succeeded']], 12, 42, 'host-run', ExecutionProfile::Gvisor);
        $sandbox = $this->createMock(SandboxExecutor::class);
        $sandbox->expects(self::once())->method('execute')->with('return 1;', self::isInstanceOf(ToolBroker::class), self::callback(static fn ($id) => 'alice' === $id->subject), ['read'], self::callback(static fn ($limits) => 10 === $limits->maxToolCalls))->willReturn($result);
        $session = $this->session($sandbox);
        $session->describe(['read']);
        $records = [];
        $response = AgentResponse::execute($session, 'return 1;', static function ($code, $trace) use (&$records) { $records[] = [$code, $trace]; });
        self::assertSame(['value' => $value], $response);
        self::assertSame('host-run', $records[0][1]['runId']);
        self::assertSame($result->toolCalls, $records[0][1]['toolCalls']);
        self::assertSame(ExecutionProfile::Gvisor->metadata(), $records[0][1]['runtime']);
    }

    public function testDenialKeepsEarlierCallsOnHostAndConsumesTheSameBudget(): void
    {
        $calls = [['name' => 'read', 'status' => 'succeeded', 'resultBytes' => 55], ['name' => 'private_read', 'status' => 'denied', 'reason' => 'tool_denied']];
        $error = new SandboxException('tool_denied', 'SECRET exception', $calls, runId: 'host-run');
        $sandbox = $this->createMock(SandboxExecutor::class);
        $sandbox->expects(self::once())->method('execute')->willThrowException($error);
        $session = $this->session($sandbox, 1);
        $session->describe(['read']);
        $records = [];
        $record = static function ($code, $trace) use (&$records) { $records[] = $trace; };
        foreach (['tool_denied', 'execution_limit'] as $reason) {
            try { AgentResponse::execute($session, 'return 1;', $record); self::fail('Execution should fail.'); }
            catch (SandboxException $caught) { self::assertSame($reason, $caught->reason); }
        }
        self::assertSame(['error' => ['reason' => 'tool_denied', 'tool' => 'private_read']], AgentResponse::failure($error, ExecutionProfile::Gvisor));
        self::assertSame($calls, $records[0]['toolCalls']);
        self::assertSame([], $records[1]['toolCalls']);
        self::assertNull($records[1]['runId']);
        self::assertStringNotContainsString('SECRET', json_encode($records));
    }

    public function testUnknownProfileAlwaysKeepsItsWarning(): void
    {
        foreach ([ExecutionProfile::Unknown] as $profile) {
            self::assertSame($profile->metadata(), AgentResponse::success(new ExecutionResult(null, [], 1, 1, 'host-run', $profile))['runtime']);
            self::assertSame($profile->metadata(), AgentResponse::failure(new \RuntimeException('secret'), $profile)['runtime']);
        }
    }

    public function testTraceFileIsPrivateExclusiveBoundedAndChecksTheSubmittedCode(): void
    {
        $dir = sys_get_temp_dir().'/code-mode-trace-test-'.bin2hex(random_bytes(12));
        mkdir($dir, 0700);
        $path = $dir.'/trace.jsonl';
        try {
            $sink = new ExecutionTraceFile($path);
            self::assertSame(0600, fileperms($path) & 0777);
            $sink('return 1;', ['value' => 1]);
            self::assertSame(['value' => 1], ExecutionTraceFile::last($path, 'return 1;'));
            try { new ExecutionTraceFile($path); self::fail('Existing file overwritten.'); } catch (\RuntimeException) {}
            symlink($path, $dir.'/link');
            try { new ExecutionTraceFile($dir.'/link'); self::fail('Symlink followed.'); } catch (\RuntimeException) {}
            try { ExecutionTraceFile::last($path, 'different'); self::fail('Wrong PHP linked.'); } catch (\RuntimeException) {}
            try { $sink('return 2;', ['value' => str_repeat('x', 2 * 1024 * 1024)]); self::fail('Unbounded trace accepted.'); } catch (\RuntimeException) {}
            self::assertSame(['value' => 1], ExecutionTraceFile::last($path, 'return 1;'));
        } finally { unset($sink); if (is_link($dir.'/link')) { unlink($dir.'/link'); } unlink($path); rmdir($dir); }
    }

    private function session(SandboxExecutor $sandbox, int $attempts = 2): CodeModeSession
    {
        return new CodeModeSession(new ToolCatalog([new ToolDefinition('read', 'Read', (object) ['type' => 'object'])]), new ToolBroker([]), $sandbox, new ExecutionIdentity('alice'), new ExecutionLimits(), $attempts);
    }
}
