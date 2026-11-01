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
        self::assertStringContainsString('Let PHP do', $crawler->filterXPath('//h1')->text());
        self::assertResponseHeaderSame('x-content-type-options', 'nosniff');
        self::assertStringContainsString("script-src 'self'", $client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertCount(1, $crawler->filterXPath('//*[@id="question-choice"]'));
        self::assertCount(1, $crawler->filterXPath('//*[@id="custom-question"]'));
        $client->request('GET', '/demo/questions');
        self::assertResponseIsSuccessful();
        $questions = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['audit', 'impact', 'custom'], array_column($questions['choices'], 'id'));
        self::assertSame(2000, $questions['maxBytes']);
        $client->request('POST', '/demo/questions');
        self::assertResponseStatusCodeSame(405);
        $client->request('POST', '/demo');
        self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/questions', server: ['REMOTE_ADDR' => '203.0.113.1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/demo/questions', server: ['HTTP_HOST' => 'attacker.example']);
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
}
