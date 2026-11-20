<?php

declare(strict_types=1);

namespace App\Demo\HintEvaluation;

use App\Demo\HttpConversation\Application;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\AI\Agent\Bridge\Mcp\ToolsetInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Only the two catalog tools enter the model's toolbox. The operator owns the fixture and grant. */
final class Fixture implements ToolsetInterface
{
    public array $calls = [];
    public readonly array $definitions;
    private readonly Application $app;
    private readonly HttpClientInterface $http;
    private readonly Client $client;
    private readonly array $tools;
    private int $attempts = 0;

    public function __construct(string $root)
    {
        $this->app = new Application($root, environment: 'test');
        $this->http = HttpClient::create(['timeout' => 10, 'max_duration' => 15, 'max_redirects' => 0, 'no_proxy' => '*']);
        $this->client = Client::builder()->setClientInfo('Symfony permission-hint study', '1')->setInitTimeout(10)->setRequestTimeout(15)->setMaxRetries(0)->build();
        try {
            $this->app->start();
            $this->client->connect(new HttpTransport($this->app->endpoint, ['Authorization' => 'Bearer '.$this->app->token], new Psr18Client($this->http)));
            $listed = $this->client->listTools();
            $this->definitions = json_decode(json_encode($listed->tools, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
            $names = array_column($this->definitions, 'name');
            sort($names);
            if (['execute_tools', 'search_tools'] !== $names || null !== $listed->nextCursor) {
                throw new \RuntimeException('Unexpected evaluation tool list.');
            }
            $this->tools = $listed->tools;
        } catch (\Throwable $failure) {
            $this->close();
            throw $failure;
        }
    }

    public function hints(): array
    {
        return $this->http->request('GET', \dirname($this->app->endpoint).'/articles/capability-hint', ['auth_bearer' => $this->app->token])->toArray()['hints'];
    }

    public function revoke(): array
    {
        $this->app->revoke();

        return ['op' => 'revoke', 'ok' => true];
    }

    public function getName(): string
    {
        return 'symfony';
    }

    public function getTools(): array
    {
        return $this->tools;
    }

    public function callTool(string $name, array $arguments = []): CallToolResult
    {
        if (!\in_array($name, ['execute_tools', 'search_tools'], true) || $this->attempts >= 12) {
            throw new \RuntimeException('Evaluation tool limit reached.');
        }
        ++$this->attempts;
        $reply = $this->client->callTool($name, $arguments);
        $text = $reply->content[0] ?? null;
        if (1 !== \count($reply->content) || !$text instanceof TextContent || !\is_string($text->text)
            || !\is_array($reply->structuredContent) || $reply->structuredContent !== json_decode($text->text, true, flags: \JSON_THROW_ON_ERROR)) {
            throw new \UnexpectedValueException('Catalog text and structured results must agree.');
        }
        $this->calls[] = ['op' => 'call', 'name' => $name, 'arguments' => $arguments, 'isError' => $reply->isError, 'result' => $reply->structuredContent];

        return $reply;
    }

    public function close(): void
    {
        try {
            $this->client->disconnect();
        } finally {
            $this->app->stop();
        }
    }
}
