<?php

declare(strict_types=1);

namespace App\Demo\Performance;

/** Allowlisted projections only. Raw headers, SQL, parameters, bodies and log context never leave the host. */
final readonly class Dataset implements ReadDataset
{
    private array $profiles;
    private array $events;

    public function __construct(array $records)
    {
        if (count($records) > 8) { throw new \InvalidArgumentException('Too many captured requests.'); }
        $profiles = $events = [];
        foreach ($records as $id => $record) {
            if (true !== ($record['approved'] ?? false)) { continue; }
            if (count($record['profile']['queries']) > 32 || count($record['profile']['logs']) > 16) {
                throw new \InvalidArgumentException('Captured request exceeds its bounds.');
            }
            if (!in_array($record['profile']['route'], ['catalog_naive', 'catalog_joined', 'health'], true)
                || !in_array($record['variant'], ['before', 'after', 'control'], true)) { throw new \InvalidArgumentException('Unknown public fixture route.'); }
            $profiles[$id] = $this->projectProfile($id, $record);
            $events[$id] = $this->projectLogs($id, $record['profile']['logs']);
        }
        // Only these projections survive into the broker; restricted records and raw fields are discarded.
        $this->profiles = $profiles;
        $this->events = $events;
    }

    public function allowed(string $id): bool { return isset($this->profiles[$id]); }

    public function requests(): array
    {
        $items = [];
        foreach ($this->profiles as $profile) {
            $items[] = array_intersect_key($profile, array_flip(['requestId', 'route', 'variant', 'durationMs', 'queryCount']));
        }
        return ['items' => $items];
    }

    public function profile(string $id): array
    {
        if (!$this->allowed($id)) { throw new \RuntimeException('No permitted profile.'); }
        return $this->profiles[$id];
    }

    public function logs(string $id): array
    {
        if (!$this->allowed($id)) { throw new \RuntimeException('No permitted profile.'); }
        return $this->events[$id];
    }

    private function projectProfile(string $id, array $record): array
    {
        $data = $record['profile'];
        $groups = [];
        foreach ($data['queries'] as $query) {
            $name = $query['fingerprint'];
            if (!in_array($name, ['catalog_list', 'author_by_id', 'catalog_join'], true)) { throw new \RuntimeException('Unapproved query fingerprint.'); }
            $groups[$name] ??= ['fingerprint' => $name, 'count' => 0, 'durationMs' => 0.0];
            ++$groups[$name]['count'];
            $groups[$name]['durationMs'] += $query['durationMs'];
        }
        return ['requestId' => $id, 'route' => $data['route'], 'variant' => $record['variant'], 'durationMs' => $data['durationMs'],
            'queryCount' => array_sum(array_column($groups, 'count')), 'queryTimeMs' => array_sum(array_column($groups, 'durationMs')),
            'queries' => array_values($groups), 'outputSha256' => $data['outputSha256']];
    }

    private function projectLogs(string $id, array $logs): array
    {
        $events = [];
        foreach ($logs as $log) {
            if (!in_array($log['message'], ['authors.loaded_individually', 'catalog.rendered', 'health.ok'], true)
                || !in_array($log['level'], ['info', 'warning'], true)) { continue; }
            $count = $log['context']['count'] ?? null;
            if (!is_int($count) || $count < 0 || $count > 100) { continue; }
            $events[] = ['event' => $log['message'], 'level' => $log['level'], 'count' => $count];
        }
        return ['requestId' => $id, 'events' => $events];
    }

}
