<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\Skills\AccessProof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SkillAccessProofTest extends TestCase
{
    public function testReviewedTwoUserHttpTraceMatchesTheCurrentSkill(): void
    {
        [$capture, $skill] = $this->load();
        AccessProof::verify($capture, $skill);
        $this->assertCount(31, $capture['trace']);
    }

    #[DataProvider('securityRegressions')]
    public function testRejectsTamperedEvidence(string $mutation): void
    {
        [$capture, $skill] = $this->load();
        $trace = &$capture['trace'];
        switch ($mutation) {
            case 'shared resource':
                $trace['granted.viewer.resources/list']['response']['reply']['result']['resources'] = $trace['granted.reader.resources/list']['response']['reply']['result']['resources'];
                break;
            case 'unsafe cache':
                $trace['revoked.reader.skills/list']['response']['reply']['result']['cacheScope'] = 'public';
                break;
            case 'known URI leak':
                $trace['revoked.reader.skills/get']['response']['reply']['error']['data']['digest'] = hash('sha256', $skill);
                break;
            case 'stale execution':
                $trace['same.read.after.revoke']['response']['reply']['result'] = $trace['read.article']['response']['reply']['result'];
                break;
            case 'stale local bytes':
                $skill .= ' ';
                break;
        }
        $this->expectException(\UnexpectedValueException::class);
        AccessProof::verify($capture, $skill);
    }

    public static function securityRegressions(): iterable
    {
        foreach (['shared resource', 'unsafe cache', 'known URI leak', 'stale execution', 'stale local bytes'] as $key) {
            yield $key => [$key];
        }
    }

    private function load(): array
    {
        $root = \dirname(__DIR__, 2);

        return [json_decode(file_get_contents($root.'/docs/evidence/skills-http.json'), true, flags: \JSON_THROW_ON_ERROR),
            file_get_contents($root.'/examples/symfony/skills/read-published-article/SKILL.md')];
    }
}
