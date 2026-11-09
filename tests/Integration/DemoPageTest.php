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
        self::assertStringContainsString('One searchable catalog.', $crawler->filterXPath('//h1')->text());
        $nav = $crawler->filterXPath('//nav[@aria-label="Documentation"]');
        self::assertStringContainsString('HISTORICAL REFERENCE', $nav->text());
        self::assertSame(['#version-one', '#version-two'], $nav->filterXPath('.//a[contains(text(),"Version ")]')->extract(['href']));
        self::assertCount(0, $nav->filterXPath('.//a[@href="#lean-responses" or @href="#adoption-proof"]'));
        self::assertCount(1, $crawler->filterXPath('//*[@id="version-one"]//img[contains(@src,"code-mode-value.svg")]'));
        self::assertResponseHeaderSame('x-content-type-options', 'nosniff');
        self::assertStringContainsString("script-src 'self'", $client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertCount(0, $crawler->filterXPath('//textarea'));
        self::assertCount(0, $crawler->filterXPath('//form'));
        // Only local price arithmetic accepts input; there is no prompt or execution form.
        self::assertSame(['cost-input-rate', 'cost-output-rate'], $crawler->filterXPath('//input')->extract(['id']));
        self::assertCount(2, $crawler->filterXPath('//input[@type="number" and @min="0"]'));
        self::assertCount(1, $crawler->filterXPath('//*[@id="agent-run"]'));
        self::assertCount(1, $crawler->filterXPath('//*[@id="security-proof"]'));
        $client->request('GET', '/demo/questions');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/demo/security-report');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('cache-control'));
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
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('cache-control'));
        self::assertStringContainsString("script-src 'self'", $client->getResponse()->headers->get('Content-Security-Policy'));
        $capture = file_get_contents(dirname(__DIR__, 2).'/docs/evidence/codex-conversation-2026-11-01.json');
        self::assertSame($capture, $client->getResponse()->getContent(), 'Do not rewrite source, responses or host traces.');
        $report = json_decode($capture, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Symfony fixture', $report['backend']);
        self::assertCount(2, $report['turns']);
        $programs = [];
        foreach ($report['turns'] as $turn) {
            $executions = array_values(array_filter($turn['items'], static fn ($item) => 'mcpToolCall' === $item['type'] && 'php_execute' === $item['tool']));
            self::assertCount(1, $executions);
            $programs[] = $executions[0]['arguments']['code'];
            self::assertCount(10, $executions[0]['result']['structuredContent']['toolCalls']);
        }
        self::assertNotSame($programs[0], $programs[1]);
        $client->request('GET', '/demo/codex-report?path=../../.env.local&file=latest.json');
        self::assertSame($capture, $client->getResponse()->getContent(), 'HTTP parameters cannot select another file.');
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
        $capture = file_get_contents(dirname(__DIR__, 2).'/docs/evidence/boundary-recovery-2026-11-02.json');
        self::assertSame($capture, $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('cache-control'));
        $report = json_decode($capture, true, flags: JSON_THROW_ON_ERROR);
        $runs = [];
        foreach ($report['turns'] as $turn) {
            $executions = array_values(array_filter($turn['items'], static fn ($i) => 'mcpToolCall' === $i['type'] && 'php_execute' === $i['tool']));
            self::assertCount(1, $executions);
            $runs[] = $executions[0];
        }
        self::assertSame('failed', $runs[0]['status']);
        self::assertSame('tool_denied', $runs[0]['result']['structuredContent']['error']['reason']);
        self::assertArrayNotHasKey('value', $runs[0]['result']['structuredContent']);
        self::assertSame('denied', $runs[0]['result']['structuredContent']['toolCalls'][0]['status']);
        self::assertSame(48, $runs[1]['result']['structuredContent']['value']['checked']);
        $articles = (new \App\Demo\AuditDataset())->articles();
        $expected = array_values(array_map(static fn ($a) => ['id' => $a['id'], 'title' => $a['title']], array_filter($articles, static fn ($a) => 'published' === $a['status'] && '' === trim($a['meta_description']))));
        self::assertSame($expected, $runs[1]['result']['structuredContent']['value']['matches']);
        self::assertSame(array_fill(0, 4, 'succeeded'), array_column($runs[1]['result']['structuredContent']['toolCalls'], 'status'));
        foreach ($runs as $index => $run) {
            self::assertSame($run['result']['structuredContent']['runId'], $report['verification']['cleanup'][$index]['runId']);
            self::assertTrue($report['verification']['cleanup'][$index]['containerAbsent']);
        }
        $client->request('POST', '/demo/boundary-report');
        self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/boundary-report', server: ['REMOTE_ADDR' => '203.0.113.1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/demo/boundary-report', server: ['HTTP_HOST' => 'attacker.example']);
        self::assertResponseStatusCodeSame(403);
    }
}
