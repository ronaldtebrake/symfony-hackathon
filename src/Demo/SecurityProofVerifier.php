<?php

declare(strict_types=1);

namespace App\Demo;

/** Checks host observations. A guest's claimed outcome cannot pass a proof. */
final class SecurityProofVerifier
{
    public function verify(string $case, mixed $value, ?string $reason, array $calls, ?bool $removed): bool
    {
        if (true !== $removed) {
            return false;
        }
        $read = 1 === count($calls) && 'cms_list_articles' === ($calls[0]['name'] ?? null) && 'succeeded' === ($calls[0]['status'] ?? null);
        return match ($case) {
            'authorized_read' => null === $reason && 48 === $value && $read,
            'unauthorized_call' => 'tool_denied' === $reason && 1 === count($calls)
                && 'cms_delete_article' === ($calls[0]['name'] ?? null) && 'denied' === ($calls[0]['status'] ?? null)
                && 'tool_denied' === ($calls[0]['reason'] ?? null),
            'infinite_loop' => 'wall_timeout' === $reason && $read,
            default => false,
        };
    }
}
