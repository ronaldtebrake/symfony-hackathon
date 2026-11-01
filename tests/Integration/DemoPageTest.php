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
        self::assertCount(0, $crawler->filterXPath('//textarea'));
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
}
