<?php

declare(strict_types=1);

namespace App\Demo\Performance;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Tool\ToolEffectHints;

#[AsTool('perf_requests', 'List approved synthetic Symfony requests in items. Compare baseline (before/control) durations, then select a requestId for the other readers.', method: 'requests', effectHints: new ToolEffectHints(readOnlyHint: true), metadata: ['code_mode.output_schema' => self::INDEX_SCHEMA])]
#[AsTool('perf_profile', 'Read an approved request by requestId from perf_requests. Returns query fingerprint counts and timings, never SQL or parameters. Timings include 2 ms simulated latency per query.', method: 'profile', effectHints: new ToolEffectHints(readOnlyHint: true), metadata: ['code_mode.output_schema' => self::PROFILE_SCHEMA])]
#[AsTool('perf_logs', 'Read allowlisted log events correlated by an approved requestId. Raw messages, headers and log context are unavailable.', method: 'logs', effectHints: new ToolEffectHints(readOnlyHint: true), metadata: ['code_mode.output_schema' => self::LOG_SCHEMA])]
final readonly class ReadTools
{
    public const NAMES = ['perf_requests', 'perf_profile', 'perf_logs'];
    private const ID = ['type' => 'string', 'pattern' => '^[a-z][a-z0-9-]{0,31}$'];
    private const TIME = ['type' => 'number', 'minimum' => 0];
    private const COUNT = ['type' => 'integer', 'minimum' => 0, 'maximum' => 32];
    private const SUMMARY = ['requestId' => self::ID, 'route' => ['enum' => ['catalog_naive', 'catalog_joined', 'health']],
        'variant' => ['enum' => ['before', 'after', 'control']], 'durationMs' => self::TIME, 'queryCount' => self::COUNT];
    public const INDEX_SCHEMA = ['type' => 'object', 'additionalProperties' => false, 'required' => ['items'], 'properties' => [
        'items' => ['type' => 'array', 'maxItems' => 8, 'items' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['requestId', 'route', 'variant', 'durationMs', 'queryCount'], 'properties' => self::SUMMARY]]]];
    public const PROFILE_SCHEMA = ['type' => 'object', 'additionalProperties' => false,
        'required' => ['requestId', 'route', 'variant', 'durationMs', 'queryCount', 'queryTimeMs', 'queries', 'outputSha256'], 'properties' => [
            ...self::SUMMARY, 'queryTimeMs' => self::TIME, 'outputSha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            'queries' => ['type' => 'array', 'maxItems' => 32, 'items' => ['type' => 'object', 'additionalProperties' => false,
                'required' => ['fingerprint', 'count', 'durationMs'], 'properties' => ['fingerprint' => ['enum' => ['catalog_list', 'author_by_id', 'catalog_join']], 'count' => self::COUNT, 'durationMs' => self::TIME]]]]];
    public const LOG_SCHEMA = ['type' => 'object', 'additionalProperties' => false, 'required' => ['requestId', 'events'], 'properties' => [
        'requestId' => self::ID, 'events' => ['type' => 'array', 'maxItems' => 16, 'items' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['event', 'level', 'count'], 'properties' => ['event' => ['enum' => ['authors.loaded_individually', 'catalog.rendered', 'health.ok']],
                'level' => ['enum' => ['info', 'warning']], 'count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100]]]]]];
    public function __construct(private Dataset $data) {}
    public function requests(): array { return $this->data->requests(); }
    public function profile(string $requestId): array { return $this->data->profile($requestId); }
    public function logs(string $requestId): array { return $this->data->logs($requestId); }
}
