<?php

declare(strict_types=1);

namespace CodeMode;

/** The guest can forge every byte on this channel. @internal */
final class Protocol
{
    public static function decode(string $line, int $expectedId): \stdClass
    {
        try {
            $frame = json_decode($line, false, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SandboxException('invalid_protocol', 'Guest emitted invalid JSON.');
        }
        if (!$frame instanceof \stdClass || !isset($frame->type)) {
            throw new SandboxException('invalid_protocol', 'Guest emitted an invalid protocol frame.');
        }
        $keys = array_keys(get_object_vars($frame));
        sort($keys);
        if ('tool_call' === $frame->type) {
            if ($keys !== ['arguments', 'id', 'name', 'type'] || $frame->id !== $expectedId
                || !\is_string($frame->name) || !preg_match('/\A[a-zA-Z][a-zA-Z0-9_.-]{0,63}\z/D', $frame->name)
                || !$frame->arguments instanceof \stdClass) {
                throw new SandboxException('invalid_protocol', 'Guest emitted an invalid tool request.');
            }
        } elseif ('result' === $frame->type) {
            if ($keys !== ['type', 'value']) {
                throw new SandboxException('invalid_protocol', 'Guest emitted an invalid result.');
            }
        } elseif ('error' === $frame->type && $keys === ['type']) {
            throw new SandboxException('guest_failed', 'The PHP program failed.');
        } else {
            throw new SandboxException('invalid_protocol', 'Unknown guest protocol message.');
        }

        return $frame;
    }
}
