<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class AiTest extends KernelTestCase
{
    public function testConfiguredAgentUsesHuggingFace(): void
    {
        self::bootKernel();
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options): JsonMockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://router.huggingface.co/v1/chat/completions', $url);
            $body = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame('openai/gpt-oss-120b', $body['model']);
            self::assertSame('Reply with OK.', $body['messages'][0]['content']);

            return new JsonMockResponse([
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'OK'],
                    'finish_reason' => 'stop',
                ]],
            ]);
        });
        self::getContainer()->set('http_client', $httpClient);

        $agent = self::getContainer()->get('ai.agent.default');
        $this->assertInstanceOf(AgentInterface::class, $agent);
        $result = $agent->call(new MessageBag(Message::ofUser('Reply with OK.')));

        $this->assertSame('OK', $result->getContent());
        $this->assertSame(1, $httpClient->getRequestsCount());
    }
}
