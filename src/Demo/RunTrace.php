<?php

declare(strict_types=1);

namespace App\Demo;

final class RunTrace
{
    public array $events = [];
    public array $brokerCalls = [];
    public array $programs = [];
    public int $toolPayloadBytes = 0;
    public int $modelRequests = 0;
    public int $agentToolCalls = 0;
    public mixed $report = null;

    public function record(string $stage, string $label, array $details = []): void
    {
        $this->events[] = ['stage' => $stage, 'label' => $label, 'details' => $details];
    }
}
