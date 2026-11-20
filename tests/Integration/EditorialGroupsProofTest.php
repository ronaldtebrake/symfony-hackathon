<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\EditorialGroupsProof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EditorialGroupsProofTest extends TestCase
{
    #[DataProvider('changes')]
    public function testSameWorkflowAcrossDiscoveryPaths(string $change): void
    {
        $root = \dirname(__DIR__, 2);
        $report = json_decode(file_get_contents($root.'/docs/evidence/editorial-groups.json'), true, flags: \JSON_THROW_ON_ERROR);
        $revoked = &$report['paths']['groups']['revoked'];
        switch ($change) {
            case 'current and retained captures':
                EditorialGroupsProof::verify($report, $root);
                $prior = json_decode(gzdecode(file_get_contents($root.'/docs/evidence/editorial-groups-initial.json.gz')), true, flags: \JSON_THROW_ON_ERROR);
                EditorialGroupsProof::verify($prior, $root);
                $this->assertSame(['catalog', 'catalogBrowse', 'groups'], array_keys($report['paths']));
                $this->assertSame(['catalog', 'groups'], array_keys($prior['paths']));

                return;
            case 'hidden negotiation overhead':
                --$revoked['metrics']['totalRequests'];
                break;
            case 'permission change after media read':
                ++$revoked['operator']['beforeCall'];
                break;
            case 'reader invoked despite denial':
                $revoked['calls'][12]['trace']['operations'][0]['nativeEvents'][] = ['phase' => 'requested', 'name' => 'cms_get_media'];
                break;
            case 'broad control actually performs exact search':
                $report['paths']['catalogBrowse']['control']['calls'][2]['request']['params']['arguments']['query'] = 'cms_get_articles';
                break;
            case 'reply replaced without updating digest':
                $report['responses'][$revoked['calls'][12]['responseSha256']] .= ' ';
                break;
        }
        $this->expectException(\UnexpectedValueException::class);
        EditorialGroupsProof::verify($report, $root);
    }

    public static function changes(): iterable
    {
        foreach (['current and retained captures', 'hidden negotiation overhead', 'permission change after media read', 'reader invoked despite denial', 'broad control actually performs exact search', 'reply replaced without updating digest'] as $case) {
            yield $case => [$case];
        }
    }
}
