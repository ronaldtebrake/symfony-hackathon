<?php

declare(strict_types=1);

namespace App\Demo;

use CodeMode\ExecutionIdentity;
use CodeMode\Mcp\McpGateway;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\StdioTransport;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

final readonly class BackendFactory
{
    public const FIXTURE_TOOLS = ['cms_list_articles', 'cms_get_articles', 'cms_get_media'];

    public function __construct(private string $projectDir) {}

    public function create(string $backend, bool $evaluation = false): McpGateway
    {
        if ($evaluation && 'fixture' !== $backend) { throw new \InvalidArgumentException('Evaluations use only the synthetic Symfony fixture.'); }
        $url = self::env('MCP_HTTP_URL');
        $token = self::env('MCP_HTTP_TOKEN');
        $names = self::FIXTURE_TOOLS;
        if ('http' === $backend) {
            $parts = parse_url($url);
            if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
                || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || ('http' === $parts['scheme'] && !in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true))) {
                throw new \InvalidArgumentException('Configure a trusted HTTPS MCP_HTTP_URL (HTTP is allowed only on loopback).');
            }
            $names = json_decode(self::env('MCP_HTTP_ALLOWED_TOOLS', '[]'), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($names) || !array_is_list($names) || [] === $names || count($names) > 16) {
                throw new \InvalidArgumentException('Configure an explicit MCP_HTTP_ALLOWED_TOOLS JSON list of read-only tool names approved by the host.');
            }
        } elseif ('fixture' !== $backend) {
            throw new \InvalidArgumentException('Choose the fixture or http backend.');
        }
        $policies = [];
        foreach ($names as $name) {
            if (!is_string($name) || !preg_match('/\A[a-zA-Z][a-zA-Z0-9_.-]{0,63}\z/D', $name)) {
                throw new \InvalidArgumentException('Invalid configured MCP tool name.');
            }
            $policies[$name] = static function (ExecutionIdentity $identity, \stdClass $arguments) use ($name, $evaluation): bool {
                if (!$identity->allows('content.audit')) { return false; }
                if ($evaluation && 'cms_get_articles' === $name) {
                    if (!is_array($arguments->ids ?? null)) { return false; }
                    foreach ($arguments->ids ?? [] as $id) {
                        if (!is_int($id) || $id < 1 || $id > 48) { return false; }
                    }
                }
                return true;
            };
        }
        $directory = $this->projectDir;
        return new McpGateway(static function () use ($backend, $directory, $url, $token, $evaluation): Client {
            $client = Client::builder()->setClientInfo('php-code-mode', '0.2.0')->setInitTimeout(2)->setRequestTimeout(2)->setMaxRetries(0)->build();
            if ('fixture' === $backend) {
                // A fully specified environment keeps provider credentials out of the fixture server.
                $transport = new StdioTransport(PHP_BINARY, [$directory.'/bin/demo-mcp-server', ...($evaluation ? ['--evaluation'] : [])], $directory, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'], maxBufferSize: 65536);
            } else {
                $headers = '' === $token ? [] : ['Authorization' => 'Bearer '.$token];
                $http = HttpClient::create(['timeout' => 2, 'max_duration' => 2, 'max_redirects' => 0]);
                $transport = new HttpTransport($url, $headers, new Psr18Client($http), maxSseBufferBytes: 65536);
            }
            try {
                $client->connect($transport);
                return $client;
            } catch (\Throwable) {
                $client->disconnect();
                throw new \RuntimeException('The configured MCP backend is unavailable.');
            }
        }, $policies);
    }

    public static function env(string $name, string $default = ''): string
    {
        return (string) ($_SERVER[$name] ?? $_ENV[$name] ?? (getenv($name) ?: $default));
    }
}
