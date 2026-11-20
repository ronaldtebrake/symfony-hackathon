<?php

declare(strict_types=1);

use App\Demo\Refund\ProtocolProof;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
if (['--verify'] === array_slice($argv, 1)) {
    ProtocolProof::verify(json_decode(file_get_contents($root.'/docs/evidence/refund-protocol.json'), true, flags: \JSON_THROW_ON_ERROR), $root);
    echo "Recorded refund exchanges verified. The order and policy reads succeeded. After revocation, the policy read was denied.\n";

    return;
}
if (3 !== $argc || '--live' !== $argv[1]) {
    fwrite(\STDERR, "Usage: php scripts/check-refund-protocol.php --verify | --live /path/to/pinned-skills-sdk\n");
    exit(2);
}
$report = ProtocolProof::record($root, $argv[2]);
$json = json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
ProtocolProof::verify(json_decode($json, true, flags: \JSON_THROW_ON_ERROR), $root, live: true);
echo $json."\n";
