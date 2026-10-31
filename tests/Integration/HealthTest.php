<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testApplicationRespondsWithoutApiCredentials(): void
    {
        $client = self::createClient();

        foreach (['/', '/health'] as $path) {
            $client->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('content-type', 'application/json');
            self::assertSame(
                ['status' => 'ok', 'project' => 'symfony-hackathon'],
                json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR),
            );
        }
    }
}
