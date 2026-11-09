<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\Performance\{ConversationVerifier, TokenBreakdown, RequestCollector};
use App\Demo\ReportStore;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PerformanceConversationTest extends WebTestCase
{
    private function evidence(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/performance-conversation.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testRealConversationAndFollowUpPassWhileFirstBudgetFailureIsRetained(): void
    {
        $r = $this->evidence();
        self::assertTrue((new ConversationVerifier())->verify($r)['passed']);
        self::assertSame(6, $r['trace']['modelRequests']);
        self::assertSame(14694, $r['trace']['providerTokens']['total']);
        self::assertCount(8, $r['trace']['brokerCalls']);
        self::assertSame('execution_limit', $r['liveAttemptLimit']);
        self::assertCount(1, $r['previousAttempts']);
        $failed = $r['previousAttempts'][0];
        self::assertSame('failed', $failed['status']);
        self::assertSame(12, $failed['trace']['modelRequests']);
        self::assertSame(20439, $failed['trace']['providerTokens']['total']);
        self::assertFalse((new ConversationVerifier())->verify($failed)['passed']);
        self::assertTrue($failed['securityProof']['verification']['passed']);
    }

    public static function tampering(): iterable
    {
        foreach (['php', 'answer', 'hash', 'ledger', 'cleanup', 'usage', 'provider_answer', 'provider_context', 'budget', 'permissions', 'schema', 'entry_tools', 'denial', 'canary', 'turn_range'] as $case) { yield $case => [$case]; }
    }

    #[DataProvider('tampering')]
    public function testInconsistentOrWeakenedEvidenceCannotPass(string $case): void
    {
        $r = $this->evidence();
        match ($case) {
            'php' => $r['trace']['programs'][0] = 'return 13;',
            'answer' => $r['turns'][1]['answer'] = '{"sameOutput":false}',
            'hash' => $r['dataset']['profiles']['profile-02']['outputSha256'] = str_repeat('0', 64),
            'ledger' => $r['trace']['executions'][0]['toolCalls'] = [],
            'cleanup' => $r['trace']['executions'][0]['containerRemoved'] = false,
            'usage' => ++$r['trace']['providerTokens']['total'],
            'provider_answer' => $r['trace']['providerResponses'][5]['answerSha256'] = str_repeat('a', 64),
            'provider_context' => $r['trace']['requestEvidence'][4]['assistantMessageSha256'] = [],
            'budget' => $r['limits']['maxToolCalls'] = 100,
            'permissions' => $r['identity']['capabilities'][] = 'admin',
            'schema' => $r['approvedTools'][0]['outputSchema']['additionalProperties'] = true,
            'entry_tools' => $r['entryTools'][0]['function']['description'] = 'Invented interface',
            'denial' => $r['securityProof']['denials'][0]['trace']['error']['reason'] = 'tool_failed',
            'canary' => $r['trace']['programs'][0] .= RequestCollector::CANARY,
            'turn_range' => $r['turns'][1]['agentCallStart'] = 0,
        };
        self::assertFalse((new ConversationVerifier())->verify($r)['passed']);
    }

    public function testOfflineCountsBindEachPayloadAndDoNotPretendToEqualProviderUsage(): void
    {
        $r = $this->evidence(); $counter = new TokenBreakdown();
        $summary = $counter->summarize($r);
        self::assertSame('verified', $summary['status']);
        self::assertSame([386, 1233, 552, 498], array_column($summary['groups'], 'tokens'));
        self::assertNotSame($r['trace']['providerTokens']['total'], array_sum(array_column($summary['groups'], 'tokens')));
        self::assertSame('verified', $counter->summarize($r['previousAttempts'][0])['status']);
        self::assertSame('Other tool responses', $counter->summarize($r['previousAttempts'][0])['groups'][4]['category']);
        $r['trace']['agentCalls'][0]['result'] .= ' ';
        self::assertSame('stale', $counter->summarize($r)['status']);
        $r = $this->evidence(); $r['tokenBreakdown']['samples'][0]['tokens'] = -1;
        self::assertSame('stale', $counter->summarize($r)['status']);
    }

    public function testLocalFailureOverridesReviewedCaptureButCannotEnterStaticExport(): void
    {
        $dir = sys_get_temp_dir().'/performance-conversation-'.bin2hex(random_bytes(8));
        mkdir($dir.'/docs/evidence', 0700, true);
        try {
            $bytes = json_encode($this->evidence(), JSON_THROW_ON_ERROR);
            file_put_contents($dir.'/docs/evidence/performance-conversation.json', $bytes);
            $store = new ReportStore($dir);
            self::assertSame($bytes, $store->readPerformanceConversation());
            $store->savePerformanceConversation(['status' => 'failed']);
            self::assertSame('failed', json_decode($store->readPerformanceConversation(), true)['status']);
            self::assertSame($bytes, (new ReportStore($dir, reviewedOnly: true))->readPerformanceConversation());
        } finally { (new Filesystem())->remove($dir); }
    }

    public function testViewerRechecksEvidenceAndRemainsLocalReadOnly(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/performance-conversation?file=../../.env.local');
        self::assertResponseIsSuccessful();
        $r = json_decode($client->getResponse()->getContent(), true);
        $selected = $r['previousAttempts'][$r['defaultAttempt']] ?? $r;
        self::assertTrue($selected['verification']['passed']);
        self::assertSame('verified', $r['payloadMeasurement']['status']);
        self::assertFalse($r['previousAttempts'][0]['verification']['passed']);
        $client->request('POST', '/demo/performance-conversation'); self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/performance-conversation', server: ['REMOTE_ADDR' => '203.0.113.1']); self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/demo/performance-conversation', server: ['HTTP_HOST' => 'attacker.example']); self::assertResponseStatusCodeSame(403);
    }
}
