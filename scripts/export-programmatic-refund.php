<?php

declare(strict_types=1);

use App\Demo\RefundRecording\ProgrammaticProof;

require dirname(__DIR__).'/vendor/autoload.php';

if (3 !== $argc) {
    fwrite(\STDERR, "Usage: php scripts/export-programmatic-refund.php <private-claude-session.jsonl> <symfony-wire.jsonl>\n");
    exit(2);
}
$read = static function (string $file): array {
    $lines = file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
    if (false === $lines) {
        throw new RuntimeException('Unable to open capture input.');
    }

    return array_map(static fn (string $line): array => json_decode($line, true, flags: \JSON_THROW_ON_ERROR), $lines);
};
$receipt = ProgrammaticProof::export($read($argv[1]), $read($argv[2]));
$receipt['sourceSha256'] = ['clientTranscript' => hash_file('sha256', $argv[1]), 'serverTrace' => hash_file('sha256', $argv[2])];
echo json_encode($receipt, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
