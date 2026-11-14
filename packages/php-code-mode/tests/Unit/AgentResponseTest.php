<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\AgentResponse;
use CodeMode\CodeModeSession;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\ExecutionProfile;
use CodeMode\ExecutionResult;
use CodeMode\ExecutionTraceFile;
use CodeMode\SandboxException;
use CodeMode\SandboxExecutor;
use CodeMode\ToolBroker;
use CodeMode\ToolCatalog;
use CodeMode\ToolDefinition;
use PHPUnit\Framework\TestCase;

final class AgentResponseTest extends TestCase
{
    public function testHostEvidenceStaysSeparateFromUntrustedReturnValue(): void
    {
        $value = ['runtime' => 'forged', 'toolCalls' => ['forged'], 'runId' => 'forged'];
        $result = new ExecutionResult($value, [['name' => 'read', 'status' => 'succeeded']], 12, 42, 'host-run', ExecutionProfile::Gvisor);
        $sandbox = $this->createMock(SandboxExecutor::class);
        $sandbox->expects($this->once())->method('execute')->with('return 1;', $this->isInstanceOf(ToolBroker::class), $this->callback(static fn ($id) => 'alice' === $id->subject), ['read'], $this->callback(static fn ($limits) => 10 === $limits->maxToolCalls))->willReturn($result);
        $session = $this->session($sandbox);
        $session->describe(['read']);
        $records = [];
        $response = AgentResponse::execute($session, 'return 1;', static function ($code, $trace) use (&$records) { $records[] = [$code, $trace]; });
        $this->assertSame(['value' => $value], $response);
        $this->assertSame('host-run', $records[0][1]['runId']);
        $this->assertSame($result->toolCalls, $records[0][1]['toolCalls']);
        $this->assertSame(ExecutionProfile::Gvisor->metadata(), $records[0][1]['runtime']);
    }

    public function testDenialKeepsEarlierCallsOnHostAndConsumesTheSameBudget(): void
    {
        $calls = [['name' => 'read', 'status' => 'succeeded', 'resultBytes' => 55], ['name' => 'private_read', 'status' => 'denied', 'reason' => 'tool_denied']];
        $error = new SandboxException('tool_denied', 'SECRET exception', $calls, runId: 'host-run');
        $sandbox = $this->createMock(SandboxExecutor::class);
        $sandbox->expects($this->once())->method('execute')->willThrowException($error);
        $session = $this->session($sandbox, 1);
        $session->describe(['read']);
        $records = [];
        $record = static function ($code, $trace) use (&$records) { $records[] = $trace; };
        foreach (['tool_denied', 'execution_limit'] as $reason) {
            try {
                AgentResponse::execute($session, 'return 1;', $record);
                $this->fail('Execution should fail.');
            } catch (SandboxException $caught) {
                $this->assertSame($reason, $caught->reason);
            }
        }
        $this->assertSame(['error' => ['reason' => 'tool_denied', 'tool' => 'private_read']], AgentResponse::failure($error, ExecutionProfile::Gvisor));
        $this->assertSame($calls, $records[0]['toolCalls']);
        $this->assertSame([], $records[1]['toolCalls']);
        $this->assertNull($records[1]['runId']);
        $this->assertStringNotContainsString('SECRET', json_encode($records));
    }

    public function testUnknownProfileAlwaysKeepsItsWarning(): void
    {
        foreach ([ExecutionProfile::Unknown] as $profile) {
            $this->assertSame($profile->metadata(), AgentResponse::success(new ExecutionResult(null, [], 1, 1, 'host-run', $profile))['runtime']);
            $this->assertSame($profile->metadata(), AgentResponse::failure(new \RuntimeException('secret'), $profile)['runtime']);
        }
    }

    public function testTraceFileIsPrivateExclusiveBoundedAndChecksTheSubmittedCode(): void
    {
        $dir = sys_get_temp_dir().'/code-mode-trace-test-'.bin2hex(random_bytes(12));
        mkdir($dir, 0700);
        $path = $dir.'/trace.jsonl';
        try {
            $sink = new ExecutionTraceFile($path);
            $this->assertSame(0600, fileperms($path) & 0777);
            $sink('return 1;', ['value' => 1]);
            $this->assertSame(['value' => 1], ExecutionTraceFile::last($path, 'return 1;'));
            try {
                new ExecutionTraceFile($path);
                $this->fail('Existing file overwritten.');
            } catch (\RuntimeException) {
            }
            symlink($path, $dir.'/link');
            try {
                new ExecutionTraceFile($dir.'/link');
                $this->fail('Symlink followed.');
            } catch (\RuntimeException) {
            }
            try {
                ExecutionTraceFile::last($path, 'different');
                $this->fail('Wrong PHP linked.');
            } catch (\RuntimeException) {
            }
            try {
                $sink('return 2;', ['value' => str_repeat('x', 2 * 1024 * 1024)]);
                $this->fail('Unbounded trace accepted.');
            } catch (\RuntimeException) {
            }
            $this->assertSame(['value' => 1], ExecutionTraceFile::last($path, 'return 1;'));
        } finally {
            unset($sink);
            if (is_link($dir.'/link')) {
                unlink($dir.'/link');
            } unlink($path);
            rmdir($dir);
        }
    }

    private function session(SandboxExecutor $sandbox, int $attempts = 2): CodeModeSession
    {
        return new CodeModeSession(new ToolCatalog([new ToolDefinition('read', 'Read', (object) ['type' => 'object'])]), new ToolBroker([]), $sandbox, new ExecutionIdentity('alice'), new ExecutionLimits(), $attempts);
    }
}
