<?php

declare(strict_types=1);

namespace CodeMode;

/** Host configuration, never an execution argument. Full preserves the original three-stage flow. */
enum DiscoveryMode: string
{
    case Full = 'full';
    case Compact = 'compact';
}
