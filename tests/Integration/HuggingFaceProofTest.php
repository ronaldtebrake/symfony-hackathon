<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\HuggingFaceProofCommand;
use App\Demo\AuditDataset;
use App\Demo\BackendFactory;
use App\Demo\BudgetedPlatform;
use App\Demo\DemoFactory;
use App\Demo\HuggingFaceProofRunner;
use App\Demo\HuggingFaceProofVerifier;
use App\Demo\ReportStore;
use App\Demo\RunTrace;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;

final class HuggingFaceProofTest extends WebTestCase
{
    public function testMissingCredentialCannotCallProviderOrReplaceEvidence(): void
    {
        $root = \dirname(__DIR__, 2);
        $directory = sys_get_temp_dir().'/code-mode-no-provider-'.bin2hex(random_bytes(8));
        $saved = $_SERVER['HUGGINGFACE_API_KEY'] ?? null;
        $_SERVER['HUGGINGFACE_API_KEY'] = '';
        $http = new MockHttpClient(static fn () => throw new \LogicException('Provider must not be called.'));
        try {
            $tester = new CommandTester(new HuggingFaceProofCommand(new HuggingFaceProofRunner(new DemoFactory($root), $http, $root), new ReportStore($directory)));
            $this->assertSame(2, $tester->execute([]));
            $this->assertSame(0, $http->getRequestsCount());
            $this->assertDirectoryDoesNotExist($directory);
        } finally {
            if (null === $saved) {
                unset($_SERVER['HUGGINGFACE_API_KEY']);
            } else {
                $_SERVER['HUGGINGFACE_API_KEY'] = $saved;
            }
        }
    }

    public function testProviderBudgetIsEnforcedBeforeAnyFurtherNetworkRequest(): void
    {
        $inner = $this->createMock(PlatformInterface::class);
        $inner->expects($this->once())->method('invoke')->willThrowException(new \RuntimeException('Simulated transport failure'));
        $trace = new RunTrace();
        $platform = new BudgetedPlatform($inner, $trace, 1);
        foreach (['Simulated transport failure', 'Provider request budget exhausted.'] as $expected) {
            try {
                $platform->invoke('model', 'question');
                $this->fail('Expected failure.');
            } catch (\RuntimeException $error) {
                $this->assertSame($expected, $error->getMessage());
            }
        }
        $this->assertSame(1, $trace->modelRequests, 'Failed requests still consume the budget.');
    }

    public function testVerificationRequiresLinkedSourceResultsMeasurementsAndCleanup(): void
    {
        $this->assertTrue((new HuggingFaceProofVerifier())->verify(self::completeResult())['passed']);
    }

    public function testOnlyTheLinkedDiscoveryRejectionCanSkipContainerCleanup(): void
    {
        $result = self::completeResult();
        $error = json_encode(['error' => ['reason' => 'discovery_required']]);
        array_unshift($result['programs'], 'return 1;');
        array_unshift($result['agentCalls'], ['name' => 'php_execute', 'arguments' => ['code' => 'return 1;'], 'result' => $error, 'bytesToModel' => \strlen($error)]);
        $result['executions'][0]['programIndex'] = 1;
        array_unshift($result['executions'], ['programIndex' => 0, 'runId' => null, 'status' => 'failed', 'reason' => 'discovery_required', 'toolCalls' => [], 'containerRemoved' => null]);
        ++$result['agentToolCalls'];
        $result['toolPayloadBytes'] += \strlen($error);
        $verifier = new HuggingFaceProofVerifier();
        $this->assertTrue($verifier->verify($result)['passed']);
        foreach (['runId', 'reason', 'toolCalls'] as $field) {
            $mutated = $result;
            $mutated['executions'][0][$field] = match ($field) {
                'runId' => 'code-mode-'.str_repeat('b', 32),
                'reason' => 'execution_failed',
                'toolCalls' => [['name' => 'cms_list_articles', 'status' => 'incomplete']],
            };
            $this->assertFalse($verifier->verify($mutated)['passed']);
        }
        $result['executions'][1]['containerRemoved'] = false;
        $this->assertFalse($verifier->verify($result)['passed'], 'Allocated runs still require positive cleanup evidence.');
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
        $this->assertFalse((new HuggingFaceProofVerifier())->verify($result)['passed']);
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
        $this->assertStringContainsString('no-store', $client->getResponse()->headers->get('cache-control'));
        $store = self::getContainer()->get(ReportStore::class);
        $this->assertSame($store->readHuggingFaceProof() ?? '{"status":"empty","result":null}', $client->getResponse()->getContent());
        $client->request('POST', '/demo/huggingface-report');
        self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/huggingface-report', server: ['REMOTE_ADDR' => '203.0.113.1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/demo/huggingface-report', server: ['HTTP_HOST' => 'attacker.example']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testReviewedCaptureRetainsSuccessfulRunAndRejectedFirstAttempt(): void
    {
        $capture = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/huggingface-proof.json'), true, flags: \JSON_THROW_ON_ERROR);
        $verifier = new HuggingFaceProofVerifier();
        $this->assertSame('complete', $capture['status']);
        $this->assertTrue($verifier->verify($capture['result'])['passed']);
        $this->assertSame(4, $capture['result']['modelRequests']);
        $this->assertSame(3014, $capture['result']['toolPayloadBytes']);
        $this->assertSame(6826, $capture['result']['providerTokens']['total']);
        $this->assertCount(10, $capture['result']['brokerCalls']);
        $this->assertCount(1, $capture['previousAttempts']);
        $this->assertFalse($verifier->verify($capture['previousAttempts'][0]['result'])['passed']);
        $this->assertSame('failed', $capture['previousAttempts'][0]['status']);
        $this->assertTrue($capture['previousAttempts'][0]['verification']['checks']['containersRemoved']);
    }

    private static function completeResult(): array
    {
        $value = (new AuditDataset())->expected();
        $source = 'return $tools->call("fixture", []);'; // Synthetic verifier input; never executed or published as live evidence.
        $calls = array_map(static fn ($name) => ['name' => $name, 'status' => 'succeeded'], BackendFactory::FIXTURE_TOOLS);
        $encoded = json_encode(['result' => $value, 'toolCalls' => 3]);

        return ['report' => $value, 'answer' => json_encode($value), 'modelRequests' => 4, 'agentToolCalls' => 1, 'toolPayloadBytes' => \strlen($encoded),
            'brokerCalls' => $calls, 'programs' => [$source],
            'executions' => [['programIndex' => 0, 'runId' => 'code-mode-'.str_repeat('a', 32), 'status' => 'succeeded', 'toolCalls' => $calls, 'containerRemoved' => true]],
            'agentCalls' => [['name' => 'php_execute', 'arguments' => ['code' => $source], 'result' => $encoded, 'bytesToModel' => \strlen($encoded)]]];
    }
}
