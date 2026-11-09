<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SurfaceProof\ClientCapture;
use SurfaceProof\ConversationVerifier;

foreach (['Connection', 'Verifier', 'Conversation', 'ConversationVerifier', 'ClientCapture'] as $class) {
    require_once dirname(__DIR__).'/src/'.$class.'.php';
}

final class ClientCaptureTest extends TestCase
{
    private function report(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/proof/client-conversation.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    public function testLiveReferenceLinksBothAnswersToActualPhpAndMcp(): void
    {
        self::assertNotContains(false, (new ClientCapture())->check($this->report()));
        self::assertContains(false, (new ClientCapture())->check([]));
        self::assertContains(false, (new ConversationVerifier())->check([]));
    }

    public function testMissingOrChangedEvidenceCannotPass(): void
    {
        foreach (['client', 'events', 'host', 'after', 'executions', 'upstreamCalls'] as $key) {
            $report = $this->report();
            unset($report[$key]);
            self::assertContains(false, (new ClientCapture())->check($report), $key);
        }
        $changes = [
            static function (&$r) { $r['executions'][0]['trace']['value']['draft']['count'] = 1; },
            static function (&$r) { $r['executions'][1]['trace']['value']['count'] = 4; },
            static function (&$r) { $r['executions'][0]['code'] = 'return [];'; },
            static function (&$r) { $r['executions'][1]['trace']['containerRemoved'] = false; },
            static function (&$r) { $r['upstreamCalls'][0]['arguments']['values']['keywords'] = 'different'; },
            static function (&$r) { $r['upstreamCalls'][0]['value'] = ['matches' => [['title' => 'PRIVATE']], 'count' => 1]; },
            static function (&$r) { $r['client']['threadId'] = 'another-conversation'; },
            static function (&$r) { $r['after']['account']['permissions'][] = 'bypass node access'; },
            static function (&$r) { $r['host']['limits']['executionAttempts'] = 3; },
            static function (&$r) { $r['events'][0]['item']['content'][0]['text'] = 'A different request'; },
        ];
        foreach ($changes as $i => $change) {
            $report = $this->report();
            $change($report);
            self::assertContains(false, (new ClientCapture())->check($report), 'Tampering '.$i);
        }
    }
}
