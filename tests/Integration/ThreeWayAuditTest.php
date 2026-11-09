<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\{AuditDataset, BackendFactory, ThreeWayAuditRunner, ThreeWayAuditVerifier};
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\{PreserveGlobalState, RunInSeparateProcess};
use App\Demo\ThreeWayAuditPrompt;

final class ThreeWayAuditTest extends WebTestCase
{
    // Large evidence inspections must not inflate the heap inherited by later 64 MiB broker tests.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testViewerRechecksTheCompleteEvidence(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/audit-three-way');
        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(ThreeWayAuditRunner::PROTOCOL, $body['kind']);
        self::assertNotEmpty($body['attempts']);
    }

    public function testViewerRemainsLocalAndReadOnly(): void
    {
        $client = self::createClient();
        $client->request('POST', '/demo/audit-three-way'); self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/audit-three-way', server: ['REMOTE_ADDR' => '203.0.113.1']); self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/demo/audit-three-way', server: ['HTTP_HOST' => 'attacker.example']); self::assertResponseStatusCodeSame(403);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReviewedFailuresAndSuccessfulPathsKeepTheirMeasuredCosts(): void
    {
        $report = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/audit-three-way.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertCount(13, $report['attempts']);
        $total = 0;
        foreach (array_slice($report['attempts'], 0, 2) as $index => $attempt) {
            self::assertFalse(ThreeWayAuditVerifier::compare($attempt)['passed']);
            foreach ($attempt['runs'] as $path => $run) {
                $verified = ThreeWayAuditVerifier::verify($run);
                self::assertSame(0 === $index && 'direct' !== $path, $verified['passed'], $path.' '.json_encode($verified));
                self::assertSame(0, $verified['unreportedRequests']);
                $total += $verified['knownProviderTokens']['total'];
            }
        }
        self::assertSame(202196, $total);
        $matched = $report['attempts'][2];
        self::assertTrue(ThreeWayAuditVerifier::compare($matched)['passed']);
        foreach (['direct' => [12, 49595, false], 'batch' => [11, 44866, false], 'code' => [5, 13652, true]] as $path => [$requests, $tokens, $draftCorrect]) {
            $run = $matched['runs'][$path];
            self::assertSame($requests, $run['result']['modelRequests']);
            self::assertSame($tokens, $run['result']['providerTokens']['total']);
            self::assertCount(10, $run['result']['brokerCalls']);
            self::assertSame($draftCorrect, (new \App\Demo\AuditVerifier())->verify(json_decode($run['draft']['answer'], true), 'fixture', $run['result']['brokerCalls'])['passed']);
        }
        $mixed = $matched; $mixed['runs']['code'] = $report['attempts'][0]['runs']['code'];
        self::assertFalse(ThreeWayAuditVerifier::compare($mixed)['checks']['protocolMatched']);
        foreach (array_slice($report['attempts'], 3, 2) as $index => $attempt) {
            self::assertSame(ThreeWayAuditPrompt::CATALOG, $attempt['protocol']);
            self::assertFalse(ThreeWayAuditVerifier::compare($attempt)['passed']);
            self::assertSame(['search_tools', 'execute_tools'], $attempt['runs']['batch']['entryTools']);
            foreach ($attempt['runs'] as $path => $run) {
                $verified = ThreeWayAuditVerifier::verify($run);
                self::assertSame(1 === $index && 'direct' === $path, $verified['passed'], $path.' '.json_encode($verified));
                self::assertSame(0, $verified['unreportedRequests']);
            }
            foreach (['direct', 'code'] as $path) {
                self::assertSame($matched['runs'][$path]['systemPrompt'], $attempt['runs'][$path]['systemPrompt']);
            }
        }
        $knownTokens = $missingUsage = 0;
        foreach ($report['attempts'] as $index => $attempt) {
            self::assertSame(in_array($index, [2, 12], true), ThreeWayAuditVerifier::compare($attempt)['passed']);
            foreach ($attempt['runs'] as $path => $run) {
                $verified = ThreeWayAuditVerifier::verify($run);
                self::assertSame($run['verification']['passed'], $verified['passed'], $attempt['id'].' '.$path);
                $knownTokens += $verified['knownProviderTokens']['total'];
                $missingUsage += $verified['unreportedRequests'];
            }
        }
        self::assertSame(1194226, $knownTokens);
        self::assertSame(3, $missingUsage);
        self::assertCount(3, $report['diagnostics']);
        $latest = $report['attempts'][12];
        self::assertSame(ThreeWayAuditPrompt::DECISION_REVIEW, $latest['protocol']);
        foreach (['direct' => [46438, 12, 10, 'replace'], 'batch' => [29798, 6, 17, 'replace'], 'code' => [12806, 5, 10, 'keep']] as $path => [$tokens, $requests, $calls, $decision]) {
            $run = $latest['runs'][$path];
            self::assertSame($tokens, $run['result']['providerTokens']['total']);
            self::assertSame($requests, $run['result']['modelRequests']);
            self::assertCount($calls, $run['result']['brokerCalls']);
            self::assertSame($decision, $run['review']['decision']);
        }
    }

    public static function protocols(): iterable
    {
        yield 'three tools' => [ThreeWayAuditPrompt::CURRENT];
        yield 'two tools' => [ThreeWayAuditPrompt::CATALOG];
        yield 'text-only review' => [ThreeWayAuditPrompt::TEXT_REVIEW];
        yield 'explicit empty toolbox' => [ThreeWayAuditPrompt::EXPLICIT_REVIEW];
        yield 'literal unicode review' => [ThreeWayAuditPrompt::UNICODE_REVIEW];
        yield 'literal unicode tool data' => [ThreeWayAuditPrompt::UTF8_RESULTS];
        yield 'plain text review' => [ThreeWayAuditPrompt::PLAIN_REVIEW];
        yield 'evidence preserving review' => [ThreeWayAuditPrompt::PRESERVING_REVIEW];
        yield 'explicit decision review' => [ThreeWayAuditPrompt::DECISION_REVIEW];
    }

    public function testReviewCorrectionPreservesEveryDraftPromptAndBaseGenerationSetting(): void
    {
        foreach (self::protocols() as [$protocol]) {
            if (!ThreeWayAuditPrompt::dedicatedReview($protocol)) { continue; }
            foreach (['direct', 'batch', 'code'] as $path) {
                self::assertSame(ThreeWayAuditPrompt::build($path, ThreeWayAuditPrompt::CATALOG), ThreeWayAuditPrompt::build($path, $protocol));
            }
            $options = ThreeWayAuditPrompt::reviewOptions($protocol);
            unset($options['tool_choice'], $options['response_format'], $options['tools']);
            self::assertSame(ThreeWayAuditPrompt::reviewOptions(ThreeWayAuditPrompt::CATALOG), $options);
        }
    }

    public function testReviewKeepNeverRepairsAnIncorrectDraft(): void
    {
        $draft = '{"audited":0,"findings":[]}';
        self::assertSame(['decision' => 'keep', 'answer' => $draft], \App\Demo\ThreeWayAuditReview::apply('{"decision":"keep"}', $draft));
        self::assertSame(['decision' => 'replace', 'answer' => '{"title":"Article · test"}'], \App\Demo\ThreeWayAuditReview::apply('{"decision":"replace","answer":{"title":"Article · test"}}', $draft));
    }

    public function testInvalidReviewRetainsTheActualReplyAndCostWithoutUsingTheDraft(): void
    {
        $draft = json_encode((new AuditDataset())->expected(), JSON_THROW_ON_ERROR);
        $invalid = '{"decision":"guess"}';
        $http = new MockHttpClient([
            $this->response(['role' => 'assistant', 'content' => $draft], 'stop'),
            $this->response(['role' => 'assistant', 'content' => $invalid], 'stop'),
        ]);
        $capture = (new ThreeWayAuditRunner($http, dirname(__DIR__, 2)))->run('batch', static function () {}, ThreeWayAuditPrompt::DECISION_REVIEW);
        self::assertSame('invalid_review_response', $capture['error']);
        self::assertSame($invalid, $capture['review']['response']);
        self::assertSame($invalid, $capture['result']['answer']);
        self::assertSame(15, $capture['review']['providerTokens']['total']);
        self::assertSame(30, $capture['verification']['knownProviderTokens']['total']);
        self::assertFalse($capture['verification']['passed']);
    }

    #[DataProvider('protocols')]
    public function testBatchAgentUsesRealMcpReadsAndReconcilesMockProviderMeasurements(string $protocol): void
    {
        $batch = static fn ($name, $arguments) => ['name' => $name, 'arguments' => $arguments];
        $turns = [
            ['tools_search', ['query' => '']],
            ['tools_describe', ['names' => BackendFactory::FIXTURE_TOOLS]],
            ['tools_execute', ['calls' => array_map(static fn ($offset) => $batch('cms_list_articles', ['offset' => $offset, 'limit' => 12]), [0, 12, 24, 36])]],
            ['tools_execute', ['calls' => array_map(static fn ($ids) => $batch('cms_get_articles', ['ids' => $ids]), array_chunk(range(1, 48), 12))]],
            ['tools_execute', ['calls' => array_map(static fn ($ids) => $batch('cms_get_media', ['ids' => $ids]), array_chunk(range(1, 24), 12))]],
        ];
        $responses = [];
        $catalog = ThreeWayAuditPrompt::usesCatalog($protocol);
        if ($catalog) {
            unset($turns[1]); $turns = array_values($turns);
            foreach ($turns as &$turn) { $turn[0] = ['tools_search' => 'search_tools', 'tools_execute' => 'execute_tools'][$turn[0]]; } unset($turn);
        }
        foreach ($turns as $index => [$name, $arguments]) {
            $responses[] = $this->response(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_'.$index, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)]],
            ]], 'tool_calls');
        }
        $responses[] = $this->response(['role' => 'assistant', 'content' => json_encode((new AuditDataset())->expected(), JSON_THROW_ON_ERROR)], 'stop');
        $responses[] = $this->response(['role' => 'assistant', 'content' => ThreeWayAuditPrompt::DECISION_REVIEW === $protocol ? '{"decision":"keep"}' : json_encode((new AuditDataset())->expected(), JSON_THROW_ON_ERROR)], 'stop');
        $captures = [];
        $wire = [];
        $http = new MockHttpClient(static function ($method, $url, $options) use (&$responses, &$wire) {
            $wire[] = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            return array_shift($responses);
        });
        $runner = new ThreeWayAuditRunner($http, dirname(__DIR__, 2));
        $capture = $runner->run('batch', static function ($report) use (&$captures) { $captures[] = $report; }, $protocol);
        self::assertSame('complete', $capture['status'], json_encode($capture['verification']));
        $requests = $catalog ? 6 : 7;
        self::assertSame($requests, $capture['result']['modelRequests']);
        self::assertSame(['prompt' => 10 * $requests, 'completion' => 5 * $requests, 'total' => 15 * $requests], $capture['result']['providerTokens']);
        self::assertCount(10, $capture['result']['upstream']);
        self::assertCount(3, $capture['result']['executions']);
        self::assertGreaterThan(2, count($captures), 'Capture must be checkpointed while inference progresses.');
        if (ThreeWayAuditPrompt::dedicatedReview($protocol)) {
            $reviewWire = end($wire);
            self::assertSame('none', $reviewWire['tool_choice']);
            if (in_array($protocol, [ThreeWayAuditPrompt::PLAIN_REVIEW, ThreeWayAuditPrompt::PRESERVING_REVIEW, ThreeWayAuditPrompt::DECISION_REVIEW], true)) { self::assertArrayNotHasKey('response_format', $reviewWire); }
            else { self::assertSame(['type' => 'json_object'], $reviewWire['response_format']); }
            self::assertEmpty($reviewWire['tools'] ?? []);
            if (ThreeWayAuditPrompt::explicitEmptyTools($protocol)) { self::assertSame([], $reviewWire['tools']); }
            self::assertSame('user', $reviewWire['messages'][array_key_last($reviewWire['messages'])]['role']);
            self::assertSame(ThreeWayAuditPrompt::reviewInstruction($protocol), $reviewWire['messages'][array_key_last($reviewWire['messages'])]['content']);
            self::assertSame(ThreeWayAuditPrompt::reviewSystem($protocol, ''), $reviewWire['messages'][0]['content']);
            foreach (['model' => ThreeWayAuditRunner::MODEL, 'temperature' => 0, 'max_tokens' => 8192, 'reasoning_effort' => 'medium'] as $key => $value) { self::assertSame($value, $reviewWire[$key]); }
            $changed = $capture; unset($changed['result']['requestEvidence'][$requests - 1]['tool_choice']);
            self::assertFalse(ThreeWayAuditVerifier::verify($changed)['checks']['textOnlyReview']);
        }
        foreach ([
            static function (&$c) { $c['result']['answer'] = '{"audited":48,"findings":[]}'; },
            static function (&$c) { $c['result']['providerTokens']['total']++; },
            static function (&$c) { $c['result']['executions'][0]['submittedCalls'][0]['arguments']['limit'] = 1; },
            static function (&$c) { $c['result']['upstream'][0]['subject'] = 'admin'; },
            static function (&$c) use ($requests) { $c['result']['requestEvidence'][$requests - 1]['toolMessages'] = []; },
            static function (&$c) use ($requests) { $c['result']['providerResponses'][$requests - 2]['answerSha256'] = str_repeat('0', 64); },
            static function (&$c) { $c['review']['oracleFeedback'] = true; },
            static function (&$c) { $c['review']['providerTokens']['total'] = 0; },
            static function (&$c) use ($requests) { $c['result']['requestEvidence'][$requests - 1]['toolsAvailable'] = true; },
            static function (&$c) use ($requests) { $c['result']['requestEvidence'][$requests - 1]['assistantMessageSha256'] = []; },
        ] as $mutate) {
            $changed = $capture; $mutate($changed);
            self::assertFalse(ThreeWayAuditVerifier::verify($changed)['passed']);
        }
        if (ThreeWayAuditPrompt::DECISION_REVIEW === $protocol) {
            $changed = $capture; $changed['review']['response'] = '{"decision":"replace","answer":{}}';
            self::assertFalse(ThreeWayAuditVerifier::verify($changed)['checks']['finalAnswerBoundToProvider']);
            $changed = $capture; $changed['review']['decision'] = 'replace';
            self::assertFalse(ThreeWayAuditVerifier::verify($changed)['checks']['finalAnswerBoundToProvider']);
        }
        self::assertFalse(ThreeWayAuditVerifier::compare(['runs' => ['batch' => $capture]])['passed']);
        $changed = $capture;
        $changed['result']['executions'][1]['results'][0]['value']['items'][0]['title'] = 'Fabricated response';
        self::assertFalse(ThreeWayAuditVerifier::verify($changed)['checks']['batchEvidence']);
    }

    public function testProviderFailureRetainsTheAttemptWithoutInventingZeroCost(): void
    {
        $http = new MockHttpClient([new MockResponse('{"error":{"message":"test quota"}}', ['http_code' => 429])]);
        $saved = [];
        $capture = (new ThreeWayAuditRunner($http, dirname(__DIR__, 2)))->run('batch', static function ($capture) use (&$saved) { $saved[] = $capture; });
        self::assertSame('failed', $capture['status']);
        self::assertSame('provider_rate_limited', $capture['error']);
        self::assertSame(1, $capture['result']['modelRequests']);
        self::assertNull($capture['result']['providerTokens']);
        self::assertSame(1, $capture['verification']['unreportedRequests']);
        self::assertSame([], $capture['result']['upstream']);
        self::assertGreaterThan(1, count($saved));
    }

    public function testReviewToolCallCannotExecuteAfterAnEmptyDraft(): void
    {
        $http = new MockHttpClient([
            $this->response(['role' => 'assistant', 'content' => ''], 'stop'),
            $this->response(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'forbidden_review_call', 'type' => 'function', 'function' => [
                    'name' => 'execute_tools', 'arguments' => '{"calls":[{"name":"cms_get_articles","arguments":{"ids":[49]}}]}',
                ]],
            ]], 'tool_calls'),
        ]);
        $capture = (new ThreeWayAuditRunner($http, dirname(__DIR__, 2)))->run('batch', static function () {}, ThreeWayAuditPrompt::CATALOG);
        self::assertSame('failed', $capture['status']);
        self::assertSame(\UnexpectedValueException::class, $capture['errorType']);
        self::assertSame('', $capture['draft']['answer']);
        self::assertSame([], $capture['result']['upstream']);
        self::assertSame([], $capture['result']['agentCalls']);
        self::assertSame(2, $capture['result']['modelRequests']);
        self::assertSame(30, $capture['verification']['knownProviderTokens']['total']);
        self::assertFalse($capture['result']['requestEvidence'][1]['toolsAvailable']);
    }

    private function response(array $message, string $finish): MockResponse
    {
        return new MockResponse(json_encode(['id' => 'mock', 'model' => ThreeWayAuditRunner::MODEL, 'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finish]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]], JSON_THROW_ON_ERROR));
    }
}
