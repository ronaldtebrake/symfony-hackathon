<?php

declare(strict_types=1);

namespace CodeMode;

/** Docker attachment transport. Container cleanup is owned by GvisorSandbox. @internal */
final class DockerSession extends ProcessSession
{
    public function __construct(array $command, array $environment)
    {
        parent::__construct($command, $environment, failureReason: 'docker_failed');
    }
}
