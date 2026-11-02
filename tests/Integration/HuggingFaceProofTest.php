<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\AuditDataset;
use App\Demo\BackendFactory;
use App\Demo\BudgetedPlatform;
use App\Demo\HuggingFaceProofVerifier;
use App\Demo\ReportStore;
use App\Demo\RunTrace;
use App\Demo\DemoFactory;
use App\Demo\HuggingFaceProofRunner;
use App\Command\HuggingFaceProofCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;

final class HuggingFaceProofTest extends WebTestCase
{
    public function testMissingCredentialCannotCallProviderOrReplaceEvidence(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir().'/code-mode-no-provider-'.bin2hex(random_bytes(8));
        $saved = $_SERVER['HUGGINGFACE_API_KEY'] ?? null;
        $_SERVER['HUGGINGFACE_API_KEY'] = '';
        $http = new MockHttpClient(static fn () => throw new \LogicException('Provider must not be called.'));
        try {
            $tester = new CommandTester(new HuggingFaceProofCommand(new HuggingFaceProofRunner(new DemoFactory($root), $http, $root), new ReportStore($directory)));
            self::assertSame(2, $tester->execute([]));
            self::assertSame(0, $http->getRequestsCount());
            self::assertDirectoryDoesNotExist($directory);
        } finally {
            if (null === $saved) { unset($_SERVER['HUGGINGFACE_API_KEY']); } else { $_SERVER['HUGGINGFACE_API_KEY'] = $saved; }
        }
    }

    public function testProviderBudgetIsEnforcedBeforeAnyFurtherNetworkRequest(): void
    {
        $inner = $this->createMock(PlatformInterface::class);
        $inner->expects(self::once())->method('invoke')->willThrowException(new \RuntimeException('Simulated transport failure'));
        $trace = new RunTrace();
        $platform = new BudgetedPlatform($inner, $trace, 1);
        foreach (['Simulated transport failure', 'Provider request budget exhausted.'] as $expected) {
            try { $platform->invoke('model', 'question'); self::fail('Expected failure.'); }
            catch (\RuntimeException $error) { self::assertSame($expected, $error->getMessage()); }
        }
        self::assertSame(1, $trace->modelRequests, 'Failed requests still consume the budget.');
    }

    public function testVerificationRequiresLinkedSourceResultsMeasurementsAndCleanup(): void
    {
        self::assertTrue((new HuggingFaceProofVerifier())->verify(self::completeResult())['passed']);
    }

    #[DataProvider('missingEvidence')]
    public function testPlausibleAnswerWithoutRequiredEvidenceCannotPass(string $field): void
    {
        $result = self::completeResult();
        match ($field) {
            'source' => $result['programs'][0] = 'return "invented";',
            'cleanup' => $result['executions'][0]['containerRemoved'] = null,
            'ledger' => $result['executions'][0]['toolCalls'] = [],
            'bytes' => $result['toolPayloadBytes']++,
            'answer' => $result['answer'] = '{"audited":48,"findings":[]}',
            'generation' => $result['modelRequests'] = 0,
            'failed' => $result['executions'][0]['status'] = 'failed',
            'missing' => $result = [],
        };
        self::assertFalse((new HuggingFaceProofVerifier())->verify($result)['passed']);
    }

    public static function missingEvidence(): iterable
    {
        foreach (['source', 'cleanup', 'ledger', 'bytes', 'answer', 'generation', 'failed', 'missing'] as $field) {
            yield $field => [$field];
        }
    }

    public function testLiveFeedIsLocalReadOnlyAndUsesAFixedPath(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/huggingface-report?file=../../.env.local');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('cache-control'));
        $store = self::getContainer()->get(ReportStore::class);
        self::assertSame($store->readHuggingFaceProof() ?? '{"status":"empty","result":null}', $client->getResponse()->getContent());
        $client->request('POST', '/demo/huggingface-report');
        self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/huggingface-report', server: ['REMOTE_ADDR' => '203.0.113.1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/demo/huggingface-report', server: ['HTTP_HOST' => 'attacker.example']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testReviewedCaptureRetainsSuccessfulRunAndRejectedFirstAttempt(): void
    {
        $capture = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/huggingface-proof.json'), true, flags: JSON_THROW_ON_ERROR);
        $verifier = new HuggingFaceProofVerifier();
        self::assertSame('complete', $capture['status']);
        self::assertTrue($verifier->verify($capture['result'])['passed']);
        self::assertSame(4, $capture['result']['modelRequests']);
        self::assertSame(3014, $capture['result']['toolPayloadBytes']);
        self::assertSame(6826, $capture['result']['providerTokens']['total']);
        self::assertCount(10, $capture['result']['brokerCalls']);
        self::assertCount(1, $capture['previousAttempts']);
        self::assertFalse($verifier->verify($capture['previousAttempts'][0]['result'])['passed']);
        self::assertSame('failed', $capture['previousAttempts'][0]['status']);
        self::assertTrue($capture['previousAttempts'][0]['verification']['checks']['containersRemoved']);
    }

    private static function completeResult(): array
    {
        $value = (new AuditDataset())->expected();
        $source = 'return $tools->call("fixture", []);'; // Synthetic verifier input; never executed or published as live evidence.
        $calls = array_map(static fn ($name) => ['name' => $name, 'status' => 'succeeded'], BackendFactory::FIXTURE_TOOLS);
        $encoded = json_encode(['result' => $value, 'toolCalls' => 3]);
        return ['report' => $value, 'answer' => json_encode($value), 'modelRequests' => 4, 'agentToolCalls' => 1, 'toolPayloadBytes' => strlen($encoded),
            'brokerCalls' => $calls, 'programs' => [$source],
            'executions' => [['programIndex' => 0, 'runId' => 'code-mode-'.str_repeat('a', 32), 'status' => 'succeeded', 'toolCalls' => $calls, 'containerRemoved' => true]],
            'agentCalls' => [['name' => 'php_execute', 'arguments' => ['code' => $source], 'result' => $encoded, 'bytesToModel' => strlen($encoded)]]];
    }
}
