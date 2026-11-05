<?php

declare(strict_types=1);

namespace App\Demo\Performance;

use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\Ai\ToolAuthorizationInterface;

final readonly class ReadPolicy implements ToolAuthorizationInterface
{
    public function __construct(private Dataset $data) {}
    public function isGranted(ExecutionIdentity $identity, string $tool, \stdClass $arguments): bool
    {
        if (!$identity->allows('performance.read_public')) { return false; }
        if ('perf_requests' === $tool) { return [] === get_object_vars($arguments); }
        return in_array($tool, ['perf_profile', 'perf_logs'], true)
            && ['requestId'] === array_keys(get_object_vars($arguments))
            && is_string($arguments->requestId) && $this->data->allowed($arguments->requestId);
    }
}
