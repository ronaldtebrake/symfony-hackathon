<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\CodeMode\ReadArticle;
use CodeMode\CodeModeSession;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\ExecutionResult;
use CodeMode\SandboxException;
use CodeMode\SandboxExecutor;
use CodeMode\Symfony\Ai\CodeModeToolbox;
use CodeMode\ToolBroker;
use CodeMode\ToolCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\ToolCall;

final class CodeModeToolboxTest extends TestCase
{
    public static function discoveryModes(): iterable { foreach (\CodeMode\DiscoveryMode::cases() as $mode) { yield [$mode]; } }

    #[\PHPUnit\Framework\Attributes\DataProvider('discoveryModes')]
    public function testAiSeesDenialAndRecoveryWithoutResettingSessionBudget(\CodeMode\DiscoveryMode $mode): void
    {
        $sandbox = new class implements SandboxExecutor {
            public array $programs = [];
            public function execute(string $code, ToolBroker $broker, ExecutionIdentity $identity, array $allowedTools = [], ?ExecutionLimits $limits = null): ExecutionResult
            {
                $this->programs[] = $code;
                if (1 === count($this->programs)) {
                    throw new SandboxException('tool_denied', 'private exception detail', [['name' => 'articles_read', 'status' => 'denied']], runId: 'code-mode-'.str_repeat('a', 32));
                }
                return new ExecutionResult(['id' => 1], [['name' => 'articles_read', 'status' => 'succeeded']], 1.0, 1, 'code-mode-'.str_repeat('b', 32));
            }
        };
        $session = new CodeModeSession(new ToolCatalog([(new ReadArticle())->definition()]), new ToolBroker([]), $sandbox, new ExecutionIdentity('test'), discovery: $mode);
        $toolbox = new CodeModeToolbox($session);
        self::assertCount(3, $toolbox->getTools());
        $call = static fn ($name, $args) => json_decode($toolbox->execute(new ToolCall('call', $name, $args))->getResult(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('invalid_arguments', $call('php_execute', ['code' => 'return 1;', 'permissions' => ['admin']])['error']['reason']);
        self::assertSame('discovery_required', $call('php_execute', ['code' => 'return 1;'])['error']['reason']);
        self::assertSame('invalid_arguments', $call('tools_search', ['query' => 'article', 'discovery' => 'compact'])['error']['reason']);
        if (\CodeMode\DiscoveryMode::Compact === $mode) { $call('tools_search', ['query' => 'article']); }
        else { $call('tools_describe', ['names' => ['articles_read']]); }
        $denial = $call('php_execute', ['code' => 'return $tools->call("articles_read", ["id" => 49]);']);
        self::assertSame('tool_denied', $denial['error']['reason']);
        self::assertStringNotContainsString('private exception', json_encode($denial));
        self::assertArrayNotHasKey('toolCalls', $denial);
        self::assertSame('articles_read', $denial['error']['tool']);
        self::assertSame('denied', $toolbox->lastExecution()['trace']['toolCalls'][0]['status']);
        $recovery = $call('php_execute', ['code' => 'return $tools->call("articles_read", ["id" => 1]);']);
        self::assertSame(['id' => 1], $recovery['value']);
        self::assertSame('succeeded', $toolbox->lastExecution()['trace']['toolCalls'][0]['status']);
        self::assertArrayNotHasKey('runId', $recovery);
        $call('tools_search', ['query' => 'article']);
        self::assertNull($toolbox->lastExecution(), 'Another call must not reuse stale execution evidence.');
        self::assertSame('execution_limit', $call('php_execute', ['code' => 'return 2;'])['error']['reason']);
        self::assertCount(2, $sandbox->programs);
    }
}
