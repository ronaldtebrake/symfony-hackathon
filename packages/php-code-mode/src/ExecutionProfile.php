<?php

declare(strict_types=1);

namespace CodeMode;

/** Host configuration and provenance; never a capability supplied by generated PHP. */
enum ExecutionProfile: string
{
    case Gvisor = 'gvisor';
    case Unknown = 'unknown';

    public function metadata(): array
    {
        return match ($this) {
            self::Gvisor => ['executor' => $this->value, 'isolation' => 'gvisor', 'label' => 'gVisor sandbox', 'warning' => null],
            self::Unknown => ['executor' => $this->value, 'isolation' => 'unknown', 'label' => 'Unspecified executor', 'warning' => 'No isolation claim is available for this executor.'],
        };
    }
}
