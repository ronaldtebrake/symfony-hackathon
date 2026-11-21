<?php

declare(strict_types=1);

namespace App\Demo\Refund;

use App\Demo\GroupSelection\Discovery;
use CodeMode\Symfony\Profiler\CatalogDataCollector;
use CodeMode\Symfony\Profiler\CatalogTrace;
use Mcp\Schema\JsonRpc\Error;
use Symfony\AI\McpBundle\Profiler\DataCollector as McpDataCollector;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/** Collect only reviewed MCP diagnostics. The MCP transport never receives profiler headers or routes. */
final class RecordingProfiler
{
    public static function capture(string $directory, Request $request, Response $response, CatalogTrace $trace, float $durationMs): ?string
    {
        if (!$request->isMethod('POST') || '/mcp' !== $request->getPathInfo() || null !== $request->getQueryString()
            || !\in_array($request->getHost(), ['127.0.0.1', 'localhost'], true) || 200 !== $response->getStatusCode()) {
            return null;
        }
        $message = json_decode($request->getContent(), true);
        $method = $message['method'] ?? null;
        if (\in_array($method, ['tools/list', 'groups/list', 'groups/get'], true)) {
            $body = $response->getContent();
            if (\is_string($body) && \strlen($body) <= 65536) {
                if (preg_match('/^data: ?(.+)$/m', $body, $part)) {
                    $body = $part[1];
                }
                $reply = json_decode($body, depth: 40);
                $matched = '2.0' === ($message['jsonrpc'] ?? null) && '2.0' === ($reply->jsonrpc ?? null)
                    && isset($message['id']) && $message['id'] === ($reply->id ?? null);
                if ($matched && !property_exists($reply, 'result') && \is_int($reply->error->code ?? null) && \is_string($reply->error->message ?? null)) {
                    $reason = match ($reply->error->code) {
                        Error::RESOURCE_NOT_FOUND => 'resource_not_found',
                        Error::MISSING_REQUIRED_CLIENT_CAPABILITY => 'missing_client_capability',
                        Error::METHOD_NOT_FOUND => 'method_not_found',
                        Error::INVALID_PARAMS => 'invalid_params',
                        default => 'discovery_failed',
                    };
                    $trace->begin($method);
                    $trace->finish(['error' => ['reason' => $reason]], true, $durationMs);
                } elseif ($matched && \is_object($reply->result ?? null) && !property_exists($reply, 'error')) {
                    $groups = $reply->result->groups ?? [];
                    $tools = $reply->result->tools ?? [];
                    if (\is_array($groups) && \is_array($tools)) {
                        $name = $message['params']['name'] ?? null;
                        $selected = 'groups/get' === $method && \is_string($name) && isset(Discovery::GROUPS[$name]) ? $name : null;
                        $instructions = $reply->result->instructions ?? null;
                        $reviewed = 'refund-review' === $selected && Tools::NAMES === array_column($tools, 'name')
                            && \is_string($instructions) && Fixture::instructions() === $instructions;
                        $trace->discoveryPage($method, \count($groups), \count($tools), $durationMs,
                            'groups/list' === $method ? self::groupSummaries($groups) : [], $selected, 'groups/list' === $method ? [] : $tools,
                            $reviewed ? $instructions : null);
                    }
                }
            }
        }
        if ([] === $trace->snapshot()['operations']) {
            return null;
        }
        $profiler = new Profiler(self::storage($directory));
        $profiler->add(new CatalogDataCollector($trace, self::reviewedExchange($message, $response)));
        // This fixture registers its catalog dynamically. The bundle has no static registry to enumerate.
        $empty = new ServiceLocator([]);
        $profiler->add(new McpDataCollector($empty, $empty));
        // Symfony normally emits X-Debug-Token on the response it collects.
        $profile = $profiler->collect($request, clone $response);
        if (null === $profile || !$profiler->saveProfile($profile)) {
            throw new \RuntimeException('Cannot store the local Symfony profile.');
        }
        if (is_file($directory.'/share-dev-profiles') && ['code_mode', 'mcp'] === array_keys($profile->getCollectors())) {
            // The operator's dev app can open this request without access to the fixture directory.
            $store = new FileProfilerStorage('file:'.\dirname(__DIR__, 3).'/examples/symfony/var/cache/dev/profiler');
            try {
                $copy = clone $profile;
                while (null !== $store->read($copy->getToken())) {
                    $copy->setToken(bin2hex(random_bytes(3)));
                }
                if (!$store->write($copy)) {
                    error_log('The local dev profiler could not index the refund recording.');
                }
            } catch (\RuntimeException) {
                error_log('The local dev profiler is unavailable. The recording remains in the refund fixture.');
            }
        }

        return $profile->getToken();
    }

    public static function operations(string $directory, string $token): array
    {
        if (!preg_match('/\A[0-9a-f]{6}\z/D', $token)) {
            throw new \InvalidArgumentException('Invalid local profile token.');
        }
        $profile = self::storage($directory)->read($token);
        if (null === $profile || !$profile->hasCollector('code_mode') || !($collector = $profile->getCollector('code_mode')) instanceof CatalogDataCollector) {
            throw new \RuntimeException('The Code Mode collector is missing from the Symfony profile.');
        }

        return $collector->getOperations();
    }

