<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DemoPageTest extends WebTestCase
{
    public function testWorkbenchShowsRecordedCallsInOrderAndLinksToOneFilm(): void
    {
        $client = self::createClient();
        $page = $client->request('GET', '/demo');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('x-content-type-options', 'nosniff');
        $this->assertStringContainsString("script-src 'none'", $client->getResponse()->headers->get('Content-Security-Policy'));
        $this->assertCount(0, $page->filterXPath('//form | //script'));
        $this->assertCount(1, $page->filterXPath('//video/source[@src="media/editorial-review-90s.mp4"]'));
        $this->assertSame(['#demo-tour', '#how-it-works', '#journey', '#quickstart'], $page->filterXPath('//nav/a')->extract(['href']));
        $proof = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/judge-demo.json'), true, flags: \JSON_THROW_ON_ERROR);
        $calls = $page->filterXPath('//*[@id="proof"]//details/pre')->each(static fn ($node): array => json_decode($node->text(null, false), true, flags: \JSON_THROW_ON_ERROR));
        $this->assertSame($proof['calls'], $calls);
        $this->assertCount($proof['revokedAfterCall'], $page->filterXPath('//p[@class="permission-change"]/preceding-sibling::details'));
    }

    public function testReadonlyAssetsAreLocalAndCannotBeSelectedByUserInput(): void
    {
        $client = self::createClient();
        $expectedFiles = [
            'evidence/judge-demo.json' => 'application/json',
            'images/symfony-mcp-architecture.svg' => 'image/svg+xml',
            'media/editorial-review-90s.mp4' => 'video/mp4',
            'media/editorial-review-poster.jpg' => 'image/jpeg',
        ];
        foreach ($expectedFiles as $path => $type) {
            $client->request('HEAD', '/'.$path.'?file=../../.env.local');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('content-type', $type);
        }
        foreach (['/demo', '/evidence/judge-demo.json', '/media/editorial-review-90s.mp4'] as $path) {
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
