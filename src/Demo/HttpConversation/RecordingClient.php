<?php

declare(strict_types=1);

namespace App\Demo\HttpConversation;

use CodeMode\Symfony\Profiler\CatalogDataCollector;
use Nyholm\Psr7\Stream;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;

/** Capture only JSON-RPC bodies and our bounded collector. Never export HTTP headers or other profiles. */
final class RecordingClient implements ClientInterface
{
    public string $phase = 'setup';
    private array $records = [];
    private array $profileTokens = [];

    public function __construct(private readonly ClientInterface $inner, private readonly string $profilerDirectory)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $started = hrtime(true);
        $response = $this->inner->sendRequest($request);
        $rpc = json_decode((string) $request->getBody(), true);
        if ('POST' !== $request->getMethod() || !isset($rpc['id'])) {
            return $response;
        }
        if (\count($this->records) >= 16) {
            throw new \RuntimeException('HTTP capture limit exceeded.');
        }
        $raw = $response->getBody()->read(131073);
        if (\strlen($raw) > 131072) {
            throw new \RuntimeException('HTTP response exceeds the capture limit.');
        }
        $body = $raw;
        if (preg_match('/^data: (.+)$/m', $raw, $match)) {
            $body = $match[1];
        }
        $decoded = json_decode($body, true);
        // Unexpected debug/error bodies are not safe evidence exports.
        if (200 !== $response->getStatusCode() || !\is_array($decoded) || !isset($decoded['result']) || ($decoded['id'] ?? null) !== $rpc['id']) {
            throw new \RuntimeException('Unexpected MCP HTTP response.');
        }
        $result = $decoded['result'];
        if ('tools/list' === $rpc['method'] && isset($result['nextCursor'])) {
            throw new \UnexpectedValueException('Unexpected extra page in the two-tool catalog.');
        }
        if ('tools/call' === $rpc['method']) {
            $text = $result['content'][0]['text'] ?? null;
            if (!\is_string($text) || 1 !== \count($result['content']) || 'text' !== ($result['content'][0]['type'] ?? null)
                || !isset($result['structuredContent']) || json_decode($text, true, flags: \JSON_THROW_ON_ERROR) !== $result['structuredContent']) {
                throw new \UnexpectedValueException('MCP text and structured content disagree.');
            }
        }
        $this->records[] = ['phase' => $this->phase, 'request' => $rpc, 'response' => $decoded,
            'httpStatus' => $response->getStatusCode(), 'durationMs' => (hrtime(true) - $started) / 1e6];
        $token = $response->getHeaderLine('X-Debug-Token');
        $this->profileTokens[] = preg_match('/\A[a-f0-9]{6}\z/D', $token) ? $token : null;

        return $response->withBody(Stream::create($raw));
    }

    public function records(): array
    {
        if ([] === $this->records) {
            return [];
        }
        $storage = new FileProfilerStorage('file:'.$this->profilerDirectory);
        // HTTP bodies can arrive before kernel.terminate finishes writing the profile.
        // Keep one bounded deadline for the whole snapshot; absent evidence stays absent.
        $deadline = hrtime(true) + 1_000_000_000;
        $records = $this->records;
        foreach ($records as $index => &$row) {
            if ('tools/call' !== $row['request']['method']) {
                continue;
            }
            $row['profiler'] = null;
            $token = $this->profileTokens[$index];
            if (null === $token) {
                continue;
            }
            do {
                $profile = $storage->read($token);
                if (null !== $profile) {
                    break;
                }
                usleep(10000);
            } while (hrtime(true) < $deadline);
            if (null !== $profile) {
                if ($profile->hasCollector('code_mode') && ($collector = $profile->getCollector('code_mode')) instanceof CatalogDataCollector) {
                    $row['profiler'] = ['operations' => $collector->getOperations(), 'dropped' => $collector->getDropped()];
                }
            }
        }

        return $records;
    }
}
