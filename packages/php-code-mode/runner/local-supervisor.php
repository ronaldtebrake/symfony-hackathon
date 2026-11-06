<?php

declare(strict_types=1);

// Trusted CLI subprocess only. Never include this file in Symfony or the broker.
// This watchdog improves cleanup for ordinary failures. It is NOT a security boundary.
if ('cli' !== PHP_SAPI || !function_exists('posix_setsid') || !function_exists('posix_kill') || !function_exists('proc_open')) {
    exit(78);
}
$wallTimeMs = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
$memoryMiB = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT);
if (false === $wallTimeMs || $wallTimeMs < 100 || $wallTimeMs > 30000 || false === $memoryMiB || $memoryMiB < 16 || $memoryMiB > 256 || -1 === posix_setsid()) {
    exit(78);
}
$deadline = hrtime(true) / 1_000_000_000 + $wallTimeMs / 1000 + 2;
$process = proc_open([
    PHP_BINARY, '-n', '-d', 'memory_limit='.$memoryMiB.'M', '-d', 'max_execution_time=30',
    '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0', '-d', 'display_errors=stderr', '-d', 'log_errors=0',
    '-d', 'disable_functions=exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork,pcntl_signal,pcntl_alarm,posix_kill,posix_setsid,dl,putenv,ini_set,ini_alter,ini_restore,set_time_limit',
    __DIR__.'/bootstrap.php',
], [STDIN, STDOUT, STDERR], $pipes, null, []);
if (false === $process) { exit(78); }
do {
    if (hrtime(true) / 1_000_000_000 >= $deadline) {
        posix_kill(-getmypid(), SIGKILL);
        exit(124);
    }
    $status = proc_get_status($process);
    if ($status['running']) { usleep(1000); }
} while ($status['running']);
$exit = $status['exitcode'];
proc_close($process);
exit($exit >= 0 ? $exit : 1);
