<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use CodeMode\DockerEngine;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\GvisorSandbox;
use CodeMode\Tool;
use CodeMode\ToolBroker;

$marker = $argv[1];
$blockHandler = 'blocked' === ($argv[2] ?? '');
$docker = new DockerEngine(json_decode(getenv('CODE_MODE_DOCKER_COMMAND') ?: '["docker"]', true, flags: JSON_THROW_ON_ERROR));
$broker = new ToolBroker([new Tool('ready', (object) ['type' => 'object'], static fn (): bool => true,
    static function () use ($marker, $blockHandler): bool {
        file_put_contents($marker, (string) getmypid());
        if ($blockHandler) {
            sleep(60);
        }
        return true;
    },
)]);
(new GvisorSandbox(getenv('CODE_MODE_IMAGE'), $docker))->execute(
    '$tools->call("ready"); sleep(60);', $broker, new ExecutionIdentity('test'), ['ready'], new ExecutionLimits(wallTimeMs: 1000),
);
