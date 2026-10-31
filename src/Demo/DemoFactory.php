<?php

declare(strict_types=1);

namespace App\Demo;

use CodeMode\CodeModeSession;
use CodeMode\DockerEngine;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\GvisorSandbox;
use CodeMode\SandboxExecutor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class DemoFactory
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private string $projectDir) {}

    public function create(string $approach = 'code', string $backend = 'fixture', ?SandboxExecutor $sandbox = null): DemoContext
    {
        if (null === $sandbox) {
            $docker = new DockerEngine(json_decode(BackendFactory::env('CODE_MODE_DOCKER_COMMAND', '["docker"]'), true, flags: JSON_THROW_ON_ERROR));
            $sandbox = new GvisorSandbox(BackendFactory::env('CODE_MODE_IMAGE'), $docker);
            // Check the deployment before making any paid model request.
            $docker->requireGvisor();
            $docker->control(['image', 'inspect', '--format', '{{.Id}}', BackendFactory::env('CODE_MODE_IMAGE')]);
        }
        $gateway = (new BackendFactory($this->projectDir))->create($backend);
        $catalog = $gateway->discover();
        if ([] === $catalog->all()) {
            throw new \RuntimeException('The configured MCP backend exposes no locally approved tools.');
        }
        $broker = $gateway->broker($catalog);
        $trace = new RunTrace();
        $session = new CodeModeSession($catalog, $broker, $sandbox, new ExecutionIdentity('demo-editor', ['content.audit']), new ExecutionLimits(wallTimeMs: 15000, toolTimeMs: 3000, maxToolCalls: 30));
        return new DemoContext(new AuditToolbox($approach, $catalog, $broker, $session, $trace), $session, $catalog, $trace);
    }
}
