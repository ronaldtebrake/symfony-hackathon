<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\GroupSelection\Proof;
use App\Demo\GroupSelection\Study;
use App\Demo\Refund\Fixture;
use App\Demo\Refund\Gateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GroupSelectionTest extends TestCase
{
    #[DataProvider('invalidGuidedCaptures')]
    public function testGuidedCaptureMustShowTheProcedureAndTheDependentRead(bool $delivered, string $error): void
    {
        $report = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/group-selection.json'), true, flags: \JSON_THROW_ON_ERROR);
        $matches = array_filter($report['attempts'], static fn (array $a): bool => 'groups' === $a['arm'] && 'refund' === $a['case'] && 'passed' === $a['status']);
        $attempt = reset($matches);
        foreach ($attempt['calls'] as &$call) {
            if ('groups/get' !== $call['request']['method'] || 'refund-review' !== $call['request']['params']['name']) {
                continue;
            }
            $call['response']['result']['instructions'] = Fixture::instructions();
            $call['responseJson'] = json_encode($call['response'], \JSON_THROW_ON_ERROR);
            $call['responseSha256'] = hash('sha256', $call['responseJson']);
            if ($delivered) {
                $message = json_encode(['available' => array_column($call['response']['result']['tools'], 'name'), 'instructions' => Fixture::instructions()], \JSON_THROW_ON_ERROR);
                $attempt['requestEvidence'][$call['modelRequest']]['toolMessages'][] = ['bytes' => \strlen($message), 'sha256' => hash('sha256', $message)];
                $reads = array_keys(array_filter($attempt['calls'], static fn (array $r): bool => 'tools/call' === $r['request']['method']));
                $attempt['calls'][$reads[1]]['modelRequest'] = $attempt['calls'][$reads[0]]['modelRequest'];
            }
            break;
        }
        unset($call);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($error);
        Proof::attempt($attempt, guided: true);
    }

    public static function invalidGuidedCaptures(): iterable
    {
        yield 'server sends procedure but model never sees it' => [false, 'procedure delivered as a tool result'];
        yield 'both reads requested in the same model turn' => [true, 'followed its policy on a later model request'];
    }

    public function testDisclosureBeforeAGroupWasOpenedInvalidatesTheCapture(): void
    {
        $report = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/group-selection.json'), true, flags: \JSON_THROW_ON_ERROR);
        Proof::verify($report);
        $index = array_key_first(array_filter($report['attempts'], static fn (array $a): bool => 'groups' === $a['arm'] && 'passed' === $a['status']));
        $this->assertNotNull($index);
        $report['attempts'][$index]['inputs'][0]['definitions'][] = ['name' => 'symfony_refund_get_policy'];

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('model-visible tool definitions');
        Proof::verify($report);
    }

    public function testASameBatchGuessCannotEstablishThatTheModelFollowedThePolicyReference(): void
    {
        $report = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/group-selection.json'), true, flags: \JSON_THROW_ON_ERROR);
        $matches = array_filter($report['attempts'], static fn (array $a): bool => 'catalog' === $a['arm'] && 'refund' === $a['case'] && 'passed' === $a['status']);
        $attempt = reset($matches);
        $reads = array_values(array_filter($attempt['calls'], static fn (array $r): bool => 'execute_tools' === ($r['request']['params']['name'] ?? null)));
        [$order, $policy] = $reads;
        $order['request']['params']['arguments']['calls'][] = $policy['request']['params']['arguments']['calls'][0];
        $order['response']['result']['structuredContent']['results'][] = $policy['response']['result']['structuredContent']['results'][0];
        $this->assertFalse(Study::score('refund', $attempt['answer'], [$order])['grounded']);
    }

    public function testBothDiscoveryRoutesShareTheCallBudgetAndHonorRevocation(): void
    {
        $fixture = new Fixture(\dirname(__DIR__, 2), null, 'selection');
        try {
            $rpc = static fn (string $user, string $method, array $params = []): array => $fixture->rpc($user, $method, $params)['response'];
            $call = static fn (string $user, string $name, array $arguments): array => $rpc($user, 'tools/call', ['name' => $name, 'arguments' => $arguments])['result'];
            $policy = ['name' => 'refund_get_policy', 'arguments' => ['policyId' => 'standard-eu']];
            $this->assertSame([], $rpc('viewer', 'groups/list')['result']['groups']);
            $this->assertSame([], $call('viewer', 'search_tools', ['query' => ''])['structuredContent']['tools']);
            $this->assertSame('discovery_required', $call('reader', 'execute_tools', ['calls' => [$policy]])['structuredContent']['error']['reason']);
            $this->assertCount(2, $call('reader', 'search_tools', ['query' => ''])['structuredContent']['tools']);
            $this->assertCount(1, $rpc('reader', 'groups/get', ['name' => 'policy-lookup'])['result']['tools']);
            $this->assertSame(30, $call('reader', 'execute_tools', ['calls' => [$policy]])['structuredContent']['results'][0]['value']['returnWindowDays']);
            $fixture->revoke();
            $this->assertSame([], $rpc('reader', 'groups/list')['result']['groups']);
            $this->assertArrayHasKey('error', $rpc('reader', 'groups/get', ['name' => 'policy-lookup']));
            $this->assertSame([], $call('reader', 'search_tools', ['query' => ''])['structuredContent']['tools']);
            for ($i = 1; $i < Gateway::LIMIT; ++$i) {
                $blocked = 0 === $i % 2 ? $call('reader', 'execute_tools', ['calls' => [$policy]]) : $call('reader', $policy['name'], $policy['arguments']);
                $this->assertTrue($blocked['isError']);
                $this->assertSame('tool_denied', $blocked['structuredContent']['error']['reason']);
                $this->assertSame(0, $blocked['structuredContent']['completedCalls']);
            }
            $this->assertSame('execution_limit', $call('reader', 'execute_tools', ['calls' => [$policy]])['structuredContent']['error']['reason']);
        } finally {
            $fixture->close();
        }
    }
}
