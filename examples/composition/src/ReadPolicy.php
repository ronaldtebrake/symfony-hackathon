<?php

declare(strict_types=1);

namespace Composition;

use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\Ai\ToolAuthorizationInterface;

final class ReadPolicy implements ToolAuthorizationInterface
{
    public function isGranted(ExecutionIdentity $identity, string $name, \stdClass $arguments): bool
    {
        if ('composition-reader' !== $identity->subject || !$identity->allows('content.audit')) {
            return false;
        }
        if ('cms_list_articles' === $name) {
            return [] === array_diff(array_keys(get_object_vars($arguments)), ['offset', 'limit'])
                && \is_int($arguments->offset ?? null) && $arguments->offset >= 0 && $arguments->offset <= 1000
                && \is_int($arguments->limit ?? null) && $arguments->limit >= 1 && $arguments->limit <= 12;
        }
        $max = match ($name) {
            'cms_get_articles' => 48, 'cms_get_media' => 24, default => 0,
        };
        $ids = $arguments->ids ?? null;

        return $max > 0 && ['ids'] === array_keys(get_object_vars($arguments)) && \is_array($ids) && array_is_list($ids)
            && \count($ids) >= 1 && \count($ids) <= 12 && \count(array_unique($ids, \SORT_REGULAR)) === \count($ids)
            && [] === array_filter($ids, static fn ($id) => !\is_int($id) || $id < 1 || $id > $max);
    }
}
