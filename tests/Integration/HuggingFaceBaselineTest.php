<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\AuditDataset;
use App\Demo\HuggingFaceBaselineVerifier;
use App\Demo\HuggingFaceComparison;
use App\Demo\ReportStore;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HuggingFaceBaselineTest extends WebTestCase
{
    public function testActualFailedBaselinesRetainUsageAndCannotClaimEquivalentResults(): void
    {
        $capture = self::capture();
        $verifier = new HuggingFaceBaselineVerifier();
        $totals = [42931, 42942];
        foreach ([$capture['previousAttempts'][0], $capture] as $index => $attempt) {
            self::assertSame('failed', $attempt['status']);
            self::assertFalse($verifier->verify($attempt)['passed']);
            self::assertTrue($verifier->verify($attempt)['checks']['allPublishedArticlesAndMediaRead']);
            self::assertSame(12, $attempt['result']['modelRequests']);
            self::assertSame($totals[$index], $attempt['result']['providerTokens']['total']);
            self::assertCount(11, $attempt['result']['agentCalls']);
            self::assertSame('incomplete', $attempt['result']['brokerCalls'][10]['status']);
            self::assertSame(range(25, 36), $attempt['result']['agentCalls'][10]['arguments']['ids']);
            self::assertSame([], $attempt['result']['programs']);
            self::assertSame([31, 38, 44, 45], array_values(array_diff(array_column((new AuditDataset())->expected()['findings'], 'id'), array_column($attempt['result']['report']['findings'], 'id'))));
        }
        $pair = (new HuggingFaceComparison())->build(self::codeJson(), $capture);
        self::assertFalse($pair['verifiedComparison']);
        self::assertNull($pair['difference']);
        self::assertSame('failed', $pair['status']);
        foreach (['codeCaptureBound', 'sameModel', 'sameQuestion', 'sameEndpoint', 'sameComponents', 'baselineGenerationSettings', 'approvedReadIdentity', 'sameToolSchemas', 'sameFixture', 'codeResultVerified', 'providerUsageReported'] as $check) {
            self::assertTrue($pair['checks'][$check], $check);
        }
        self::assertSame(['attempts' => 2, 'modelRequests' => 24, 'providerTokens' => 85873, 'knownProviderTokens' => 85873, 'unreportedTokenAttempts' => 0], $pair['observedTotals']['direct']);
        self::assertSame(['attempts' => 2, 'modelRequests' => 8, 'providerTokens' => 13337, 'knownProviderTokens' => 13337, 'unreportedTokenAttempts' => 0], $pair['observedTotals']['code']);
    }

    public function testSyntheticCorrectDirectResultCanBeComparedWithoutExecutingPhp(): void
    {
        $pair = (new HuggingFaceComparison())->build(self::codeJson(), self::syntheticCorrectResult());
        self::assertTrue($pair['verifiedComparison']);
        self::assertSame('complete', $pair['status']);
        self::assertSame(7, $pair['difference']['modelRequests']);
    }

    #[DataProvider('invalidEvidence')]
    public function testIncompleteOrMismatchedEvidenceSuppressesSavings(string $mutation): void
    {
        $capture = self::syntheticCorrectResult();
        match ($mutation) {
            'source' => $capture['codeCaptureSha256'] = str_repeat('0', 64),
            'model' => $capture['model'] = 'different-model',
            'question' => $capture['task'] = 'Different task',
            'identity' => $capture['identity']['capabilities'][] = 'admin',
            'schema' => $capture['approvedTools'][0]['description'] = 'Different schema',
            'data' => $capture['datasetSha256'] = str_repeat('0', 64),
            'usage' => $capture['result']['providerTokens'] = null,
            'usageSum' => $capture['result']['providerTokens']['total']++,
            'php' => $capture['result']['programs'][] = 'return 48;',
            'bytes' => $capture['result']['toolPayloadBytes']++,
            'ledger' => $capture['result']['brokerCalls'][0]['name'] = 'cms_delete_article',
            'coverage' => $capture['result']['agentCalls'][4]['result'] = '{"items":[]}',
        };
        $pair = (new HuggingFaceComparison())->build(self::codeJson(), $capture);
        self::assertFalse($pair['verifiedComparison']);
        self::assertNull($pair['difference']);
    }

    public static function invalidEvidence(): iterable
    {
        foreach (['source', 'model', 'question', 'identity', 'schema', 'data', 'usage', 'usageSum', 'php', 'bytes', 'ledger', 'coverage'] as $mutation) { yield $mutation => [$mutation]; }
    }

    public function testComparisonEndpointIsFixedLocalAndReadOnly(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/huggingface-comparison?file=../../.env.local');
        self::assertResponseIsSuccessful();
        self::assertSame(self::getContainer()->get(ReportStore::class)->readHuggingFaceComparison(), $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('cache-control'));
        $client->request('POST', '/demo/huggingface-comparison');
        self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/huggingface-comparison', server: ['REMOTE_ADDR' => '203.0.113.1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/demo/huggingface-comparison', server: ['HTTP_HOST' => 'attacker.example']);
        self::assertResponseStatusCodeSame(403);
    }

    private static function capture(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/huggingface-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function codeJson(): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/docs/evidence/huggingface-proof.json');
    }

    /** Verifier-only input, never executed or published as observed evidence. */
    private static function syntheticCorrectResult(): array
    {
        $capture = self::capture();
        $capture['status'] = 'complete';
        $capture['result']['report'] = (new AuditDataset())->expected();
        $capture['result']['answer'] = json_encode($capture['result']['report']);
        array_pop($capture['result']['agentCalls']);
        array_pop($capture['result']['brokerCalls']);
        $capture['result']['agentToolCalls'] = 10;
        $capture['result']['modelRequests'] = 11;
        $capture['result']['toolPayloadBytes'] = 20154;
        return $capture;
    }
}
