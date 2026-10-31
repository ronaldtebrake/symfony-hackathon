<?php

declare(strict_types=1);

namespace CodeMode;

final readonly class ExecutionLimits
{
    public function __construct(
        public int $wallTimeMs = 5000,
        public int $toolTimeMs = 1000,
        public int $memoryMiB = 128,
        public int $phpMemoryMiB = 32,
        public int $brokerMemoryMiB = 64,
        public int $maxToolCalls = 10,
        public int $maxCodeBytes = 32768,
        public int $maxFrameBytes = 16384,
        public int $maxOutputBytes = 65536,
        public int $maxToolResultBytes = 16384,
    ) {
        foreach ([
            [$wallTimeMs, 100, 30000], [$toolTimeMs, 50, $wallTimeMs],
            [$memoryMiB, 64, 512], [$phpMemoryMiB, 16, intdiv($memoryMiB, 2)],
            [$brokerMemoryMiB, 16, 512],
            [$maxToolCalls, 0, 100], [$maxCodeBytes, 1, 65536],
            [$maxFrameBytes, 256, 65536], [$maxOutputBytes, $maxFrameBytes, 1048576],
            [$maxToolResultBytes, 256, 65536],
        ] as [$value, $min, $max]) {
            if ($value < $min || $value > $max) {
                throw new \InvalidArgumentException('Execution limits must be finite and within the supported bounds.');
            }
        }
    }
}
