<?php

declare(strict_types=1);

namespace Adoption;

/** Synthetic data owned by this app; no dependency on the hackathon's audit fixture. */
final class ArticleCatalog
{
    private const ARTICLES = [
        101 => ['title' => 'A Symfony application owns its tools', 'published' => true],
        202 => ['title' => 'PRIVATE_FIXTURE_DO_NOT_RETURN', 'published' => false],
    ];

    public function isPublished(int $id): bool
    {
        return true === (self::ARTICLES[$id]['published'] ?? false);
    }

    /** @return array{id: int, title: string} */
    public function readPublished(int $id): array
    {
        // Recheck at the data boundary; an allowlist or tool annotation is not access control.
        if (!$this->isPublished($id)) {
            throw new \RuntimeException('No permitted article.');
        }

        return ['id' => $id, 'title' => self::ARTICLES[$id]['title']];
    }
}
