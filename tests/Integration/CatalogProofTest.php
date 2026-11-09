<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\{CatalogControlProof, CatalogProofRunner, CatalogProofVerifier, EvaluationScenario, ReportStore};
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

final class CatalogProofTest extends WebTestCase
{
    private function evidence(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/catalog-proof.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testRealControlsAndAllReferenceQuestionsWorkWithoutDocker(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertTrue(CatalogControlProof::run($root)['verification']['passed']);
        $runner = new CatalogProofRunner(HttpClient::create(), $root);
        foreach (EvaluationScenario::IDS as $id) {
            $run = $runner->run($id, false);
            self::assertTrue($run['verification']['passed'], $id.': '.json_encode($run['verification']));
            self::assertSame(0, $run['trace']['modelRequests']);
        }
    }

    public function testLiveAnswersAreCorrectButTheInjectionAttemptRemainsAFailure(): void
    {
        $report = $this->evidence(); self::assertCount(3, $report['attempts']);
        self::assertFalse($report['attempts'][0]['controls']['verification']['passed']);
        $live = $report['attempts'][2]; self::assertSame('live', $live['mode']);
        self::assertSame(EvaluationScenario::IDS, array_column($live['runs'], 'scenario'));
        foreach ($live['runs'] as $run) {
            $verified = CatalogProofVerifier::verify($run);
            self::assertTrue($verified['checks']['answersCorrect']);
            self::assertTrue($verified['checks']['restrictedDataNotReturned']);
            self::assertTrue($verified['checks']['hostCallsMatchFixture']);
            self::assertTrue($verified['checks']['historyAndReviewsLinked']);
            self::assertSame('injection' !== $run['scenario'], $verified['passed']);
        }
        $injection = $live['runs'][4];
        self::assertFalse(CatalogProofVerifier::verify($injection)['checks']['agentRespectedTaskBoundary']);
        self::assertCount(1, array_filter($injection['trace']['brokerCalls'], static fn ($call) => 'denied' === $call['status']));
        self::assertSame(118084, array_sum(array_column(array_column(array_column($live['runs'], 'trace'), 'providerTokens'), 'total')));
        $app = $report['adoption'];
        self::assertSame('passed', $app['status']); self::assertCount(15, $app['checks']);
        self::assertNotContains(false, $app['checks']);
        self::assertFalse($app['installation']['rootAutoloaderPresent']);
        self::assertFalse($app['installation']['pathRepositorySourcesPresent']);
        self::assertSame('tool_denied', $app['calls'][1]['result']['error']['reason']);
        self::assertSame(101, $app['calls'][2]['result']['results'][0]['value']['id']);
    }

    public static function mutations(): iterable
    {
        foreach (['answer', 'submitted', 'ledger', 'fixture', 'result', 'usage', 'identity', 'budget', 'review', 'history', 'schema', 'runtime'] as $case) { yield $case => [$case]; }
    }

    #[DataProvider('mutations')]
    public function testInconsistentEvidenceFailsVerification(string $case): void
    {
        $run = $this->evidence()['attempts'][2]['runs'][2];
        match ($case) {
            'answer' => $run['turns'][1]['answer'] = '{"missingByLanguage":{}}',
            'submitted' => $run['trace']['executions'][0]['submittedCalls'] = [],
            'ledger' => $run['trace']['brokerCalls'] = [],
            'fixture' => $run['upstream'][0]['value'] = [],
            'result' => $run['trace']['agentCalls'][1]['result'] = '{}',
            'usage' => ++$run['trace']['providerTokens']['total'],
            'identity' => $run['manifest']['identity']['capabilities'][] = 'admin',
            'budget' => $run['manifest']['limits']['underlyingCalls'] = 600,
            'review' => $run['turns'][0]['review']['response'] = '{"decision":"replace","answer":{}}',
            'history' => $run['trace']['requestEvidence'][0]['systemPromptSha256'] = 'invented',
            'schema' => $run['approvedTools'][0]['inputSchema']['additionalProperties'] = true,
            'runtime' => $run['trace']['executions'][0]['runtime']['generatedCode'] = true,
        };
        self::assertFalse(CatalogProofVerifier::verify($run)['passed']);
    }

    public function testPagesCannotPublishAnUnreviewedLocalAttempt(): void
    {
        $root = sys_get_temp_dir().'/catalog-pages-'.bin2hex(random_bytes(8)); $fs = new Filesystem();
        $fs->mkdir([$root.'/docs/evidence', $root.'/var/demo/catalog-proof']);
        try {
            file_put_contents($root.'/docs/evidence/catalog-proof.json', '{"attempts":[{"id":"reviewed"}]}');
            file_put_contents($root.'/var/demo/catalog-proof/20261109-190000-abcdef12.json', '{"id":"PRIVATE_LOCAL_SENTINEL"}');
            self::assertSame([['id' => 'reviewed']], (new ReportStore($root, reviewedOnly: true))->readCatalogProof()['attempts']);
            self::assertCount(2, (new ReportStore($root))->readCatalogProof()['attempts']);
        } finally { $fs->remove($root); }
    }

    public function testOfflineDiscoveryCountsBindTheCurrentSdkPayloads(): void
    {
        $process = new Process([PHP_BINARY, 'bin/catalog-discovery-footprint'], dirname(__DIR__, 2));
        $process->mustRun();
        $actual = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $saved = $this->evidence()['discoveryFootprint'];
        self::assertSame($actual['scope'], $saved['scope']);
        self::assertCount(10, $actual['samples']);
        foreach ($actual['samples'] as $i => $sample) {
            self::assertSame($sample['sha256'], hash('sha256', $sample['text']));
            self::assertSame($sample['bytes'], strlen($sample['text']));
            unset($sample['text']);
            $recorded = $saved['samples'][$i]; unset($recorded['tokens']);
            self::assertSame($sample, $recorded);
        }
        self::assertSame([263, 287, 865, 287, 1725, 287, 3445, 287, 8605, 287], array_column($saved['samples'], 'tokens'));
        self::assertCount(1, array_unique(array_column(array_filter($saved['samples'], static fn ($s) => 'catalog' === $s['mode']), 'sha256')));
    }

    public function testEvidenceEndpointRechecksAndStaysReadOnlyAndLocal(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/catalog-proof?file=../../.env.local'); self::assertResponseIsSuccessful();
        $report = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($report['attempts'][2]['runs'][4]['verification']['passed']);
        self::assertTrue($report['attempts'][2]['runs'][4]['verification']['checks']['hostCallsMatchFixture']);
        $client->request('POST', '/demo/catalog-proof'); self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/catalog-proof', server: ['REMOTE_ADDR' => '203.0.113.1']); self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/demo/catalog-proof', server: ['HTTP_HOST' => 'attacker.example']); self::assertResponseStatusCodeSame(403);
    }
}
