<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Profiler;

use Symfony\Contracts\Service\ResetInterface;

/** Bounded request-local diagnostics. Never stores identities, arguments, results or exception messages. */
final class CatalogTrace implements ResetInterface
{
    private array $operations = [];
    private ?int $active = null;
    private int $dropped = 0;

    public function begin(string $operation): void
    {
        if (\count($this->operations) >= 32) {
            $this->active = null;
            ++$this->dropped;

            return;
        }
        $this->operations[] = ['operation' => $operation, 'status' => 'running', 'budget' => [], 'calls' => [], 'nativeEvents' => []];
        $this->active = array_key_last($this->operations);
    }

    public function budget(array $state, string $phase): void
    {
        if (null === $this->active || \count($this->operations[$this->active]['budget']) >= 4) {
            return;
        }
        $this->operations[$this->active]['budget'][] = ['phase' => $phase,
            'attempts' => (int) ($state['executions'] ?? 0), 'reservedCalls' => (int) ($state['toolCalls'] ?? 0),
            'knownContracts' => \count($state['described'] ?? [])];
    }

    public function ledger(array $trace): void
    {
        if (null === $this->active) {
            return;
        }
        foreach (\array_slice($trace['toolCalls'] ?? [], 0, 100) as $call) {
            $row = ['name' => self::name($call['name'] ?? ''), 'status' => self::name($call['status'] ?? 'unknown')];
            if (isset($call['reason'])) {
                $row['reason'] = self::name($call['reason']);
            }
            if (isset($call['durationMs'])) {
                $row['durationMs'] = round((float) $call['durationMs'], 3);
            }
            $this->operations[$this->active]['calls'][] = $row;
        }
    }

    public function nativeEvent(string $name, string $phase): void
    {
        if (null === $this->active) {
            return;
        }
        if (\count($this->operations[$this->active]['nativeEvents']) >= 400) {
            ++$this->dropped;

            return;
        }
        $this->operations[$this->active]['nativeEvents'][] = ['name' => self::name($name), 'phase' => $phase];
    }

    public function finish(array $value, bool $failed, float $durationMs): void
    {
        if (null === $this->active) {
            return;
        }
        $row = &$this->operations[$this->active];
        $row['status'] = $failed ? 'failed' : 'succeeded';
        $row['durationMs'] = round($durationMs, 3);
        if ($failed) {
            $row['reason'] = self::name($value['error']['reason'] ?? 'tool_failed');
        }
        if ('search_tools' === $row['operation'] && !$failed) {
            $row['discovery'] = ['matched' => (int) ($value['matched'] ?? 0), 'returned' => (int) ($value['returned'] ?? 0),
                'truncated' => (bool) ($value['truncated'] ?? false)];
        }
        $this->active = null;
    }

    public function snapshot(): array
    {
        return ['operations' => $this->operations, 'dropped' => $this->dropped];
    }

    public function reset(): void
    {
        $this->operations = [];
        $this->active = null;
        $this->dropped = 0;
    }

    private static function name(string $value): string
    {
        return preg_match('/\A[a-zA-Z][a-zA-Z0-9_.-]{0,63}\z/D', $value) ? $value : '[redacted]';
    }
}