    /** Render the real WebProfilerBundle panel offline. Only the operator can use this kernel. */
    public static function panels(string $directory, ?array $expectedOperations = null): array
    {
        if (!is_dir($directory.'/profiles')) {
            return [];
        }
        $fs = new Filesystem();
        $rows = array_reverse(self::storage($directory)->find(null, null, 256, 'POST'));
        if (null !== $expectedOperations && \count($rows) !== \count($expectedOperations)) {
            throw new \RuntimeException('The number of stored Symfony profiles differs from the request traces.');
        }
        $kernel = new ProfilerViewerKernel($directory);
        $paths = [];
        try {
            foreach ($rows as $index => $row) {
                $token = $row['token'];
                $collected = self::operations($directory, $token);
                if (null !== $expectedOperations && json_encode($collected, \JSON_THROW_ON_ERROR) !== json_encode([$expectedOperations[$index]], \JSON_THROW_ON_ERROR)) {
                    throw new \RuntimeException('Stored Symfony profile does not match the corresponding MCP request trace.');
                }
                $operation = $collected[0];
                $request = Request::create('/_profiler/'.$token.'?panel=code_mode');
                $reply = $kernel->handle($request);
                if (200 !== $reply->getStatusCode()) {
                    throw new \RuntimeException('The local Symfony profiler panel could not be rendered.');
                }
                $name = preg_replace('/[^a-zA-Z0-9_-]/', '-', $operation['operation']);
                $path = \sprintf('%s/panels/%03d-%s-%s.html', $directory, \count($paths) + 1, $name, $operation['status']);
                $fs->dumpFile($path, $reply->getContent());
                $paths[] = $path;
                $kernel->terminate($request, $reply);
            }
        } finally {
            $kernel->shutdown();
        }

        return $paths;
    }

    /** Only the public synthetic inputs and result fields used in the judge demo may enter storage. */
    private static function reviewedExchange(array $message, Response $response): array
    {
        if ('tools/call' !== ($message['method'] ?? null)) {
            return [];
        }
        $params = $message['params'] ?? [];
        $args = $params['arguments'] ?? [];
        if ('search_tools' === ($params['name'] ?? null)) {
            if (isset($args['query'])) {
                return ['Search query' => \in_array($args['query'], ['refund', 'refund procedure', 'refund review', 'refund-review'], true) ? $args['query'] : '[redacted]'];
            }

            return [];
        }
        if ('execute_tools' !== ($params['name'] ?? null) || 1 !== \count((array) ($args['calls'] ?? []))) {
            return [];
        }
        $call = $args['calls'][0] ?? [];
        $input = $call['arguments'] ?? null;
        $tool = $call['name'] ?? '';
        $allowed = match ($tool) {
            'refund_get_order' => [['orderId' => 'R-1042'], ['orderId' => 'R-1043']],
            'refund_get_policy' => [['policyId' => 'standard-eu']],
            default => [],
        };
        if (!\in_array($input, $allowed, true)) {
            return [];
        }
        $exchange = ['Requested read' => ['name' => $tool, 'arguments' => $input]];
        $body = $response->getContent();
        if (!\is_string($body) || \strlen($body) > 65536) {
            return $exchange;
        }
        $reply = json_decode(preg_match('/^data: ?(.+)$/m', $body, $part) ? $part[1] : $body, true, 40);
        if (!isset($message['id']) || ($reply['id'] ?? null) !== $message['id'] || ($reply['result']['isError'] ?? false)) {
            return $exchange;
        }
        $result = $reply['result']['structuredContent']['results'][0] ?? [];
        if (($result['name'] ?? null) !== $tool) {
            return $exchange;
        }
        $expected = 'refund_get_order' === $tool ? ['orderId' => 'R-1042', 'policyId' => 'standard-eu', 'amountMinor' => 7500] : ['policyId' => 'standard-eu', 'manualReviewAboveMinor' => 5000];
        foreach ($expected as $key => $publicValue) {
            if (($result['value'][$key] ?? null) === $publicValue) {
                $exchange['Returned fields'][$key] = $publicValue;
            }
        }

        return $exchange;
    }

    /** Project only the public group summaries owned by this application. */
    private static function groupSummaries(array $groups): array
    {
        $summaries = [];
        foreach (\array_slice($groups, 0, \count(Discovery::GROUPS)) as $group) {
            $name = $group->name ?? null;
            $description = $group->description ?? null;
            if (\is_string($name) && isset(Discovery::GROUPS[$name])
                && (Discovery::GROUPS[$name]['description'] === $description || (Discovery::GROUPS[$name]['procedureDescription'] ?? null) === $description
                    || (Group::ENTRY['name'] === $name && Group::ENTRY['description'] === $description))) {
                $summaries[] = ['name' => $name, 'description' => $description];
            }
        }

        return $summaries;
    }

    private static function storage(string $directory): FileProfilerStorage
    {
        return new FileProfilerStorage('file:'.$directory.'/profiles');
    }
}
