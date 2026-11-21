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
        $this->assertCount(8, $page->filterXPath('//video'));
        $this->assertCount(8, $page->filterXPath('//video[@controls and @playsinline and @preload="none" and not(@autoplay)]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="judge-demo"]/video[@poster="media/symfony-code-mode-judge-poster.jpg"]/source[@src="media/symfony-code-mode-judge-90s.mp4"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="judge-demo"]//a[@href="media/judge-workflow-voiceover.txt"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="ready-demo"]/video[@poster="media/symfony-code-mode-ready-poster.jpg"]/source[@src="media/symfony-code-mode-ready-90s.mp4"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="ready-demo"]//a[@href="media/judge-ready-voiceover.txt"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="ready-demo"]//a[@href="#code-mode-ready"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="plain-demo"]/video[@poster="media/symfony-code-mode-plain-poster.jpg"]/source[@src="media/symfony-code-mode-plain-90s.mp4"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="plain-demo"]//a[@href="media/judge-plain-voiceover.txt"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="support-demo"]/video[@poster="media/symfony-code-mode-support-poster.jpg"]/source[@src="media/symfony-code-mode-support-90s.mp4"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="support-calls"]//a[@href="evidence/refund-group-discovery.json"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="support-calls"]//a[@href="evidence/claude-support-review.json"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="native-explainer"]/video[@poster="media/symfony-code-mode-native-explainer-poster.jpg"]/source[@src="media/symfony-code-mode-native-explainer-90s.mp4"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="native-explainer"]//a[@href="media/judge-native-explainer-voiceover.txt"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="native-explainer"]//a[@href="#demo-tour"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="demo-tour"]//a[@href="#live-demo"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="demo-tour"]//a[@href="#terminal-demo"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="terminal-demo"]//video[@poster="media/symfony-code-mode-terminal-poster.jpg"]/source[@src="media/symfony-code-mode-terminal-90s.mp4"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="terminal-demo"]//a[@href="media/symfony-code-mode-terminal-full.mp4"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="terminal-demo"]//a[@href="media/symfony-code-mode-terminal-film.json"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="live-demo"]//video[@poster="media/symfony-code-mode-live-poster.jpg"]/source[@src="media/symfony-code-mode-live-90s.mp4"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="live-demo"]//a[@href="media/judge-live-voiceover.txt"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="demo-tour"]/video[@controls and @poster="media/symfony-code-mode-poster.jpg"]/source[@src="media/symfony-code-mode-90s.mp4"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="demo-tour"]/figcaption/a[@href="media/symfony-code-mode-film.json"]'));
        $this->assertCount(1, $page->filterXPath('//*[@id="direct-tool"]//a[@href="evidence/direct-tool.json"]'));
        $this->assertSame(['#judge-demo', '#how-it-works', '#journey', '#quickstart'], $page->filterXPath('//nav/a')->extract(['href']));
        $support = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/claude-support-review.json'), true, flags: \JSON_THROW_ON_ERROR);
        $attempt = $support['attempts'][$support['featuredAttempt'] - 1];
        $commands = $page->filterXPath('//*[@id="quickstart"]//pre/code')->each(static fn ($node): string => $node->getNode(0)->textContent);
        $this->assertStringEndsWith('composer demo:serve', $commands[0]);
        $this->assertStringContainsString('examples/support', $page->filterXPath('//*[@id="quickstart"]')->text());
        $this->assertSame(array_column($attempt['programs'], 'source'), $page->filterXPath('//*[@id="support-calls"]//pre/code')->each(static fn ($node): string => $node->getNode(0)->textContent));
        $this->assertSame($attempt['answer'], $page->filterXPath('//*[@id="support-calls"]//details[@class="support-answer"]/pre')->getNode(0)->textContent);
        $plain = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/claude-plain-launch.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame($plain['question'], $page->filterXPath('//*[@id="quickstart"]/blockquote')->text());
        $this->assertSame(array_column($plain['calls'], 'tool'), $page->filterXPath('//*[@id="plain-claude"]//li/code[1]')->each(static fn ($node): string => $node->text()));
        $this->assertSame(array_column($plain['calls'], 'arguments'), $page->filterXPath('//*[@id="plain-claude"]//li/code[2]')->each(static fn ($node): array => json_decode($node->text(), true, flags: \JSON_THROW_ON_ERROR)));
        $this->assertCount(1, $page->filterXPath('//*[@id="plain-claude"]/details[not(@open)]//a[@href="evidence/claude-plain-launch.json"]'));
        $this->assertSame($plain['script'], $page->filterXPath('//*[@id="plain-claude"]//pre/code')->getNode(0)->textContent);
        $this->assertSame(explode("\n\n", $plain['answer'], 2)[0], $page->filterXPath('//*[@id="plain-claude"]//blockquote')->getNode(0)->textContent);
        $this->assertSame($attempt['question'], $plain['question']);
        $session = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/agent-procedure.json'), true, flags: \JSON_THROW_ON_ERROR);
        $calls = $page->filterXPath('//*[@id="proof"]//details/pre')->each(static fn ($node): array => json_decode($node->text(null, false), true, flags: \JSON_THROW_ON_ERROR));
        $this->assertSame(array_merge(...array_column($session['turns'], 'calls')), $calls);
        $this->assertCount($session['operator']['afterCall'], $page->filterXPath('//p[@class="permission-change"]/preceding-sibling::details'));
    }

    public function testStructuredRerunShowsTheOriginalQuestionAndRecordedDataDependency(): void
    {
        $proof = self::createClient()->request('GET', '/demo')->filterXPath('//*[@id="code-mode-ready"]');
        $dir = \dirname(__DIR__, 2).'/docs/evidence';
        $before = json_decode(file_get_contents($dir.'/claude-plain-launch.json'), true, flags: \JSON_THROW_ON_ERROR);
        $after = json_decode(file_get_contents($dir.'/claude-structured-refund.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame($before['question'], $after['question']);
        $verified = array_values(array_filter($after['attempts'], static fn (array $attempt): bool => 'verified' === $attempt['status']));
        $this->assertCount(1, $verified);
        $attempt = $verified[0];
        $this->assertSame(array_column($after['attempts'], 'reason'), $proof->filterXPath('.//details[@class="attempt-history"]//li/span')->each(static fn ($node): string => $node->text()));
        $this->assertSame(array_column($after['attempts'], 'clientDiscoveryPreview'), $proof->filterXPath('.//details[@class="attempt-history"]//li/pre')->each(static fn ($node): string => $node->getNode(0)->textContent));
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

    public function testProgrammaticReviewShowsTheRecordedSourceAndEventsInOrder(): void
    {
        $page = self::createClient()->request('GET', '/demo');
        $proof = $page->filterXPath('//*[@id="programmatic-refund"]');
        $receipt = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/programmatic-refund.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertStringContainsString($receipt['client'], $proof->text());
        $this->assertSame($receipt['script'], $proof->filterXPath('.//details[1]/pre')->getNode(0)->textContent);
        $this->assertSame($receipt['stdout'], $proof->filterXPath('.//details[@class="execution-result"]/pre')->getNode(0)->textContent);
        $this->assertSame($receipt['answer'], $proof->filterXPath('.//details[last()]/pre')->getNode(0)->textContent);
        $events = $proof->filterXPath('.//details[@class="event"]/pre')->each(static fn ($node): array => json_decode($node->text(null, false), true, flags: \JSON_THROW_ON_ERROR));
        $this->assertSame($receipt['events'], $events);
        $this->assertCount(1, $proof->filterXPath('.//a[@href="evidence/programmatic-refund.json"]'));
    }

    public function testReadonlyAssetsAreLocalAndCannotBeSelectedByUserInput(): void
    {
        $client = self::createClient();
        $expectedFiles = [
            'evidence/agent-procedure.json' => 'application/json',
            'evidence/direct-tool.json' => 'application/json',
            'evidence/judge-demo.json' => 'application/json',
            'evidence/programmatic-refund.json' => 'application/json',
            'images/symfony-mcp-architecture.svg' => 'image/svg+xml',
            'media/symfony-code-mode-90s.mp4' => 'video/mp4',
            'media/symfony-code-mode-poster.jpg' => 'image/jpeg',
            'media/symfony-code-mode-film.json' => 'application/json',
            'media/judge-voiceover.txt' => 'text/plain; charset=UTF-8',
            'evidence/claude-refund-film-session.json' => 'application/json',
            'evidence/claude-support-review.json' => 'application/json',
            'evidence/claude-plain-launch.json' => 'application/json',
            'evidence/claude-structured-refund.json' => 'application/json',
            'evidence/catalog-scaling.json' => 'application/json',
            'evidence/refund-group-discovery.json' => 'application/json',
            'evidence/group-selection.json' => 'application/json',
            'media/symfony-code-mode-ready-90s.mp4' => 'video/mp4',
            'media/symfony-code-mode-ready-poster.jpg' => 'image/jpeg',
            'media/symfony-code-mode-ready-film.json' => 'application/json',
            'media/judge-workflow-voiceover.txt' => 'text/plain; charset=UTF-8',
            'media/symfony-code-mode-judge-90s.mp4' => 'video/mp4',
            'media/symfony-code-mode-judge-poster.jpg' => 'image/jpeg',
            'media/judge-ready-voiceover.txt' => 'text/plain; charset=UTF-8',
            'media/symfony-code-mode-plain-90s.mp4' => 'video/mp4',
            'media/symfony-code-mode-plain-poster.jpg' => 'image/jpeg',
            'media/symfony-code-mode-plain-film.json' => 'application/json',
            'media/judge-plain-voiceover.txt' => 'text/plain; charset=UTF-8',
            'media/symfony-code-mode-support-90s.mp4' => 'video/mp4',
            'media/symfony-code-mode-support-poster.jpg' => 'image/jpeg',
            'media/symfony-code-mode-support-film.json' => 'application/json',
            'media/judge-support-voiceover.txt' => 'text/plain; charset=UTF-8',
            'media/symfony-code-mode-native-explainer-90s.mp4' => 'video/mp4',
            'media/symfony-code-mode-native-explainer-poster.jpg' => 'image/jpeg',
            'media/symfony-code-mode-native-explainer-film.json' => 'application/json',
            'media/judge-native-explainer-voiceover.txt' => 'text/plain; charset=UTF-8',
            'media/symfony-code-mode-live-90s.mp4' => 'video/mp4',
            'media/symfony-code-mode-live-poster.jpg' => 'image/jpeg',
            'media/symfony-code-mode-live-film.json' => 'application/json',
            'media/symfony-code-mode-terminal-90s.mp4' => 'video/mp4',
            'media/symfony-code-mode-terminal-full.mp4' => 'video/mp4',
            'media/symfony-code-mode-terminal-operator.mp4' => 'video/mp4',
            'media/symfony-code-mode-terminal-poster.jpg' => 'image/jpeg',
            'media/symfony-code-mode-terminal-film.json' => 'application/json',
            'media/judge-live-voiceover.txt' => 'text/plain; charset=UTF-8',
            'media/editorial-review-90s.mp4' => 'video/mp4',
            'media/editorial-review-film.json' => 'application/json',
            'media/editorial-review-poster.jpg' => 'image/jpeg',
            'media/codex-refund-review.mp4' => 'video/mp4',
            'media/codex-refund-review-poster.jpg' => 'image/jpeg',
            'media/codex-refund-review-film.json' => 'application/json',
        ];
        foreach ($expectedFiles as $path => $type) {
            $client->request('HEAD', '/'.$path.'?file=../../.env.local');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('content-type', $type);
        }
        foreach (['/demo', '/evidence/judge-demo.json', '/evidence/programmatic-refund.json', '/media/symfony-code-mode-90s.mp4', '/media/symfony-code-mode-film.json', '/media/symfony-code-mode-native-explainer-90s.mp4', '/media/judge-native-explainer-voiceover.txt', '/evidence/claude-support-review.json', '/media/symfony-code-mode-support-90s.mp4', '/media/symfony-code-mode-plain-90s.mp4', '/media/judge-plain-voiceover.txt', '/media/symfony-code-mode-ready-90s.mp4', '/media/judge-ready-voiceover.txt'] as $path) {
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
