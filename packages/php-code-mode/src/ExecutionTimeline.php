<?php

declare(strict_types=1);

namespace CodeMode;

/** Opt-in, bounded host observations. Tool arguments can contain application data. */
final class ExecutionTimeline
{
    private readonly float $origin;
    private array $spans = [];

    public function __construct()
    {
        $this->origin = hrtime(true) / 1_000_000;
    }

    public function begin(string $stage, string $label, array $evidence = []): ?int
    {
        if (\count($this->spans) >= 512) {
            return null;
        }
        $id = \count($this->spans);
        $this->spans[] = ['id' => $id, 'stage' => $stage, 'label' => $label, 'startMs' => $this->now(), 'endMs' => null,
            'durationMs' => null, 'status' => 'incomplete', 'evidence' => $evidence];

        return $id;
    }

    public function end(?int $id, string $status = 'succeeded', array $evidence = []): void
    {
        if (null === $id || !isset($this->spans[$id]) || null !== $this->spans[$id]['endMs']) {
            return;
        }
        $this->spans[$id]['endMs'] = $this->now();
        $this->spans[$id]['durationMs'] = $this->spans[$id]['endMs'] - $this->spans[$id]['startMs'];
        $this->spans[$id]['status'] = $status;
        $this->spans[$id]['evidence'] += $evidence;
    }

    public function snapshot(): array
    {
        return $this->spans;
    }

    public function now(): float
    {
        return hrtime(true) / 1_000_000 - $this->origin;
    }
}
