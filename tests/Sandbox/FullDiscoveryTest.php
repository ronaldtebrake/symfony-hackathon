<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Demo\BackendFactory;
use CodeMode\DiscoveryMode;
use CodeMode\DockerEngine;
use CodeMode\ExecutionIdentity;
use CodeMode\SandboxException;
use CodeMode\Symfony\Ai\ApprovedToolbox;
use CodeMode\Symfony\Ai\ToolAuthorizationInterface;
use CodeMode\Symfony\SessionFactory;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Tool\ToolEffectHints;

final class FullDiscoveryTest extends TestCase
{
    public function testDefaultRequiresFullContractsBeforeSimpleOrComplexExecution(): void
    {
        $session = $this->sessions()->create(new ExecutionIdentity('reader', ['read']));
        $this->assertSame(DiscoveryMode::Full, $session->discoveryMode());
        $found = array_column($session->search('read'), null, 'name');
        $this->assertArrayNotHasKey('inputSchema', $found['simple_read']);
        $this->assertArrayNotHasKey('inputSchema', $found['complex_read']);
        try {
            $session->execute('return 1;');
            $this->fail('Search enabled execution.');
        } catch (SandboxException $error) {
            $this->assertSame('discovery_required', $error->reason);
        }
        $session->describe(['simple_read']);
        try {
            $session->execute('return $tools->call("complex_read", ["id" => 1]);');
            $this->fail('Undisclosed complex tool ran.');
        } catch (SandboxException $error) {
            $this->assertSame('tool_denied', $error->reason);
        }
        $session->describe(['complex_read']);
        $result = $session->execute('$simple = $tools->call("simple_read", ["id" => 1]); $complex = $tools->call("complex_read", ["id" => 1]); return [$simple, $complex->result->items[0]->title];');
        $this->assertSame([1, 'approved'], $result->value);
        $this->assertSame(['succeeded', 'succeeded'], array_column($result->toolCalls, 'status'));
        try {
            $session->execute('return 1;');
            $this->fail('Fallback reset attempts.');
        } catch (SandboxException $error) {
            $this->assertSame('execution_limit', $error->reason);
        }
    }

    public function testFullDiscoveryPreservesOriginalInputAndOutputValidation(): void
    {
        $session = $this->sessions()->create(new ExecutionIdentity('reader', ['read']));
        $session->search('simple');
        $session->describe(['simple_read']);
        try {
            $session->execute('return $tools->call("simple_read", ["id" => 1, "permissions" => ["admin"]]);');
            $this->fail('Additional argument bypassed schema.');
        } catch (SandboxException $error) {
            $this->assertSame('invalid_arguments', $error->reason);
        }
        try {
            $session->execute('return $tools->call("simple_read", ["id" => 1, "invalid" => true]);');
            $this->fail('Invalid handler result passed the original output schema.');
        } catch (SandboxException $error) {
            $this->assertSame('tool_failed', $error->reason);
        }
    }

    private function sessions(): SessionFactory
    {
        $policy = new class implements ToolAuthorizationInterface {
            public function isGranted(ExecutionIdentity $identity, string $tool, \stdClass $arguments): bool
            {
                return $identity->allows('read') && 1 === ($arguments->id ?? null);
            }
        };

        return new SessionFactory([], BackendFactory::env('CODE_MODE_IMAGE'),
            new DockerEngine(json_decode(BackendFactory::env('CODE_MODE_DOCKER_COMMAND', '["docker"]'), true, flags: \JSON_THROW_ON_ERROR)),
            aiToolbox: new ApprovedToolbox(new Toolbox([new MixedDiscoveryTools()]), ['simple_read', 'complex_read'], $policy));
    }
}

#[AsTool('simple_read', 'Read a scalar', method: 'simple', metadata: ['code_mode.output_schema' => ['type' => 'integer']], effectHints: new ToolEffectHints(readOnlyHint: true))]
#[AsTool('complex_read', 'Read a nested result', method: 'complex', metadata: ['code_mode.output_schema' => self::OUTPUT], effectHints: new ToolEffectHints(readOnlyHint: true))]
final class MixedDiscoveryTools
{
    public const OUTPUT = ['type' => 'object', 'properties' => ['result' => ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]]]]]]];

    public function simple(int $id, bool $invalid = false): int|string
    {
        return $invalid ? 'wrong output type' : $id;
    }

    public function complex(int $id): array
    {
        return ['result' => ['items' => [['title' => 'approved']]]];
    }
}
