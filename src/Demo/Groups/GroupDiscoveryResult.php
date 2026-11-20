<?php

declare(strict_types=1);

namespace App\Demo\Groups;

use Mcp\Schema\Enum\CacheScope;
use Mcp\Schema\JsonRpc\ResultInterface;

/** Both Groups methods return identity-dependent discovery data. */
final readonly class GroupDiscoveryResult implements ResultInterface
{
    public function __construct(private array $contents)
    {
    }

    public function jsonSerialize(): array
    {
        return $this->contents + ['cacheScope' => CacheScope::Private->value, 'ttlMs' => 0];
    }
}
