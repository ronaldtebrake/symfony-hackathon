<?php

declare(strict_types=1);

namespace App\Demo\Refund;

use App\Demo\GroupSelection\Discovery;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Operator for the disposable mid-review experiment. No operator control is exposed over MCP. */
final class ProgrammaticCapture
{
    public static function record(string $directory, Request $request, Response $response, array $operations): void
    {
        if (!$request->isMethod('POST') || '/mcp' !== $request->getPathInfo()) {
            return;
        }
        $message = json_decode($request->getContent(), true);
        $method = $message['method'] ?? null;
        if (!\in_array($method, ['initialize', 'server/discover', 'tools/list', 'tools/call', 'groups/list', 'groups/get'], true)) {
            return;
        }
        $body = (string) $response->getContent();
        if (preg_match('/^data: ?(.+)$/m', $body, $match)) {
            $body = $match[1];
        }
        $envelope = json_decode($body, true);
        $reply = $envelope['result'] ?? [];
        $entry = ['method' => $method, 'httpStatus' => $response->getStatusCode()];
        if ('tools/call' !== $method) {
            if ('tools/list' === $method || 'groups/get' === $method) {
                $entry['tools'] = array_column($reply['tools'] ?? [], 'name');
            }
            if ('groups/list' === $method) {
                $entry['groups'] = array_column($reply['groups'] ?? [], 'name');
            }
            self::append($directory, $entry);

            return;
        }
        $name = $message['params']['name'] ?? null;
        $args = $message['params']['arguments'] ?? [];
        $read = null;
        if (\is_array($args) && ['calls'] === array_keys($args) && \is_array($args['calls']) && [0] === array_keys($args['calls'])) {
            $call = $args['calls'][0];
            if (\is_array($call) && 2 === \count($call)) {
                $read = match ($call['arguments'] ?? null) {
                    ['orderId' => 'R-1042'] => 'refund_get_order',
                    ['policyId' => 'standard-eu'] => 'refund_get_policy',
                    default => null,
                };
                if ($read !== ($call['name'] ?? null)) {
                    $read = null;
                }
            }
        }
        $query = \is_array($args) && ['query'] === array_keys($args) && \is_string($args['query']) && preg_match('/\A[A-Za-z -]{1,80}\z/D', $args['query']);
        $entry['tool'] = \in_array($name, Discovery::CATALOG, true) ? $name : '[other]';
        $entry['arguments'] = $query || ['group' => 'refund-review'] === $args || null !== $read ? $args : ['redacted' => true];
        $entry['isError'] = $reply['isError'] ?? !isset($envelope['result']);
        $result = $reply['structuredContent'] ?? [];
        // Only publish responses to the synthetic reads named above and the app's own discovery data.
        if ('search_tools' === $name) {
            $entry['result'] = ['tools' => array_column($result['tools'] ?? [], 'name')];
            if (isset($result['groups'])) {
                $entry['result']['groups'] = array_column($result['groups'], 'name');
            }
            if ('refund-review' === ($result['group'] ?? null)) {
                $entry['result']['group'] = $result['group'];
                $entry['result']['instructions'] = $result['instructions'];
            }
        } elseif ('execute_tools' === $name && null !== $read) {
            $entry['result'] = $result;
        }
        $operation = $operations[0] ?? [];
        $entry['broker'] = array_map(static fn (array $call): array => array_intersect_key($call, array_flip(['name', 'status', 'reason'])), $operation['calls'] ?? []);
        $entry['nativeEvents'] = $operation['nativeEvents'] ?? [];
        self::append($directory, $entry);

        if ('execute_tools' === $name && !$entry['isError'] && 'refund_get_order' === $read
            && [['name' => 'refund_get_order', 'status' => 'succeeded']] === $entry['broker']
            && ['requested', 'succeeded'] === array_column($entry['nativeEvents'], 'phase')
            && 'R-1042' === ($result['results'][0]['value']['orderId'] ?? null)) {
            $permission = $directory.'/permission';
            if ("granted\n" !== file_get_contents($permission)) {
                throw new \RuntimeException('Unexpected grant state at the order boundary.');
            }
            (new Filesystem())->dumpFile($permission, "revoked\n");
            if ("revoked\n" !== file_get_contents($permission)) {
                throw new \RuntimeException('The operator could not confirm revocation.');
            }
            self::append($directory, ['operator' => 'revoke', 'permission' => 'refund.review', 'previous' => 'granted', 'current' => 'revoked']);
        }
    }

    private static function append(string $directory, array $row): void
    {
        (new Filesystem())->appendToFile($directory.'/programmatic.jsonl', json_encode($row, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n", true);
    }
}
