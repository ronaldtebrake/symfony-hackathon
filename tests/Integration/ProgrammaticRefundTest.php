<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\Refund\Fixture;
use PHPUnit\Framework\TestCase;

final class ProgrammaticRefundTest extends TestCase
{
    public function testOperatorRevokesBetweenLinkedReads(): void
    {
        $fixture = new Fixture(\dirname(__DIR__, 2), null, 'catalog', 0);
        try {
            $fixture->serveRecording(static function (string $directory) use ($fixture): void {
                touch($directory.'/revoke-after-order');
                $client = $fixture->connectReader();
                try {
                    $group = $client->callTool('search_tools', ['group' => 'refund-review'])->structuredContent;
                    self::assertSame(['refund_get_order', 'refund_get_policy'], array_column($group['tools'], 'name'));
                    $call = static fn (string $tool, array $args) => $client->callTool('execute_tools', ['calls' => [['arguments' => $args, 'name' => $tool]]]);
                    $order = $call('refund_get_order', ['orderId' => 'R-1042'])->structuredContent['results'][0]['value'];
                    self::assertSame("revoked\n", file_get_contents($directory.'/permission'));
                    $policy = $call('refund_get_policy', ['policyId' => $order['policyId']]);
                    self::assertTrue($policy->isError);
                    self::assertSame(['error' => ['reason' => 'tool_denied', 'tool' => 'refund_get_policy', 'index' => 0], 'completedCalls' => 0], $policy->structuredContent);
                    $rows = array_map(static fn (string $line) => json_decode($line, true, flags: \JSON_THROW_ON_ERROR), file($directory.'/programmatic.jsonl', \FILE_IGNORE_NEW_LINES));
                    $calls = array_values(array_filter($rows, static fn (array $row): bool => isset($row['operator']) || 'execute_tools' === ($row['tool'] ?? null)));
                    self::assertCount(3, $calls);
                    self::assertSame($order, $calls[0]['result']['results'][0]['value']);
                    self::assertSame(['requested', 'succeeded'], array_column($calls[0]['nativeEvents'], 'phase'));
                    self::assertSame(['operator' => 'revoke', 'permission' => 'refund.review', 'previous' => 'granted', 'current' => 'revoked'], $calls[1]);
                    self::assertSame([['name' => 'refund_get_policy', 'status' => 'denied', 'reason' => 'tool_denied']], $calls[2]['broker']);
                    self::assertSame([], $calls[2]['nativeEvents']);
                } finally {
                    $client->disconnect();
                    touch($directory.'/stop-recording');
                }
            });
        } finally {
            $fixture->close();
        }
    }
}
