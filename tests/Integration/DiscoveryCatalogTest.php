<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\{AdoptionProofVerifier, DiscoveryCatalog};
use CodeMode\{CompactToolGuide, DockerEngine, ExecutionIdentity, ExecutionLimits};
use CodeMode\Symfony\Ai\ApprovedToolbox;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Toolbox;

final class DiscoveryCatalogTest extends TestCase
{
    public function testAllSixteenNativeContractsExecuteAndPreservePublishedOnlyPolicy(): void
    {
        $native = new DiscoveryCatalog();
        $approved = new ApprovedToolbox(new Toolbox([$native]), DiscoveryCatalog::NAMES, $native);
        $reader = new ExecutionIdentity('reader', ['articles.read_published']);
        foreach ($approved->tools() as $tool) {
            $definition = $tool->definition();
            $arguments = match ($definition->name) {
                'publishing_archive' => (object) ['year' => 2026, 'month' => 10],
                'publishing_locale_summary' => new \stdClass(),
                default => (object) (str_starts_with($definition->name, 'publishing_') && 'publishing_read' !== $definition->name ? ['page' => 1] : ['id' => 1]),
            };
            self::assertTrue($tool->authorize($reader, $arguments), $definition->name);
            self::assertNotNull($tool->invoke($arguments, $reader)); // Includes original output-schema validation.
            self::assertFalse($tool->authorize(new ExecutionIdentity('anonymous', []), $arguments));
            if ('publishing_locale_summary' !== $definition->name) {
                self::assertFalse($tool->authorize($reader, (object) ['id' => 49]), $definition->name);
            }
        }
        self::assertFalse($native->isGranted($reader, 'unapproved_tool', new \stdClass()));
    }

    public function testRefinementFindsPreviouslyOmittedCapabilityWithoutChangingTheCatalog(): void
    {
        $catalog = DiscoveryCatalog::sessions('', new DockerEngine(), new ExecutionLimits())->catalog();
        $broad = $catalog->searchResult('article read');
        self::assertTrue($broad['truncated']);
        self::assertGreaterThan(8, $broad['matched']);
        self::assertNotContains('publishing_read', array_column($broad['tools'], 'name'));
        $body = $catalog->searchResult('complete body');
        self::assertSame(['publishing_read'], array_column($body['tools'], 'name'));
        self::assertFalse($body['truncated']);
        $audit = $catalog->searchResult('meta_description');
        self::assertContains('publishing_list', array_column($audit['tools'], 'name'));
        self::assertFalse($audit['truncated']);
    }

    public function testNestedAndMapContractsFallBackAndAuditDataRemainsTheSame(): void
    {
        $catalog = DiscoveryCatalog::sessions('', new DockerEngine(), new ExecutionLimits())->catalog();
        self::assertCount(16, $catalog->all());
        self::assertCount(8, $catalog->search('published article'));
        $summary = DiscoveryCatalog::summary($catalog->all());
        self::assertCount(11, $summary['compactReady']);
        self::assertSame(['article_links', 'article_localization', 'publishing_archive', 'publishing_list', 'publishing_locale_summary'], $summary['requiresDescribe']);
        $list = $catalog->describe(['publishing_list'])[0];
        self::assertTrue((new CompactToolGuide())->describe($list)['requiresDescribe']);
        $audited = 0; $missing = [];
        for ($page = 1; $page <= 4; ++$page) {
            foreach ((new DiscoveryCatalog())->list($page)['items'] as $article) {
                ++$audited;
                if ('' === trim($article['meta_description'])) { $missing[] = $article['id']; }
            }
        }
        self::assertSame((new AdoptionProofVerifier())->expected(), ['audited' => $audited, 'missing' => $missing]);
    }
}
