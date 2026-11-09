<?php

declare(strict_types=1);

namespace CodeMode\Provider;

/** Trusted host registration. Providers expose only explicitly approved operations. */
interface ToolProviderInterface
{
    public function name(): string;
    /** A non-sensitive adapter label for host traces, never a credential or endpoint. */
    public function source(): string;
    /** @return iterable<ToolBinding> */
    public function tools(): iterable;
}
