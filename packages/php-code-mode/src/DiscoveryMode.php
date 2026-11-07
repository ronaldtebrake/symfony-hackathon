<?php

declare(strict_types=1);

namespace CodeMode;

/** Discovery returns complete schemas for explicitly selected tools. */
enum DiscoveryMode: string
{
    case Full = 'full';
}
