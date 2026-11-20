<?php

declare(strict_types=1);

use App\Demo\EditorialAuditReplay;
use App\Demo\EditorialGroupsProof;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

switch (array_slice($argv, 1)) {
    case ['--verify']:
        $capture = file_get_contents($root.'/docs/evidence/editorial-audit-replay.json');
        EditorialAuditReplay::verify(json_decode($capture, true, flags: \JSON_THROW_ON_ERROR));
        echo "Verified both archived editorial audit runs, the editor checkpoint and the denied media request.\n";
        break;
    case ['--verify-groups']:
        $capture = file_get_contents($root.'/docs/evidence/editorial-groups.json');
        EditorialGroupsProof::verify(json_decode($capture, true, flags: \JSON_THROW_ON_ERROR), $root);
        echo "Verified six archived Groups and catalog runs, including discovery cost and the denied reads.\n";
        break;
    default:
        fwrite(\STDERR, "Usage: php scripts/replay-editorial-audit.php --verify|--verify-groups\n");
        exit(2);
}
