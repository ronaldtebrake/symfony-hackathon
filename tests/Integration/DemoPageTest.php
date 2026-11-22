<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DemoPageTest extends WebTestCase
{
    public function testWorkbenchFeaturesTheJudgeFilmAndKeepsTheSymfonyAiRunInOrder(): void
    {
        $client = self::createClient();
        $page = $client->request('GET', '/demo');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('x-content-type-options', 'nosniff');
        $this->assertStringContainsString("script-src 'none'", $client->getResponse()->headers->get('Content-Security-Policy'));
        $this->assertCount(0, $page->filterXPath('//form | //script'));
        $this->assertCount(1, $page->filterXPath('//video'));
        $this->assertCount(1, $page->filterXPath('//video[@controls and @playsinline and @preload="none" and not(@autoplay)]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="judge-demo"]/video[@poster="media/symfony-code-mode-judge-poster.jpg"]/source[@src="media/symfony-code-mode-judge-90s.mp4"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="judge-demo"]//a[@href="media/judge-workflow-voiceover.txt"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="support-calls"]//a[@href="evidence/refund-group-discovery.json"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="direct-tool"]//a[@href="evidence/direct-tool.json"]'));
        $this->assertSame(['#judge-demo', '#how-it-works', '#journey', '#quickstart'], $page->filterXPath('//nav/a')->extract(['href']));
        $commands = $page->filterXPath('//*[@id="quickstart"]//pre/code')->each(static fn ($node): string => $node->getNode(0)->textContent);
        $this->assertStringEndsWith('composer demo:serve', $commands[0]);
        $this->assertStringContainsString('examples/support', $page->filterXPath('//*[@id="quickstart"]')->text());
        $structured = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/claude-structured-refund.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame($structured['question'], $page->filterXPath('//*[@id="quickstart"]/blockquote')->text());
        $session = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/agent-procedure.json'), true, flags: \JSON_THROW_ON_ERROR);
        $calls = $page->filterXPath('//*[@id="proof"]//details/pre')->each(static fn ($node): array => json_decode($node->text(null, false), true, flags: \JSON_THROW_ON_ERROR));
        $this->assertSame(array_merge(...array_column($session['turns'], 'calls')), $calls);
        $this->assertCount($session['operator']['afterCall'], $page->filterXPath('//p[@class="permission-change"]/preceding-sibling::details'));
    }

    public function testStructuredRerunShowsTheOriginalQuestionAndRecordedDataDependency(): void
    {
        $proof = self::createClient()->request('GET', '/demo')->filterXPath('//*[@id="code-mode-ready"]');
        $dir = \dirname(__DIR__, 2).'/docs/evidence';
        $after = json_decode(file_get_contents($dir.'/claude-structured-refund.json'), true, flags: \JSON_THROW_ON_ERROR);
        $verified = array_values(array_filter($after['attempts'], static fn (array $attempt): bool => 'verified' === $attempt['status']));
        $this->assertCount(1, $verified);
        $attempt = $verified[0];
        $this->assertCount(1, $proof->filterXPath('.//*[@id="programmatic-refund"]/a[@href="evidence/programmatic-refund.json"]'));
        $this->assertCount(1, $proof->filterXPath('.//a[@href="evidence/claude-structured-refund.json"]'));
        $this->assertSame($after['question'], $proof->filterXPath('.//blockquote')->text());
        $this->assertSame($attempt['script'], $proof->filterXPath('.//details[@class="verified-program"]/pre/code')->getNode(0)->textContent);
        $reads = array_values(array_filter($attempt['calls'], static fn (array $call): bool => 'execute_tools' === $call['tool'] && !$call['isError']));
        $this->assertSame($reads[0]['programId'], $reads[1]['programId']);
        $this->assertSame($attempt['programId'], $reads[0]['programId']);
        $id = $reads[0]['result']['results'][0]['value']['policyId'];
        $this->assertSame($id, $reads[1]['arguments']['calls'][0]['arguments']['policyId']);
        $this->assertSame([$id, $id], $proof->filterXPath('.//dl[@class="field-handoff"]//strong')->each(static fn ($node): string => $node->text()));
        $this->assertSame(array_column($attempt['calls'], 'tool'), $proof->filterXPath('.//details[@class="structured-call"]/summary//code')->each(static fn ($node): string => $node->text()));
        foreach ($proof->filterXPath('.//details[@class="structured-call"]/pre') as $index => $node) {
            $call = $attempt['calls'][$index];
            $this->assertSame(['arguments' => $call['arguments'], 'result' => $call['result']], json_decode($node->textContent, true, flags: \JSON_THROW_ON_ERROR));
        }
    }

    public function testReadonlyAssetsAreLocalAndCannotBeSelectedByUserInput(): void
    {
        $client = self::createClient();
        $expectedFiles = [
            'evidence/agent-procedure.json' => 'application/json',
            'evidence/direct-tool.json' => 'application/json',
            'evidence/judge-demo.json' => 'application/json',
            'evidence/programmatic-refund.json' => 'application/json',
            'images/symfony-mcp-profiler.png' => 'image/png',
            'images/symfony-mcp-architecture.svg' => 'image/svg+xml',
            'evidence/claude-structured-refund.json' => 'application/json',
            'evidence/catalog-scaling.json' => 'application/json',
            'evidence/refund-group-discovery.json' => 'application/json',
            'evidence/group-selection.json' => 'application/json',
            'media/judge-workflow-voiceover.txt' => 'text/plain; charset=UTF-8',
            'media/symfony-code-mode-judge-90s.mp4' => 'video/mp4',
            'media/symfony-code-mode-judge-poster.jpg' => 'image/jpeg',
        ];
        foreach ($expectedFiles as $path => $type) {
            $client->request('HEAD', '/'.$path.'?file=../../.env.local');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('content-type', $type);
        }
        foreach (['/demo', '/evidence/judge-demo.json', '/evidence/programmatic-refund.json', '/media/symfony-code-mode-judge-90s.mp4', '/media/symfony-code-mode-judge-film.json', '/media/judge-workflow-voiceover.txt'] as $path) {
            $client->request('POST', $path);
            self::assertResponseStatusCodeSame(405);
            foreach ([['REMOTE_ADDR' => '203.0.113.1'], ['HTTP_HOST' => 'other.example']] as $server) {
                $client->request('GET', $path, server: $server);
                self::assertResponseStatusCodeSame(403);
            }
        }
        $client->request('GET', '/evidence/private.json');
        self::assertResponseStatusCodeSame(404);
    }
}
