<?php

declare(strict_types=1);

use CodeMode\DockerEngine;
use CodeMode\ExecutionIdentity;
use CodeMode\GvisorSandbox;
use CodeMode\Tool;
use CodeMode\ToolBroker;

$autoload = is_file(dirname(__DIR__).'/vendor/autoload.php')
    ? dirname(__DIR__).'/vendor/autoload.php'
    : dirname(__DIR__, 3).'/vendor/autoload.php';
require $autoload;

$image = getenv('CODE_MODE_IMAGE') ?: '';
$command = json_decode(getenv('CODE_MODE_DOCKER_COMMAND') ?: '["docker"]', true, flags: JSON_THROW_ON_ERROR);
$sandbox = new GvisorSandbox($image, new DockerEngine($command));
$broker = new ToolBroker([
    new Tool(
        'demo.add',
        json_decode('{"type":"object","properties":{"a":{"type":"integer"},"b":{"type":"integer"}},"required":["a","b"],"additionalProperties":false}'),
        static fn (ExecutionIdentity $identity): bool => $identity->allows('calculate'),
        static fn (stdClass $args): int => $args->a + $args->b,
    ),
]);
$result = $sandbox->execute(<<<'PHP'
$values = [];
foreach ([2, 4, 6] as $number) {
    $values[] = $tools->call('demo.add', ['a' => $number, 'b' => 10]);
}
return ['php' => PHP_VERSION, 'sum' => array_sum($values), 'values' => $values];
PHP, $broker, new ExecutionIdentity('proof-user', ['calculate']), ['demo.add']);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
