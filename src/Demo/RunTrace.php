<?php

declare(strict_types=1);

namespace App\Demo;

final class RunTrace
{
    public function __construct(public readonly ?\CodeMode\ExecutionTimeline $timeline = null) {}
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

    public function record(string $stage, string $label, array $details = []): void
    {
        $this->events[] = ['stage' => $stage, 'label' => $label, 'details' => $details];
    }

    public function captureCall(string $name, array $arguments, string $result): void
    {
        if ($this->captureDetails) {
            // Capture only tool-level data, never HTTP headers, credentials or provider debug output.
            $this->agentCalls[] = ['name' => $name, 'arguments' => $arguments, 'result' => $result, 'bytesToModel' => strlen($result)];
        }
    }
}
