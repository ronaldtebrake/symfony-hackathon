<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Profiler;

use CodeMode\ToolDefinition;
use Symfony\Contracts\Service\ResetInterface;

/** Bounded request-local diagnostics. Tool definitions are separate from handler inputs and results. */
final class CatalogTrace implements ResetInterface
{
    private array $operations = [];
    private array $localContracts = [];
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
            $returned = (int) ($value['returned'] ?? \count($value['tools'] ?? []));
            $row['discovery'] = ['matched' => (int) ($value['matched'] ?? $returned), 'returned' => $returned,
                'truncated' => (bool) ($value['truncated'] ?? false), 'toolDefinitions' => $this->returnedContracts($value['tools'] ?? [])];
        }
        $this->active = null;
    }

    public function snapshot(): array
    {
        return ['operations' => $this->operations, 'dropped' => $this->dropped];
    }

    /** Record only group metadata the host projected from its own registry for this search response. */
    public function catalogGroups(array $summaries, ?string $selectedGroup = null, ?string $instructions = null): void
    {
        if (null === $this->active || 'search_tools' !== $this->operations[$this->active]['operation']) {
            return;
        }
        $groups = ['groupSummaries' => \array_slice($summaries, 0, 8), 'selectedGroup' => $selectedGroup];
        if (null !== $instructions && \strlen($instructions) <= 4096) {
            $groups['instructions'] = $instructions;
        }
        $this->operations[$this->active]['groupDiscovery'] = $groups;
    }

    /** Register only metadata owned by installed handlers. The registry itself is never exported. */
    public function registerLocalContract(ToolDefinition $definition): void
    {
        if (\count($this->localContracts) < 128 || isset($this->localContracts[$definition->name])) {
            $this->localContracts[$definition->name] = hash('sha256', json_encode($definition, \JSON_THROW_ON_ERROR));
        }
    }

    /** Record the group's public labels and the contracts that the successful MCP response disclosed. */
    public function discoveryPage(string $method, int $groups, int $tools, float $durationMs, array $groupSummaries = [], ?string $selectedGroup = null, array $definitions = [], ?string $reviewedInstructions = null): void
    {
        $this->begin($method);
        if (null !== $this->active) {
            $this->operations[$this->active]['page'] = ['groups' => $groups, 'tools' => $tools, 'groupSummaries' => $groupSummaries,
                'selectedGroup' => $selectedGroup, 'toolDefinitions' => $this->returnedContracts($definitions)];
            if (null !== $reviewedInstructions && \strlen($reviewedInstructions) <= 4096) {
                $this->operations[$this->active]['page']['instructions'] = $reviewedInstructions;
            }
        }
        $this->finish([], false, $durationMs);
    }

    public function reset(): void
    {
        $this->operations = [];
        $this->localContracts = [];
        $this->active = null;
        $this->dropped = 0;
    }

    private static function name(string $value): string
    {
        return preg_match('/\A[a-zA-Z][a-zA-Z0-9_.-]{0,63}\z/D', $value) ? $value : '[redacted]';
    }

    private function returnedContracts(array $definitions): array
    {
        $visible = [];
        $bytes = 0;
        foreach (\array_slice($definitions, 0, 8) as $definition) {
            $tool = (array) $definition;
            $name = $tool['name'] ?? null;
            if (!\is_string($name) || !isset($this->localContracts[$name], $tool['description'], $tool['inputSchema'])) {
                continue;
            }
            $contract = ['name' => $name, 'description' => $tool['description'], 'inputSchema' => $tool['inputSchema']];
            if (isset($tool['outputSchema'])) {
                $contract['outputSchema'] = $tool['outputSchema'];
            }
            $json = json_encode($contract, depth: 40);
            if (false !== $json && $bytes + \strlen($json) <= 32768 && hash('sha256', $json) === $this->localContracts[$name]) {
                $visible[] = (array) json_decode($json, depth: 40, flags: \JSON_THROW_ON_ERROR);
                $bytes += \strlen($json);
            }
        }

        return $visible;
    }
}
