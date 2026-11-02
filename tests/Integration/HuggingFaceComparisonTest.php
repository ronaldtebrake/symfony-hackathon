<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\HuggingFaceCompareCommand;
use App\Demo\{DemoFactory, HuggingFaceComparison, HuggingFaceProofRunner, ReportStore};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;

final class HuggingFaceComparisonTest extends TestCase
{
    public function testActualPairHasCorrectAnswersReconciledUsageAndAllHistoricalAttempts(): void
    {
        $pair = self::capture();
        $comparison = self::compare($pair);
        self::assertTrue($comparison['verifiedComparison']);
        self::assertCount(21, $comparison['checks']);
        self::assertSame(31382, $comparison['difference']['tokens']);
        self::assertSame(6, $comparison['difference']['modelRequests']);
        foreach (['code' => [5, 12597, 10], 'direct' => [11, 43979, 9]] as $approach => [$requests, $tokens, $earlier]) {
            $capture = $pair[$approach];
            self::assertSame('complete', $capture['status']);
            self::assertSame($requests, $capture['result']['modelRequests']);
            self::assertSame($tokens, $capture['result']['providerTokens']['total']);
            self::assertCount(10, $capture['result']['report']['findings']);
            self::assertCount(10, $capture['result']['brokerCalls']);
            self::assertCount($earlier, $capture['previousAttempts']);
            self::assertSame(2, $comparison['observedTotals'][$approach]['unreportedTokenAttempts']);
            self::assertNull($comparison['observedTotals'][$approach]['providerTokens']);
            $file = 'code' === $approach ? 'huggingface-proof.json' : 'huggingface-baseline.json';
            $original = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/'.$file), true);
            $first = $original['previousAttempts'][0];
            unset($original['previousAttempts']);
            self::assertSame($first, $capture['previousAttempts'][0]);
            self::assertSame($original, $capture['previousAttempts'][1]);
        }
        self::assertSame(98259, $comparison['observedTotals']['code']['knownProviderTokens']);
        self::assertSame(327422, $comparison['observedTotals']['direct']['knownProviderTokens']);
        self::assertSame([], $pair['direct']['result']['programs']);
    }

    #[DataProvider('mutations')]
    public function testCorrectAnswersAloneDoNotEstablishAMatchedComparison(string $mutation): void
    {
        $pair = self::capture();
        $last = count($pair['direct']['result']['requestEvidence']) - 1;
        match ($mutation) {
            'prompt' => $pair['direct']['systemPrompt'] .= ' Extra guidance.',
            'shared' => $pair['code']['sharedPromptSha256'] = str_repeat('0', 64),
            'settings' => $pair['code']['generationSettings']['reasoning_effort'] = 'high',
            'identity' => $pair['code']['identity']['capabilities'][] = 'admin',
            'data' => $pair['code']['datasetSha256'] = str_repeat('0', 64),
            'ceiling' => $pair['direct']['limits']['maxModelRequests']++,
            'message' => $pair['direct']['result']['requestEvidence'][$last]['toolMessages'][0]['sha256'] = str_repeat('0', 64),
            'finish' => $pair['direct']['result']['providerResponses'][$last]['finishReason'] = 'length',
            'usage' => $pair['direct']['result']['providerResponses'][0]['tokens']['total']++,
            'cleanup' => $pair['code']['result']['executions'][1]['containerRemoved'] = false,
            'answer' => $pair['direct']['result']['answer'] = '{"audited":48,"findings":[]}',
        };
        // Rebind after mutation so individual verification, not only the outer digest, must reject it.
        $pair['direct']['codeCaptureSha256'] = hash('sha256', HuggingFaceComparison::encodeCapture($pair['code']));
        $comparison = self::compare($pair);
        self::assertFalse($comparison['verifiedComparison']);
        self::assertNull($comparison['difference']);
    }

    public static function mutations(): iterable
    {
        foreach (['prompt', 'shared', 'settings', 'identity', 'data', 'ceiling', 'message', 'finish', 'usage', 'cleanup', 'answer'] as $field) { yield $field => [$field]; }
    }

