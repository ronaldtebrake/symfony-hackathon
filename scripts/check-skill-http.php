<?php

declare(strict_types=1);

use App\Demo\Skills\AccessProof;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
if (2 !== $argc) {
    fwrite(\STDERR, "Usage: php scripts/check-skill-http.php /path/to/pinned-draft-sdk\n");
    exit(2);
}
$capture = AccessProof::record($root, $argv[1]);
AccessProof::verify($capture, file_get_contents($root.'/examples/symfony/skills/read-published-article/SKILL.md'));
echo json_encode($capture, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
