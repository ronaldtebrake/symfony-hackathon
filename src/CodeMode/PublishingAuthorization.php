<?php

declare(strict_types=1);

namespace App\CodeMode;

use App\Demo\AuditDataset;
use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\Ai\ToolAuthorizationInterface;

final class PublishingAuthorization implements ToolAuthorizationInterface
{
    public function isGranted(ExecutionIdentity $identity, string $tool, \stdClass $arguments): bool
    {
        if (!$identity->allows('articles.read_published')) { return false; }
        return match ($tool) {
            'publishing_read' => is_int($arguments->id ?? null) && 'published' === ((new AuditDataset())->articles()[$arguments->id]['status'] ?? null),
            'publishing_list' => is_int($arguments->page ?? null) && $arguments->page >= 1 && $arguments->page <= 4,
            default => false,
        };
    }
}
