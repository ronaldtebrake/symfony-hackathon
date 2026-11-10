<?php

declare(strict_types=1);

namespace CodeMode;

/** Results are application data. The ledger is host evidence, never an agent-supplied claim. */
final readonly class BatchResult
{
    public function __construct(public array $results, public array $toolCalls, public float $durationMs, public int $outputBytes, public string $runId) {}

    public function response(): array { return ['results' => $this->results]; }

    public static function runtime(): array
    {
        return ['executor' => 'json-batch', 'generatedCode' => false, 'label' => 'Approved application calls',
            'execution' => 'in-process', 'deadlineEnforcement' => 'cooperative', 'hardTermination' => false];
    }

    public function trace(): array
    {
        return $this->response() + ['toolCalls' => $this->toolCalls, 'durationMs' => $this->durationMs,
            'outputBytes' => $this->outputBytes, 'runId' => $this->runId, 'runtime' => self::runtime()];
    }

    public static function failure(\Throwable $error): array
    {
        $calls = $error instanceof SandboxException ? $error->toolCalls : [];
        $failure = ['reason' => $error instanceof SandboxException ? $error->reason : 'tool_failed'];
        $last = $calls[array_key_last($calls)] ?? null;
        if (null !== $last && 'succeeded' !== $last['status']) {
            $failure += ['tool' => $last['name'], 'index' => array_key_last($calls)];
        }
        return ['error' => $failure, 'completedCalls' => count(array_filter($calls, static fn ($call) => 'succeeded' === $call['status']))];
    }

    public static function failureTrace(\Throwable $error): array
    {
        return self::failure($error) + ['toolCalls' => $error instanceof SandboxException ? $error->toolCalls : [],
            'runId' => $error instanceof SandboxException ? $error->runId : null, 'runtime' => self::runtime()];
    }
}
