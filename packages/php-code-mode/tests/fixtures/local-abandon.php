<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use CodeMode\{ExecutionIdentity, ExecutionLimits, LocalUnsafeExecutor, ToolBroker};

$marker = $argv[1];
// Write the witness directly: a broker call could still be waiting for a reply when the host dies.
$program = 'file_put_contents('.var_export($marker, true).', json_encode(["pid" => getmypid(), "directory" => getcwd()])); sleep(60);';
(new LocalUnsafeExecutor())->execute($program, new ToolBroker([]), new ExecutionIdentity('test'), [], new ExecutionLimits(wallTimeMs: 1000));
