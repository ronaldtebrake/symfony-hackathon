<?php

declare(strict_types=1);

namespace App\Demo\Mcp;

use Mcp\Client\Stateless\HeaderFactory;
use Mcp\Client\Stateless\RequestEnvelope;
use Mcp\Client\Stateless\ToolCatalog;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\MessageInterface;

/** Builds the wire messages recorded by the HTTP and stdio probes, including experimental methods. */
final readonly class CaptureRequestFactory
{
    private RequestEnvelope $envelope;
    private HeaderFactory $headers;

    /** @param list<string> $extensions */
    public function __construct(string $clientName, array $extensions)
    {
        $this->envelope = new RequestEnvelope(ProtocolVersion::V2026_07_28,
            new ClientCapabilities(extensions: array_fill_keys($extensions, new \stdClass())),
            new Implementation($clientName, '1'));
        $this->headers = new HeaderFactory(new ToolCatalog());
    }

    public function message(int $id, string $method, array $params = []): array
    {
        return $this->envelope->stamp(['jsonrpc' => MessageInterface::JSONRPC_VERSION, 'id' => $id, 'method' => $method, 'params' => $params]);
    }

    /** @return array<string, string> */
    public function headers(array $message): array
    {
        return $this->headers->forMessage($message, $this->envelope->protocolVersion());
    }
}
