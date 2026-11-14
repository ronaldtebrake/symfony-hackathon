<?php

declare(strict_types=1);

// Guest entry point: runs only inside the isolated gVisor image.
// Never include this file in the host application or broker process.
final class Tools
{
    private int $id = 0;

    public function call(string $name, array $arguments = []): mixed
    {
        $id = ++$this->id;
        $frame = json_encode(['type' => 'tool_call', 'id' => $id, 'name' => $name, 'arguments' => (object) $arguments], \JSON_THROW_ON_ERROR);
        fwrite(\STDOUT, $frame."\n");
        fflush(\STDOUT);
        $line = fgets(\STDIN, 131072);
        if (false === $line) {
            throw new RuntimeException('Broker disconnected.');
        }
        $response = json_decode($line, false, 32, \JSON_THROW_ON_ERROR);
        if ($response->id !== $id) {
            throw new RuntimeException('Unexpected broker response.');
        }

        return $response->value;
    }
}

try {
    $line = fgets(\STDIN, 524288);
    if (false === $line) {
        throw new RuntimeException('Missing program.');
    }
    $program = json_decode($line, false, 8, \JSON_THROW_ON_ERROR);
    if (!$program instanceof stdClass || !is_string($program->code ?? null)) {
        throw new RuntimeException('Invalid program.');
    }
    // Normal echo/print output is diagnostic. Raw STDOUT is still untrusted at the host.
    ob_start(static function (string $output): string {
        fwrite(\STDERR, $output);

        return '';
    }, 1024);
    $result = (static function (string $code, Tools $tools): mixed {
        return eval($code);
    })($program->code, new Tools());
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    fwrite(\STDOUT, json_encode(['type' => 'result', 'value' => $result], \JSON_THROW_ON_ERROR, 32)."\n");
} catch (Throwable) {
    fwrite(\STDOUT, "{\"type\":\"error\"}\n");
    exit(1);
}
