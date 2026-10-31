<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\AuditDataset;
use App\Demo\BackendFactory;
use App\Demo\DemoFactory;
use App\Demo\DemoRunner;
use CodeMode\{ExecutionIdentity, ExecutionLimits, ExecutionResult, SandboxExecutor, ToolBroker};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class CodeModeAgentTest extends KernelTestCase
{
    public function testDirectAgentUsesTheSameBatchedMcpToolsAndChecksItsAnswer(): void
    {
        self::bootKernel();
        $sandbox = new class implements SandboxExecutor {
            public function execute(string $code, ToolBroker $broker, ExecutionIdentity $identity, array $allowedTools = [], ?ExecutionLimits $limits = null): ExecutionResult
            {
                throw new \LogicException('The direct baseline must not run PHP.');
            }
        };
        $context = (new DemoFactory(dirname(__DIR__, 2)))->create('direct', sandbox: $sandbox);
        $round = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$round, $context): JsonMockResponse {
            ++$round;
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertCount(3, $body['tools']);
            $calls = match ($round) {
                1 => array_map(static fn ($offset) => ['cms_list_articles', ['offset' => $offset, 'limit' => 12]], [0, 12, 24, 36]),
                2 => array_map(static fn ($ids) => ['cms_get_articles', ['ids' => $ids]], array_chunk(range(1, 48), 12)),
                3 => array_map(static fn ($ids) => ['cms_get_media', ['ids' => $ids]], array_chunk(range(1, 24), 12)),
                4 => [],
                default => throw new \LogicException('Unexpected provider round.'),
            };
            if (4 === $round) {
                self::assertCount(10, array_filter($body['messages'], static fn ($message) => 'tool' === $message['role']));
            }
            $message = ['role' => 'assistant', 'content' => [] === $calls ? json_encode((new AuditDataset())->expected()) : null];
            foreach ($calls as $index => [$name, $arguments]) {
                $message['tool_calls'][] = ['id' => 'batch_'.$round.'_'.$index, 'type' => 'function', 'function' => ['name' => $context->toolbox->aliasFor($name), 'arguments' => json_encode($arguments)]];
            }
            return new JsonMockResponse(['choices' => [['index' => 0, 'message' => $message, 'finish_reason' => [] === $calls ? 'stop' : 'tool_calls']]]);
        });
        self::getContainer()->set('http_client', $http);
        $result = self::getContainer()->get(DemoRunner::class)->run('live', 'direct', context: $context);
        self::assertTrue($result['verification']['passed']);
        self::assertCount(10, $result['brokerCalls']);
        self::assertSame(4, $result['modelRequests']);
        self::assertNull($result['providerTokens']);
        self::assertSame([], $result['programs']);
    }

    public function testSymfonyAiDiscoversSchemasAndDispatchesProviderSuppliedPhp(): void
    {
        self::bootKernel();
        $generated = '$fromTheProvider = true; return ["audited" => 48, "findings" => []];';
        $expected = (new AuditDataset())->expected();
        $sandbox = new class($expected) implements SandboxExecutor {
            public ?string $source = null;
            public function __construct(private array $report) {}
            public function execute(string $code, ToolBroker $broker, ExecutionIdentity $identity, array $allowedTools = [], ?ExecutionLimits $limits = null): ExecutionResult
            {
                $this->source = $code;
                return new ExecutionResult($this->report, array_map(static fn ($name): array => ['name' => $name, 'status' => 'succeeded'], $allowedTools), 1, 0, 'unit-only');
            }
        };
        $round = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$round, $generated, $expected): JsonMockResponse {
            ++$round;
            self::assertSame('https://router.huggingface.co/v1/chat/completions', $url);
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['tools_search', 'tools_describe', 'php_execute'], array_column(array_column($body['tools'], 'function'), 'name'));
            $message = match ($round) {
                1 => self::toolCall('tools_search', ['query' => 'article metadata media']),
                2 => self::toolCall('tools_describe', ['names' => BackendFactory::FIXTURE_TOOLS]),
                3 => self::toolCall('php_execute', ['code' => $generated]),
                4 => ['role' => 'assistant', 'content' => json_encode($expected)],
                default => throw new \LogicException('Unexpected provider round.'),
            };
            if (2 === $round) {
                self::assertStringContainsString('cms_get_articles', json_encode($body['messages']));
            }
            if (3 === $round) {
                self::assertStringContainsString('inputSchema', json_encode($body['messages']));
            }
            return new JsonMockResponse(['model' => 'openai/gpt-oss-120b', 'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $round < 4 ? 'tool_calls' : 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 25, 'total_tokens' => 125]]);
        });
        self::getContainer()->set('http_client', $http);
        $context = (new DemoFactory(dirname(__DIR__, 2)))->create(sandbox: $sandbox);
        $runner = self::getContainer()->get(DemoRunner::class);
        $result = $runner->run('live', 'code', context: $context);
        self::assertSame($generated, $sandbox->source, 'The PHP source must come from the provider response.');
        self::assertSame(4, $round);
        self::assertSame(4, $result['modelRequests']);
        self::assertSame(3, $result['agentToolCalls']);
        self::assertSame(500, $result['providerTokens']['total']);
        self::assertTrue($result['verification']['passed']);
    }

    private static function toolCall(string $name, array $arguments): array
    {
        return ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_'.$name, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)]]]];
    }
}
