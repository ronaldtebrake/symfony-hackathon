<?php

declare(strict_types=1);

namespace Adoption;

use CodeMode\ExecutionIdentity;
use CodeMode\ToolVisibilityInterface;

/** Capability visibility only; ReadPolicy still decides access to each article. */
final class PublicationVisibility implements ToolVisibilityInterface
{
    public function isVisible(ExecutionIdentity $identity, string $tool): bool
    {
        return 'publication_read' === $tool && $identity->allows('publication.read_published');
    }
}
