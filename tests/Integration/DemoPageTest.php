<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DemoPageTest extends WebTestCase
{
    public function testWorkbenchAndReportAreReadOnlyAndLocallyScoped(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/demo');
        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filterXPath('//*[@id="overview"]//a[@class="primary-link" and @href="#demo-tour"]'));
        $this->assertCount(1, $crawler->filterXPath('//*[@id="sandbox-tour"]//video/source[@src="/media/server-code-mode-90s.mp4"]'));
        $this->assertCount(1, $crawler->filterXPath('//*[@id="demo-tour"]//video/source[@src="/media/editorial-review-90s.mp4?v=11"]'));
        $this->assertCount(1, $crawler->filterXPath('//*[@id="article-tour"]//video/source[@src="/media/symfony-native-tools-demo-90s.mp4"]'));
        $nav = $crawler->filterXPath('//nav[@aria-label="Documentation"]');
        $this->assertSame(['#overview', '#demo-tour', '#how-it-works', '#journey'], $nav->filterXPath('.//a')->extract(['href']));
        $this->assertSame(['#catalog-adoption', '#group-receipts', '#refund-wire'], $crawler->filterXPath('//*[@id="overview"]//*[@id="contributions"]//article//a')->extract(['href']));
        $this->assertSame(['#group-receipts', '#workflow-directions'], $crawler->filterXPath('//*[@id="overview"]//*[@id="whats-next"]//a')->extract(['href']));
        $this->assertCount(1, $crawler->filterXPath('//*[@id="journey"]//img[contains(@src,"code-mode-value.svg")]'));
        $this->assertCount(1, $crawler->filterXPath('//*[@id="how-it-works"]//*[@id="mcp-architecture"]//img[@src="/images/symfony-mcp-architecture.svg"]'));
        $this->assertStringContainsString('search_tools', $crawler->filterXPath('//*[@id="how-it-works"]//pre')->text());
        $this->assertStringContainsString('execute_tools', $crawler->filterXPath('//*[@id="how-it-works"]//pre')->text());
        $this->assertStringContainsString('composer proof:http --working-dir=examples/symfony', $crawler->filterXPath('//*[@id="quickstart"]')->text());
        $this->assertSame(['symfony-ai'], $crawler->filterXPath('//select[@id="http-conversation-client" and @hidden]/option[1]')->extract(['value']));
        $this->assertCount(0, $nav->filterXPath('.//a[contains(@href, "claude") or @href="#mcp-paths"]'));
        self::assertResponseHeaderSame('x-content-type-options', 'nosniff');
        $this->assertStringContainsString("script-src 'self'", $client->getResponse()->headers->get('Content-Security-Policy'));
        $this->assertCount(0, $crawler->filterXPath('//textarea'));
        $this->assertCount(0, $crawler->filterXPath('//form'));
        // These inputs select a recorded trace or calculate prices locally.
        $this->assertSame(['flow-before', 'flow-after', 'cost-input-rate', 'cost-output-rate'], $crawler->filterXPath('//input')->extract(['id']));
        $this->assertSame(['lookup', 'revoked'], $crawler->filterXPath('//*[@id="how-it-works"]//input[@type="radio"]')->extract(['value']));
        $this->assertCount(2, $crawler->filterXPath('//input[@type="number" and @min="0"]'));
        $this->assertCount(1, $crawler->filterXPath('//*[@id="agent-run"]'));
        $this->assertCount(1, $crawler->filterXPath('//*[@id="security-proof"]'));
        $client->request('GET', '/images/symfony-mcp-architecture.svg');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'image/svg+xml');
        $this->assertSame(file_get_contents(\dirname(__DIR__, 2).'/docs/images/symfony-mcp-architecture.svg'), $client->getResponse()->getContent());
        foreach (['refund-protocol', 'refund-skill', 'editorial-review', 'editorial-review-agent', 'editorial-audit-replay', 'editorial-groups', 'editorial-groups-footprint'] as $report) {
            $client->request('GET', '/demo/'.$report);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('content-type', 'application/json');
            $this->assertSame(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/'.$report.'.json'), $client->getResponse()->getContent());
        }
        foreach (['server-code-mode-90s.mp4' => 'video/mp4', 'server-code-mode-poster.jpg' => 'image/jpeg', 'server-code-mode-film.json' => 'application/json'] as $file => $type) {
            $client->request('HEAD', '/media/'.$file);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('content-type', $type);
        }
        $client->request('GET', '/demo/questions');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/demo/security-report');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $this->assertStringContainsString('no-store', $client->getResponse()->headers->get('cache-control'));
        $client->request('POST', '/demo/security-report');
        self::assertResponseStatusCodeSame(405);
        $client->request('POST', '/demo');
        self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/security-report', server: ['REMOTE_ADDR' => '203.0.113.1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/demo/security-report', server: ['HTTP_HOST' => 'attacker.example']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', 'http://localhost/demo/report', server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $client->request('POST', '/demo/report');
        self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/report', server: ['REMOTE_ADDR' => '203.0.113.1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/demo/report', server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'attacker.example']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCodexCaptureIsExactReadOnlyAndLocallyScoped(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/codex-report');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertResponseHeaderSame('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('no-store', $client->getResponse()->headers->get('cache-control'));
        $this->assertStringContainsString("script-src 'self'", $client->getResponse()->headers->get('Content-Security-Policy'));
        $capture = file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/codex-conversation-2026-11-01.json');
        $this->assertSame($capture, $client->getResponse()->getContent(), 'Do not rewrite source, responses or host traces.');
        $report = json_decode($capture, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('Symfony fixture', $report['backend']);
        $this->assertCount(2, $report['turns']);
        $programs = [];
        foreach ($report['turns'] as $turn) {
            $executions = array_values(array_filter($turn['items'], static fn ($item) => 'mcpToolCall' === $item['type'] && 'php_execute' === $item['tool']));
            $this->assertCount(1, $executions);
            $programs[] = $executions[0]['arguments']['code'];
            $this->assertCount(10, $executions[0]['result']['structuredContent']['toolCalls']);
        }
        $this->assertNotSame($programs[0], $programs[1]);
        $client->request('GET', '/demo/codex-report?path=../../.env.local&file=latest.json');
        $this->assertSame($capture, $client->getResponse()->getContent(), 'HTTP parameters cannot select another file.');
        $client->request('POST', '/demo/codex-report', content: '{"prompt":"run PHP"}');
        self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/codex-report', server: ['REMOTE_ADDR' => '203.0.113.1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/demo/codex-report', server: ['HTTP_HOST' => 'attacker.example']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testBoundaryRecoveryCaptureIsReadOnlyAndCannotSelectFiles(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/boundary-report?file=../../.env.local');
        self::assertResponseIsSuccessful();
        $capture = file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/boundary-recovery-2026-11-02.json');
        $this->assertSame($capture, $client->getResponse()->getContent());
        $this->assertStringContainsString('no-store', $client->getResponse()->headers->get('cache-control'));
        $report = json_decode($capture, true, flags: \JSON_THROW_ON_ERROR);
        $runs = [];
        foreach ($report['turns'] as $turn) {
            $executions = array_values(array_filter($turn['items'], static fn ($i) => 'mcpToolCall' === $i['type'] && 'php_execute' === $i['tool']));
            $this->assertCount(1, $executions);
            $runs[] = $executions[0];
        }
        $this->assertSame('failed', $runs[0]['status']);
        $this->assertSame('tool_denied', $runs[0]['result']['structuredContent']['error']['reason']);
        $this->assertArrayNotHasKey('value', $runs[0]['result']['structuredContent']);
        $this->assertSame('denied', $runs[0]['result']['structuredContent']['toolCalls'][0]['status']);
        $this->assertSame(48, $runs[1]['result']['structuredContent']['value']['checked']);
        $articles = (new \App\Demo\AuditDataset())->articles();
        $expected = array_values(array_map(static fn ($a) => ['id' => $a['id'], 'title' => $a['title']], array_filter($articles, static fn ($a) => 'published' === $a['status'] && '' === trim($a['meta_description']))));
        $this->assertSame($expected, $runs[1]['result']['structuredContent']['value']['matches']);
        $this->assertSame(array_fill(0, 4, 'succeeded'), array_column($runs[1]['result']['structuredContent']['toolCalls'], 'status'));
        foreach ($runs as $index => $run) {
            $this->assertSame($run['result']['structuredContent']['runId'], $report['verification']['cleanup'][$index]['runId']);
            $this->assertTrue($report['verification']['cleanup'][$index]['containerAbsent']);
        }
        $client->request('POST', '/demo/boundary-report');
        self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/boundary-report', server: ['REMOTE_ADDR' => '203.0.113.1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/demo/boundary-report', server: ['HTTP_HOST' => 'attacker.example']);
        self::assertResponseStatusCodeSame(403);
    }
}
