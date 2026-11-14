<?php

declare(strict_types=1);

namespace App\Demo;

use CodeMode\CodeModeSession;
use CodeMode\ToolCatalog;

final readonly class DemoContext
{
    public function __construct(public AuditToolbox $toolbox, public CodeModeSession $session, public ToolCatalog $catalog, public RunTrace $trace)
    {
    }
}
