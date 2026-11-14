<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\NativeCatalogRehearsal;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NativeCatalogRehearsalTest extends WebTestCase
{
    public function testNativeMessagesRemainExactAndVerifyWithoutExecutingTools(): void
    {
        $path = \dirname(__DIR__, 2).'/docs/evidence/native-catalog-rehearsal.json';
        $this->assertSame('c84300386fe0e287188ebf85df3f5381e30643776fa1822477e351423267188b', hash_file('sha256', $path));
        $this->assertTrue(NativeCatalogRehearsal::verify($this->capture())['passed']);
    }

    public function testSavedSuccessFlagsCannotHideAlteredRequestsOrReturnedRestrictedData(): void
    {
        foreach (['argument', 'order', 'missing', 'denied_data', 'text', 'schema', 'error_flag'] as $mutation) {
            $capture = $this->capture();
            switch ($mutation) {
                case 'argument': $capture['calls'][1]['arguments']['calls'][0]['arguments']['id'] = 202;
                    break;
                case 'order': $capture['calls'] = array_reverse($capture['calls']);
                    break;
                case 'missing': array_pop($capture['calls']);
                    break;
                case 'denied_data': $capture['calls'][2]['response']['structuredContent']['results'] = [['title' => 'restricted']];
                    break;
                case 'text': $capture['calls'][1]['response']['content'][0]['text'] = '{"results":[]}';
                    break;
                case 'schema': $capture['calls'][0]['response']['structuredContent']['tools'][0]['inputSchema']['additionalProperties'] = true;
                    break;
                case 'error_flag': $capture['calls'][2]['response']['isError'] = false;
                    break;
            }
            // The capture's original success flags remain true in all these cases.
            $this->assertFalse(NativeCatalogRehearsal::verify($capture)['passed'], $mutation);
        }
    }

    public function testEndpointIsReadOnlyLocalAndExportsOnlyReviewedMessagesAndFixedSourceFiles(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/native-catalog?path=../../.env.local&file=latest.json');
        self::assertResponseIsSuccessful();
        $report = json_decode($client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertTrue($report['verification']['passed']);
        $this->assertSame($this->capture(), $report['capture']);
        $this->assertSame(['handler', 'policy'], array_keys($report['implementation']));
        foreach (['handler' => 'ArticleTool.php', 'policy' => 'ReadPolicy.php'] as $role => $file) {
            $source = $report['implementation'][$role];
            $this->assertSame('examples/symfony/src/'.$file, $source['path']);
            $this->assertSame(file_get_contents(\dirname(__DIR__, 2).'/'.$source['path']), $source['source']);
            $this->assertSame(hash('sha256', $source['source']), $source['sha256']);
        }
        $this->assertStringNotContainsString('PRIVATE_FIXTURE_DO_NOT_RETURN', $client->getResponse()->getContent());
        $client->request('POST', '/demo/native-catalog');
        self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/demo/native-catalog', server: ['REMOTE_ADDR' => '203.0.113.1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/demo/native-catalog', server: ['HTTP_HOST' => 'attacker.example']);
        self::assertResponseStatusCodeSame(403);
    }

    private function capture(): array
    {
        return json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/native-catalog-rehearsal.json'), true, flags: \JSON_THROW_ON_ERROR);
    }
}
