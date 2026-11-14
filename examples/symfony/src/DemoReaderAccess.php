<?php

declare(strict_types=1);

namespace Adoption;

use Symfony\Component\Filesystem\Filesystem;

/** Local recording fixture. Production apps load grants from their own user store. */
final readonly class DemoReaderAccess
{
    public function __construct(private string $path) {}

    public function isGranted(): bool
    {
        // Missing, unreadable or malformed state cannot grant the demo role.
        return "granted\n" === @file_get_contents($this->path);
    }

    public function setGranted(bool $granted): void
    {
        (new Filesystem())->dumpFile($this->path, $granted ? "granted\n" : "revoked\n");
    }
}
