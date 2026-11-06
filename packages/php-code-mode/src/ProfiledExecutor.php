<?php

declare(strict_types=1);

namespace CodeMode;

/** Optional provenance for custom executors. This declaration is not a security attestation. */
interface ProfiledExecutor extends SandboxExecutor
{
    public function profile(): ExecutionProfile;
}
