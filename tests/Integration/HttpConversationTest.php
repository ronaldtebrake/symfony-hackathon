<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\HttpConversation\Proof;
use App\Demo\HttpConversation\Runner;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;

final class HttpConversationTest extends TestCase
{
    public function testRetainedLiveAttemptsKeepTheFailureAndVerifyCurrentSource(): void
    {
        $attempts = Proof::load(\dirname(__DIR__, 2))['attempts'];
        $this->assertCount(2, $attempts);
        [$initial, $complete] = $attempts;
        $this->assertFalse($initial['verification']['passed']);
        $this->assertFalse($initial['recordedVerification']['passed']);
        $this->assertFalse($initial['verification']['checks']['revokedContractHidden']);
        $this->assertTrue($initial['verification']['checks']['sameReadDeniedAfterRevocation']);
        $this->assertTrue($initial['verification']['checks']['nativeHandlerInvokedOnlyForAllowedRead']);
        $this->assertTrue($initial['verification']['checks']['persistentBudgetConsumed']);
        $this->assertTrue($complete['verification']['passed']);
        $this->assertTrue($complete['recordedVerification']['passed']);
        foreach ($attempts as $run) {
            $this->assertTrue($run['currentSourceMatches']);
            $this->assertSame('live', $run['mode']);
        }
        $this->assertSame(5220, $complete['trace']['providerTokens']['total']);
        foreach (['model', 'settings', 'limits', 'sourceSha256'] as $key) {
            $this->assertSame($initial[$key], $complete[$key]);
        }
        $this->assertSame(array_column($initial['turns'], 'question'), array_column($complete['turns'], 'question'));
        foreach (['usage', 'received', 'identity', 'settings'] as $case) {
            $mutated = $complete;
            match ($case) {
                'usage' => $mutated['trace']['providerResponses'][0]['tokens']['prompt']++,
                'received' => $mutated['trace']['requestEvidence'][5]['toolMessages'][2]['sha256'] = str_repeat('0', 64),
                'identity' => $mutated['permissionChange']['sameUser'] = false,
                'settings' => $mutated['settings']['temperature'] = 1,
            };
            $this->assertFalse(Proof::verify($mutated)['passed'], $case);
        }
    }

    public function testThePublicLoaderRejectsScriptedModelEvidence(): void
    {
        $directory = sys_get_temp_dir().'/symfony-evidence-'.bin2hex(random_bytes(8));
        mkdir($directory.'/docs/evidence', 0700, true);
        file_put_contents($directory.'/docs/evidence/http-conversation.json', '{"attempts":[{"mode":"test"}]}');
        try {
            $this->expectException(\UnexpectedValueException::class);
            Proof::load($directory);
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($directory);
        }
    }

    // Loading development profiler collectors must not enlarge the inherited heap of later fork-based tests.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testScriptedAgentCrossesRealHttpBoundaryWithoutUsingProviderCredits(): void
    {
        $read = ['calls' => [['name' => 'publication_read', 'arguments' => ['id' => 101]]]];
        $responses = [
            new ToolCallResult([new ToolCall('search', 'search_tools', ['query' => 'publications'])]),
            new ToolCallResult([new ToolCall('read', 'execute_tools', $read)]),
            '{"status":"read","articleId":101,"title":"A Symfony application owns its tools","contractVisible":true,"explanation":"The article was read."}',
            new ToolCallResult([new ToolCall('denied', 'execute_tools', $read)]),
            new ToolCallResult([new ToolCall('hidden', 'search_tools', ['query' => 'publications'])]),
            '{"status":"denied","articleId":101,"title":null,"contractVisible":false,"explanation":"Permission was revoked; no fresh article was returned."}',
        ];
        $platform = MockPlatformFactory::createPlatform(static function () use (&$responses) {
            return array_shift($responses) ?? throw new \LogicException('Unexpected model request.');
        });
        $run = (new Runner(\dirname(__DIR__, 2)))->run($platform);
        $this->assertTrue($run['verification']['passed'], json_encode([$run['verification'], $run['errorType'] ?? null]));
        $this->assertSame(6, $run['trace']['modelRequests']);
        $this->assertTrue($run['cleanup']['applicationRemoved']);
        $this->assertSame('test', $run['mode']);
        $this->assertStringNotContainsString('Bearer ', json_encode($run));
        $this->assertStringNotContainsString('X-Debug-Token', json_encode($run));
        $this->assertStringNotContainsString('Authorization', json_encode($run));
        $this->assertCount(2, $run['turns']);
        foreach (['answer', 'http', 'native', 'budget', 'history', 'usage'] as $case) {
            $mutated = $run;
            match ($case) {
                'answer' => $mutated['turns'][1]['answer'] = $run['turns'][0]['answer'],
                'http' => $mutated['trace']['agentCalls'][2]['arguments']['calls'][0]['arguments']['id'] = 202,
                'native' => $mutated['http'][4]['profiler']['operations'][0]['nativeEvents'][] = ['name' => 'publication_read', 'phase' => 'succeeded'],
                'budget' => $mutated['permissionChange']['budgetReset'] = true,
                'history' => $mutated['trace']['requestEvidence'][3]['userMessageSha256'] = [],
                'usage' => $mutated['mode'] = 'live',
            };
            $this->assertFalse(Proof::verify($mutated)['passed'], $case);
        }
    }
}
