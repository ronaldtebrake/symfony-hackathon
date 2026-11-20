<?php

declare(strict_types=1);

namespace App\Demo\FieldSelection;

use Nyholm\Psr7\Stream;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Measure the SDK's actual HTTP entities, including both representations of each MCP result. */
final class BodyMeter implements ClientInterface
{
    public string $phase = 'setup';
    public array $calls = [];

    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $sent = (string) $request->getBody();
        $rpc = json_decode($sent, true);
        $response = $this->client->sendRequest($request);
        if ('POST' !== $request->getMethod()) {
            return $response;
        }
        $received = $response->getBody()->getContents();
        $this->calls[] = ['phase' => $this->phase, 'method' => $rpc['method'], 'tool' => $rpc['params']['name'] ?? null,
            'requestBodyBytes' => \strlen($sent), 'responseBodyBytes' => \strlen($received), 'httpStatus' => $response->getStatusCode()];

        return $response->withBody(Stream::create($received));
    }
}
