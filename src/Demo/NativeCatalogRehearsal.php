<?php

declare(strict_types=1);

namespace App\Demo;

/** Checks recorded MCP messages; never runs a tool or trusts saved success flags. */
final class NativeCatalogRehearsal
{
    public static function verify(array $capture): array
    {
        $calls = $capture['calls'] ?? [];
        $search = $calls[0]['response']['structuredContent'] ?? [];
        $allowed = $calls[1]['response']['structuredContent'] ?? [];
        $denied = $calls[2]['response']['structuredContent'] ?? [];
        $messagesAgree = 3 === \count($calls);
        foreach ($calls as $call) {
            try {
                $text = json_decode($call['response']['content'][0]['text'] ?? '', true, flags: \JSON_THROW_ON_ERROR);
                $messagesAgree = $messagesAgree && $text === ($call['response']['structuredContent'] ?? null);
            } catch (\JsonException) {
                $messagesAgree = false;
            }
        }
        $checks = [
            'nativeCaptureFormat' => 'native-catalog-rehearsal/v1' === ($capture['format'] ?? null),
            'threeCallsInOrder' => ['search_tools', 'execute_tools', 'execute_tools'] === array_column($calls, 'tool'),
            'discoveredApprovedContract' => ['query' => 'publication'] === ($calls[0]['arguments'] ?? null)
                && false === ($calls[0]['response']['isError'] ?? null)
                && ['publication_read'] === array_column($search['tools'] ?? [], 'name')
                && ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id'], 'additionalProperties' => false] === ($search['tools'][0]['inputSchema'] ?? null),
            'publishedReadReturned' => self::read(101) === ($calls[1]['arguments'] ?? null)
                && false === ($calls[1]['response']['isError'] ?? null)
                && ['results' => [['name' => 'publication_read', 'value' => ['id' => 101, 'title' => 'A Symfony application owns its tools']]]] === $allowed,
            'restrictedReadDeniedWithoutData' => self::read(202) === ($calls[2]['arguments'] ?? null)
                && true === ($calls[2]['response']['isError'] ?? null)
                && ['error' => ['reason' => 'tool_denied', 'tool' => 'publication_read', 'index' => 0], 'completedCalls' => 0] === $denied,
            'textAndStructuredResponsesAgree' => $messagesAgree,
        ];

        return ['passed' => !\in_array(false, $checks, true), 'checks' => $checks];
    }

    private static function read(int $id): array
    {
        return ['calls' => [['name' => 'publication_read', 'arguments' => ['id' => $id]]]];
    }
}
