<?php

declare(strict_types=1);

namespace App\Demo\Performance;

use Opis\JsonSchema\{SchemaLoader, Validator};

/** Operator-selected public fixture replay. No new traffic or remeasured timing data. */
final readonly class FrozenDataset implements ReadDataset
{
    public function __construct(private array $snapshot)
    {
        $ids = ['profile-01', 'profile-02', 'profile-03'];
        if (array_keys($snapshot) !== ['requests', 'profiles', 'logs'] || array_keys($snapshot['profiles']) !== $ids
            || array_keys($snapshot['logs']) !== $ids || str_contains(json_encode($snapshot, JSON_THROW_ON_ERROR), RequestCollector::CANARY)) {
            throw new \InvalidArgumentException('Only the public performance fixture can be replayed.');
        }
        $validator = new Validator(new SchemaLoader());
        $valid = static fn ($value, $schema) => $validator->validate(json_decode(json_encode($value, JSON_THROW_ON_ERROR)), json_decode(json_encode($schema, JSON_THROW_ON_ERROR)))->isValid();
        if (!$valid($snapshot['requests'], ReadTools::INDEX_SCHEMA) || array_column($snapshot['requests']['items'], 'requestId') !== $ids) {
            throw new \InvalidArgumentException('Invalid public request index.');
        }
        foreach ($ids as $i => $id) {
            $profile = $snapshot['profiles'][$id]; $logs = $snapshot['logs'][$id];
            if (!$valid($profile, ReadTools::PROFILE_SCHEMA) || !$valid($logs, ReadTools::LOG_SCHEMA)
                || $profile['requestId'] !== $id || $logs['requestId'] !== $id
                || array_intersect_key($profile, array_flip(['requestId', 'route', 'variant', 'durationMs', 'queryCount'])) !== $snapshot['requests']['items'][$i]) {
                throw new \InvalidArgumentException('Invalid public profile projection.');
            }
        }
    }

    public function allowed(string $id): bool { return isset($this->snapshot['profiles'][$id]); }
    public function requests(): array { return $this->snapshot['requests']; }
    public function profile(string $id): array
    {
        if (!$this->allowed($id)) { throw new \RuntimeException('No permitted profile.'); }
        return $this->snapshot['profiles'][$id];
    }
    public function logs(string $id): array
    {
        if (!$this->allowed($id)) { throw new \RuntimeException('No permitted profile.'); }
        return $this->snapshot['logs'][$id];
    }
}
