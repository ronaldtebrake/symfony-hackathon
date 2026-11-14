<?php

declare(strict_types=1);

namespace CodeMode;

/** Rank only the supplied visible definitions. This interface grants no execution rights. */
interface CatalogSearchInterface
{
    /**
     * @param list<ToolDefinition> $tools
     *
     * @return list<string> matching names, most relevant first, before disclosure limits
     */
    public function search(string $query, array $tools): array;
}
