<?php

declare(strict_types=1);

namespace CodeMode;

/** Presentation only. The session, broker and executor retain all enforcement. */
final class AgentResponse
{
    /** @param ?\Closure(string, array): void $record Trusted host observer; never sent to the agent. */
    public static function execute(CodeModeSession $session, string $code, ?\Closure $record = null): array
    {
        try {
            $result = $session->execute($code);
        } catch (\Throwable $error) {
            $record?->__invoke($code, self::failureTrace($error, $session->profile()));
            throw $error;
        }
        $record?->__invoke($code, self::successTrace($result));
        return self::success($result);
    }

    public static function success(ExecutionResult $result): array
    {
        return ['value' => $result->value] + self::runtimeWarning($result->profile);
    }

    public static function failure(\Throwable $error, ExecutionProfile $profile): array
    {
        $failure = ['reason' => $error instanceof SandboxException ? $error->reason : 'tool_failed'];
        if ($error instanceof SandboxException) {
            // Identify the failed operation without sending arguments, upstream prose or earlier results.
            $last = $error->toolCalls[array_key_last($error->toolCalls)] ?? null;
            if (is_array($last) && in_array($last['status'] ?? null, ['denied', 'incomplete'], true)) {
                $failure['tool'] = $last['name'];
            }
        }
        return ['error' => $failure] + self::runtimeWarning($profile);
    }

    public static function successTrace(ExecutionResult $result): array
    {
        return ['value' => $result->value, 'toolCalls' => $result->toolCalls, 'durationMs' => $result->durationMs,
            'runId' => $result->runId, 'runtime' => $result->profile->metadata()];
    }

    public static function failureTrace(\Throwable $error, ExecutionProfile $profile): array
    {
        return ['error' => ['reason' => $error instanceof SandboxException ? $error->reason : 'tool_failed'],
            'toolCalls' => $error instanceof SandboxException ? $error->toolCalls : [],
            'runId' => $error instanceof SandboxException ? $error->runId : null, 'runtime' => $profile->metadata()];
    }

    private static function runtimeWarning(ExecutionProfile $profile): array
    {
        // Never optimize away a warning about unsandboxed or unknown execution.
        return ExecutionProfile::Gvisor === $profile ? [] : ['runtime' => $profile->metadata()];
    }
}
