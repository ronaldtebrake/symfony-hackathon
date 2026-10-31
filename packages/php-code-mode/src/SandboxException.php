<?php

declare(strict_types=1);

namespace CodeMode;

final class SandboxException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
