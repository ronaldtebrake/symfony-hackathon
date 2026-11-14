<?php

declare(strict_types=1);

// Apply the additive proposal to a temporary copy. Never patch installed vendor code.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Symfony\Component\Process\Process;

$directory = sys_get_temp_dir().'/code-mode-sdk-proposal-'.bin2hex(random_bytes(8));
$target = $directory.'/src/Schema/Result/CallToolResult.php';
mkdir(dirname($target), 0700, true);
copy(dirname(__DIR__, 2).'/vendor/mcp/sdk/src/Schema/Result/CallToolResult.php', $target);
try {
    $patch = new Process(['patch', '--batch', '--forward', '-p1', '--input', __DIR__.'/php-mcp-sdk-structured-results.patch'], $directory, timeout: 10);
    $patch->mustRun();
    require $target;
    $check = static function (bool $condition): void { if (!$condition) { throw new RuntimeException('SDK proposal verification failed.'); } };
    $values = [['count' => 48], [1, 2], (object) [], false, 0, '', null];
    foreach ($values as $value) {
        $result = Mcp\Schema\Result\CallToolResult::structured($value, meta: ['trace' => 'fixture']);
        $wire = json_decode(json_encode($result, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        $check(!$wire['isError']);
        $check(json_decode($wire['content'][0]['text'], true) === json_decode(json_encode($value), true));
        $check(($wire['structuredContent'] ?? null) === json_decode(json_encode($value), true));
        $check($wire['_meta'] === ['trace' => 'fixture']);
    }
    $error = Mcp\Schema\Result\CallToolResult::structured(['error' => ['reason' => 'tool_denied']], isError: true);
    $check($error->isError && 'tool_denied' === $error->structuredContent['error']['reason']);
    $check(Mcp\Schema\Result\CallToolResult::fromArray(json_decode(json_encode($error), true))->isError);
    try {
        Mcp\Schema\Result\CallToolResult::structured(\NAN);
        throw new RuntimeException('Invalid JSON was accepted.');
    } catch (JsonException) {
        // The helper must not silently replace invalid data with false or an empty string.
    }
    $check(!Mcp\Schema\Result\CallToolResult::success([])->isError);
    $check(Mcp\Schema\Result\CallToolResult::error([])->isError);
    echo "PASS: 7 JSON shapes, structured denial round-trip, invalid JSON rejection, existing factories unchanged. Installed vendor code was not modified.\n";
} finally {
    unlink($target);
    foreach (['/src/Schema/Result', '/src/Schema', '/src', ''] as $path) {
        rmdir($directory.$path);
    }
}
