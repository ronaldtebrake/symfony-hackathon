<?php

declare(strict_types=1);

namespace Composition;

use CodeMode\Mcp\McpGateway;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;

final readonly class MediaConnection
{
    public function __construct(private string $projectDir, private ReadPolicy $policy)
    {
    }

    public function gateway(): McpGateway
    {
        $directory = $this->projectDir;

        return new McpGateway(static function () use ($directory): Client {
            $client = Client::builder()->setClientInfo('composition-consumer', '1.0')->setInitTimeout(2)->setRequestTimeout(2)->setMaxRetries(0)->build();
            try {
                // The server receives no inherited provider credentials. Command/account are host choices.
                $client->connect(new StdioTransport(\PHP_BINARY, [$directory.'/bin/media-server'], $directory, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'], maxBufferSize: 65536));

                return $client;
            } catch (\Throwable) {
                $client->disconnect();
                throw new \RuntimeException('Configured media server unavailable.');
            }
        }, ['cms_get_media' => fn ($identity, $args) => $this->policy->isGranted($identity, 'cms_get_media', $args)]);
    }
}
