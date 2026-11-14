<?php

declare(strict_types=1);

namespace App\Demo;

use CodeMode\ExecutionIdentity;
use CodeMode\Tool;

/** Host-only observations of the same trusted handlers; inherited by supervised broker children. */
final class AuditReadJournal
{
    private mixed $stream;

    public function __construct()
    {
        $this->stream = tmpfile();
        if (false === $this->stream) {
            throw new \RuntimeException('Cannot open private audit journal.');
        }
    }

    public function __destruct()
    {
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function wrap(array $tools): array
    {
        return array_map(fn (Tool $tool) => new Tool($tool->name, $tool->inputSchema, $tool->authorize,
            function (\stdClass $arguments, ExecutionIdentity $identity) use ($tool): mixed {
                $event = ['name' => $tool->name, 'arguments' => $arguments, 'subject' => $identity->subject];
                try {
                    $value = ($tool->invoke)($arguments, $identity);
                    $event += ['status' => 'succeeded', 'value' => $value];

                    return $value;
                } finally {
                    $event += ['status' => 'failed', 'value' => null];
                    $line = json_encode($event, \JSON_THROW_ON_ERROR)."\n";
                    if (\strlen($line) > 65536 || !flock($this->stream, \LOCK_EX)) {
                        throw new \RuntimeException('Audit observation exceeded its bound.');
                    }
                    try {
                        fseek($this->stream, 0, \SEEK_END);
                        if (ftell($this->stream) + \strlen($line) > 2 * 1048576 || fwrite($this->stream, $line) !== \strlen($line)) {
                            throw new \RuntimeException('Cannot retain audit evidence.');
                        }
                        fflush($this->stream);
                    } finally {
                        flock($this->stream, \LOCK_UN);
                    }
                }
            }), $tools);
    }

    public function events(): array
    {
        rewind($this->stream);
        $events = [];
        while (false !== ($line = fgets($this->stream))) {
            $events[] = json_decode($line, true, 32, \JSON_THROW_ON_ERROR);
        }

        return $events;
    }
}
