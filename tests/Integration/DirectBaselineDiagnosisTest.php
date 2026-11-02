<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\{AuditPrompt, DemoFactory, DemoQuestion, DemoRunner};
use CodeMode\{ExecutionIdentity, ExecutionLimits, ExecutionResult, SandboxExecutor, ToolBroker};
use Symfony\AI\Platform\Bridge\HuggingFace\Factory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use PHPUnit\Framework\TestCase;

final class DirectBaselineDiagnosisTest extends TestCase
{
    public function testHistoricalDirectToolResponsesReachTheHttpBodyWithoutTruncation(): void
    {
        $root = dirname(__DIR__, 2);
        $capture = json_decode(file_get_contents($root.'/docs/evidence/huggingface-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        $calls = $capture['result']['agentCalls'];
        $sandbox = new class implements SandboxExecutor {
            public function execute(string $code, ToolBroker $broker, ExecutionIdentity $identity, array $allowedTools = [], ?ExecutionLimits $limits = null): ExecutionResult
            { throw new \LogicException('Direct replay must not execute PHP.'); }
        };
        $factory = new DemoFactory($root);
        $context = $factory->create('direct', sandbox: $sandbox);
        $context->trace->captureDetails = true;
        $round = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$round, $calls, $capture): JsonMockResponse {
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            $toolMessages = array_values(array_filter($body['messages'], static fn ($m) => 'tool' === $m['role']));
            self::assertSame(array_column(array_slice($calls, 0, $round), 'result'), array_column($toolMessages, 'content'), 'Exact complete tool responses must survive normalization into the HTTP body.');
            self::assertSame(AuditPrompt::build('direct', DemoQuestion::preset()), $body['messages'][0]['content']);
            $call = $calls[$round] ?? null;
            $message = null === $call ? ['role' => 'assistant', 'content' => $capture['result']['answer']]
                : ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'replay_'.$round, 'type' => 'function', 'function' => ['name' => $call['name'], 'arguments' => json_encode($call['arguments'])]]]];
            ++$round;
            return new JsonMockResponse(['choices' => [['index' => 0, 'message' => $message, 'finish_reason' => null === $call ? 'stop' : 'tool_calls']]]);
        });
        $runner = new DemoRunner($factory, Factory::createPlatform('mock-only', httpClient: $http), $root);
        $result = $runner->run('live', 'direct', context: $context, maxModelRequests: 12);
        self::assertSame(12, $round);
        self::assertFalse($result['verification']['passed']);
        self::assertSame($capture['result']['answer'], $result['answer']);
        self::assertSame('stop', $result['providerResponses'][11]['finishReason']);
        self::assertCount(11, $result['requestEvidence'][11]['toolMessages']);
    }

    public function testUnconditionalReviewRetainsToolHistoryAndCountsBothTurns(): void
    {
        $root = dirname(__DIR__, 2);
        $saved = json_decode(file_get_contents($root.'/docs/evidence/huggingface-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        $calls = $saved['result']['agentCalls'];
        $sandbox = new class implements SandboxExecutor {
            public function execute(string $code, ToolBroker $broker, ExecutionIdentity $identity, array $allowedTools = [], ?ExecutionLimits $limits = null): ExecutionResult
            { throw new \LogicException('Direct review must not execute PHP.'); }
        };
        $factory = new DemoFactory($root);
        $context = $factory->create('direct', sandbox: $sandbox);
        $context->trace->captureDetails = true;
        $round = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$round, $calls, $saved): JsonMockResponse {
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('medium', $body['reasoning_effort']);
            self::assertSame(8192, $body['max_tokens']);
            if (12 === $round) {
                self::assertSame(AuditPrompt::review(), $body['messages'][array_key_last($body['messages'])]['content']);
                self::assertSame($saved['result']['answer'], $body['messages'][count($body['messages']) - 2]['content']);
                self::assertSame(array_column($calls, 'result'), array_column(array_values(array_filter($body['messages'], static fn ($m) => 'tool' === $m['role'])), 'content'));
            }
            $call = $calls[$round] ?? null;
            // Synthetic provider replies only: the live runner never receives oracle feedback.
            $answer = 12 === $round ? json_encode((new \App\Demo\AuditDataset())->expected()) : $saved['result']['answer'];
            $message = null === $call ? ['role' => 'assistant', 'content' => $answer]
                : ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'review_'.$round, 'type' => 'function', 'function' => ['name' => $call['name'], 'arguments' => json_encode($call['arguments'])]]]];
            ++$round;
            return new JsonMockResponse(['choices' => [['index' => 0, 'message' => $message, 'finish_reason' => null === $call ? 'stop' : 'tool_calls']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 1, 'total_tokens' => 11]]);
        });
        $result = (new DemoRunner($factory, Factory::createPlatform('mock-only', httpClient: $http), $root))->run('live', 'direct', context: $context, maxModelRequests: 14, protocol: 'coverage-v7');
        self::assertSame(13, $round);
        self::assertSame(143, $result['providerTokens']['total']);
        self::assertCount(13, $result['providerResponses']);
        self::assertTrue($result['verification']['passed']);
        self::assertSame($saved['result']['answer'], $result['review']['initialAnswer']);
        self::assertTrue((new \App\Demo\HuggingFaceBaselineVerifier())->verify(['result' => $result, 'toolAliases' => $saved['toolAliases']], 14)['passed']);
        $result['brokerCalls'][10]['reason'] = 'forged';
        self::assertFalse((new \App\Demo\HuggingFaceBaselineVerifier())->verify(['result' => $result, 'toolAliases' => $saved['toolAliases']], 14)['passed']);
    }

    public function testCoverageProtocolIsSharedAndContainsNoFixtureAnswers(): void
    {
        $shared = AuditPrompt::shared(AuditPrompt::COVERAGE_PROTOCOL);
        self::assertStringNotContainsString('48', $shared);
        self::assertStringNotContainsString('24', $shared);
        self::assertStringNotContainsString('10 findings', $shared);
        foreach (['code', 'direct'] as $approach) {
            self::assertStringStartsWith($shared, AuditPrompt::build($approach, DemoQuestion::preset(), AuditPrompt::COVERAGE_PROTOCOL));
        }
        self::assertStringNotContainsString('PHP', AuditPrompt::build('direct', DemoQuestion::preset(), AuditPrompt::COVERAGE_PROTOCOL));
    }
}
