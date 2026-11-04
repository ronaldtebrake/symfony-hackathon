<?php

declare(strict_types=1);

namespace Adoption;

use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\Ai\ToolAuthorizationInterface;

final readonly class ReadPolicy implements ToolAuthorizationInterface
{
    public function __construct(private ArticleCatalog $articles) {}

    public function isGranted(ExecutionIdentity $identity, string $tool, \stdClass $arguments): bool
    {
        return $identity->allows('publication.read_published')
            && 'publication_read' === $tool
            && ['id'] === array_keys(get_object_vars($arguments))
            && is_int($arguments->id)
            && $this->articles->isPublished($arguments->id);
    }
}
