<?php

declare(strict_types=1);

namespace CodeMode;

/** Optional operator-owned evidence sink, outside the MCP response and guest filesystem. */
final class ExecutionTraceFile
{
    private $stream;
    private int $bytes = 0;

    public function __construct(string $path)
    {
        // Exclusive creation refuses existing files and symlinks. Set permissions at creation.
        $mask = umask(0077);
        try { $this->stream = @fopen($path, 'x'); }
        finally { umask($mask); }
        if (false === $this->stream) { throw new \RuntimeException('The execution trace needs a new file in an existing private directory.'); }
    }

    public function __invoke(string $code, array $trace): void
    {
        $line = json_encode(['code' => $code, 'trace' => $trace], JSON_THROW_ON_ERROR)."\n";
        // An exhausted client can keep requesting execution. Do not grow a log without bounds.
        if ($this->bytes + strlen($line) > 2 * 1024 * 1024) { throw new \RuntimeException('Execution trace size limit reached.'); }
        if (strlen($line) !== fwrite($this->stream, $line) || !fflush($this->stream)) {
            throw new \RuntimeException('Execution trace could not be persisted.');
        }
        $this->bytes += strlen($line);
    }

    public function __destruct() { if (is_resource($this->stream)) { fclose($this->stream); } }

    /** For trusted local proof consumers only. Never expose paths or this reader as an MCP tool. */
    public static function last(string $path, string $expectedCode): array
    {
        $stream = fopen($path, 'r');
        try { $contents = stream_get_contents($stream, 2 * 1024 * 1024 + 1); }
        finally { fclose($stream); }
        if (strlen($contents) > 2 * 1024 * 1024) { throw new \RuntimeException('Execution trace size limit reached.'); }
        $lines = explode("\n", rtrim($contents, "\n"));
        $record = json_decode(end($lines), true, 64, JSON_THROW_ON_ERROR);
        if ($expectedCode !== ($record['code'] ?? null) || !is_array($record['trace'] ?? null)) {
            throw new \RuntimeException('Execution trace does not match the submitted PHP.');
        }
        return $record['trace'];
    }
}
