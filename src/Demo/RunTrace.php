<?php

declare(strict_types=1);

namespace App\Demo;

final class RunTrace
{
    public array $events = [];
    public array $brokerCalls = [];
    public array $programs = [];
    public array $executions = [];
    public array $agentCalls = [];
    public array $requestEvidence = [];
    public array $providerResponses = [];
    public ?array $review = null;
    public bool $captureDetails = false;
    public int $toolPayloadBytes = 0;
    public int $modelRequests = 0;
    public int $agentToolCalls = 0;
    public mixed $report = null;

    public function __construct(public readonly ?\CodeMode\ExecutionTimeline $timeline = null)
    {
    }

    public static function tokens(array $responses, int $requestCount): ?array
    {
        if (0 === $requestCount || \count($responses) !== $requestCount) {
            return null;
        }
        $totals = ['prompt' => 0, 'completion' => 0, 'total' => 0];
        foreach ($responses as $row) {
            $u = $row['tokens'] ?? [];
            foreach ($totals as $key => $sum) {
                if (!\is_int($u[$key] ?? null) || $u[$key] < 0) {
                    return null;
                }
                $totals[$key] += $u[$key];
            }
            if ($u['prompt'] + $u['completion'] !== $u['total']) {
                return null;
            }
        }

        return $totals;
    }

    public function record(string $stage, string $label, array $details = []): void
    {
        $this->events[] = ['stage' => $stage, 'label' => $label, 'details' => $details];
    }

    public function captureCall(string $name, array $arguments, string $result): void
    {
        if ($this->captureDetails) {
            // Capture only tool-level data, never HTTP headers, credentials or provider debug output.
            $this->agentCalls[] = ['name' => $name, 'arguments' => $arguments, 'result' => $result, 'bytesToModel' => \strlen($result)];
        }
    }
}
