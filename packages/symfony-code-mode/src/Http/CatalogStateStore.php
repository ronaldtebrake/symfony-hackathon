<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Http;

use CodeMode\SandboxException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;

/** Persistent per-principal budgets. MCP session IDs cannot mint fresh attempts. */
final readonly class CatalogStateStore
{
    public function __construct(private CacheItemPoolInterface $cache, private LockFactory $locks) {}

    /** The lock covers read/reservation/dispatch; checkpoints are saved before any handler runs. */
    public function withState(string $subject, \Closure $operation): mixed
    {
        $key = 'catalog.'.hash('sha256', $subject);
        $lock = $this->locks->createLock($key, null);
        if (!$lock->acquire()) { throw new SandboxException('execution_busy', 'Another request owns this catalog budget.'); }
        try {
            $item = $this->cache->getItem($key);
            $state = $item->isHit() ? $item->get() : [];
            if (!is_array($state)) { throw new SandboxException('state_unavailable', 'Invalid host state.'); }
            $save = function (array $state) use ($item): void {
                $item->set($state)->expiresAfter(null);
                if (!$this->cache->save($item)) { throw new SandboxException('state_unavailable', 'Cannot reserve the catalog budget.'); }
            };
            return $operation($state, $save);
        } finally { $lock->release(); }
    }

    /** Operator action only: no tool or HTTP endpoint exposes this method. */
    public function reset(string $subject): void
    {
        $this->withState($subject, function () use ($subject): void {
            if (!$this->cache->deleteItem('catalog.'.hash('sha256', $subject))) { throw new \RuntimeException('Cannot reset the catalog budget.'); }
        });
    }
}
