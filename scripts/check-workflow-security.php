<?php

declare(strict_types=1);

use App\Demo\Skills\WorkflowSecurityProof;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
if (2 !== $argc) {
    fwrite(\STDERR, "Usage: php scripts/check-workflow-security.php /path/to/pinned-draft-sdk\n");
    exit(2);
}
echo json_encode(WorkflowSecurityProof::record($root, $argv[1]), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
