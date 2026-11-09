<?php

declare(strict_types=1);

namespace SurfaceProof;

use CodeMode\ExecutionIdentity;
use CodeMode\Provider\{ToolBinding, ToolProviderInterface};

/** Example-only observer. Authorization, schemas and invocation remain upstream. */
final readonly class ObservedProvider implements ToolProviderInterface
{
    /** @param resource $journal Private host file, inherited by the broker worker. */
    public function __construct(private ToolProviderInterface $inner, private mixed $journal)
    {
        if (!is_resource($journal)) {
            throw new \InvalidArgumentException('A private host journal is required.');
        }
    }

    public function name(): string { return $this->inner->name(); }
    public function source(): string { return $this->inner->source(); }

    public function tools(): iterable
    {
        foreach ($this->inner->tools() as $binding) {
            yield new ToolBinding($binding->method, $binding->definition, $binding->handler->authorize,
                function (\stdClass $arguments, ExecutionIdentity $identity) use ($binding): mixed {
                    $start = hrtime(true);
                    $event = ['name' => $this->name().'.'.$binding->method, 'upstreamTool' => $binding->definition->name,
                        'arguments' => $arguments, 'subject' => $identity->subject];
                    try {
                        $value = ($binding->handler->invoke)($arguments, $identity);
                        $event += ['status' => 'succeeded', 'value' => $value];
                        return $value;
                    } finally {
                        // Never persist upstream exception messages or connection credentials.
                        $event += ['status' => 'failed', 'value' => null];
                        $event['durationMs'] = (hrtime(true) - $start) / 1e6;
                        $line = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                        if (strlen($line) > 32768 || !flock($this->journal, LOCK_EX)) {
                            throw new \RuntimeException('Cannot capture a bounded tool event.');
                        }
                        try {
                            fseek($this->journal, 0, SEEK_END);
                            if (ftell($this->journal) + strlen($line) > 262144 || fwrite($this->journal, $line) !== strlen($line)) {
                                throw new \RuntimeException('Cannot capture a bounded tool event.');
                            }
                            fflush($this->journal);
                        } finally {
                            flock($this->journal, LOCK_UN);
                        }
                    }
                });
        }
    }
}