    public function testFreshCheckoutFallbackNeverHidesALocalFailedPair(): void
    {
        $root = sys_get_temp_dir().'/code-mode-pair-'.bin2hex(random_bytes(8));
        mkdir($root.'/docs/evidence', 0700, true);
        copy(dirname(__DIR__, 2).'/docs/evidence/huggingface-pair.json', $root.'/docs/evidence/huggingface-pair.json');
        $store = new ReportStore($root);
        try {
            self::assertTrue(json_decode($store->readHuggingFaceComparison(), true)['verifiedComparison']);
            self::assertSame('complete', json_decode($store->readHuggingFaceProof(), true)['status']);
            $pair = self::capture();
            $pair['direct']['status'] = 'failed';
            $store->saveHuggingFacePair($pair);
            self::assertFalse(json_decode($store->readHuggingFaceComparison(), true)['verifiedComparison']);
            file_put_contents($root.'/var/demo/huggingface-pair.json', str_repeat('x', 1048577));
            $this->expectException(\RuntimeException::class);
            $store->readHuggingFaceComparison();
        } finally {
            unlink($root.'/docs/evidence/huggingface-pair.json');
            unlink($root.'/var/demo/huggingface-pair.json');
            rmdir($root.'/docs/evidence'); rmdir($root.'/docs'); rmdir($root.'/var/demo'); rmdir($root.'/var'); rmdir($root);
        }
    }

    public function testMissingCredentialDoesNotOverwriteOrInvoke(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir().'/code-mode-pair-no-key-'.bin2hex(random_bytes(8));
        $saved = $_SERVER['HUGGINGFACE_API_KEY'] ?? null;
        $_SERVER['HUGGINGFACE_API_KEY'] = '';
        $http = new MockHttpClient(static fn () => throw new \LogicException('No provider request allowed.'));
        try {
            $command = new HuggingFaceCompareCommand(new HuggingFaceProofRunner(new DemoFactory($root), $http, $root), new ReportStore($directory), $root);
            self::assertSame(2, (new CommandTester($command))->execute([]));
            self::assertSame(0, $http->getRequestsCount());
            self::assertDirectoryDoesNotExist($directory);
        } finally {
            if (null === $saved) { unset($_SERVER['HUGGINGFACE_API_KEY']); } else { $_SERVER['HUGGINGFACE_API_KEY'] = $saved; }
        }
    }

    public function testInterruptedPairKeepsBothHistoriesWhenPrerequisitesFail(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir().'/code-mode-interrupted-'.bin2hex(random_bytes(8));
        $store = new ReportStore($directory);
        $previous = self::capture();
        $history = [];
        foreach (['code', 'direct'] as $approach) {
            unset($previous[$approach]['previousAttempts']);
            $history[$approach] = [$previous[$approach]];
        }
        $store->saveHuggingFacePair(['status' => 'running', 'code' => null, 'direct' => null, 'retainedAttempts' => $history]);
        $saved = array_intersect_key($_SERVER, array_flip(['HUGGINGFACE_API_KEY', 'HUGGINGFACE_MODEL', 'CODE_MODE_IMAGE']));
        $_SERVER['HUGGINGFACE_API_KEY'] = 'mock-only';
        $_SERVER['HUGGINGFACE_MODEL'] = $previous['code']['model'];
        $_SERVER['CODE_MODE_IMAGE'] = 'invalid-unpinned-image'; // Rejected before Docker or provider I/O.
        $http = new MockHttpClient(static fn () => throw new \LogicException('No provider request allowed.'));
        try {
            $command = new HuggingFaceCompareCommand(new HuggingFaceProofRunner(new DemoFactory($root), $http, $root), $store, $root);
            self::assertSame(1, (new CommandTester($command))->execute([]));
            self::assertSame(0, $http->getRequestsCount());
            $pair = $store->readHuggingFacePair();
            foreach (['code', 'direct'] as $approach) {
                self::assertSame($history[$approach], $pair[$approach]['previousAttempts']);
                self::assertSame('failed', $pair[$approach]['status']);
            }
        } finally {
            foreach (['HUGGINGFACE_API_KEY', 'HUGGINGFACE_MODEL', 'CODE_MODE_IMAGE'] as $key) {
                if (array_key_exists($key, $saved)) { $_SERVER[$key] = $saved[$key]; } else { unset($_SERVER[$key]); }
            }
            unlink($directory.'/var/demo/huggingface-pair.json');
            rmdir($directory.'/var/demo'); rmdir($directory.'/var'); rmdir($directory);
        }
    }

    private static function compare(array $pair): array
    {
        return (new HuggingFaceComparison())->build(HuggingFaceComparison::encodeCapture($pair['code']), $pair['direct']);
    }

    private static function capture(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evidence/huggingface-pair.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
