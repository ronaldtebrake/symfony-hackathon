<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\CatalogScalingProof;
use CodeMode\ToolCatalog;
use CodeMode\ToolDefinition;
use PHPUnit\Framework\TestCase;

final class CatalogScalingProofTest extends TestCase
{
    public function testCurrentHttpContractsAndRetrievedSchemasStayBoundToEvidence(): void
    {
        $root = \dirname(__DIR__, 2);
        $report = CatalogScalingProof::load($root);
        $payloads = CatalogScalingProof::payloads($root);
        $this->assertSame([3, 10, 20, 40, 100, 128], array_column($report['samples'], 'availableTools'));
        $this->assertSame([263, 865, 1725, 3445, 8605, 11013], array_column(array_column($report['samples'], 'direct'), 'tokens'));
        $this->assertSame([239], array_values(array_unique(array_column(array_column($report['samples'], 'catalog'), 'tokens'))));
        $this->assertSame([107], array_values(array_unique(array_column(array_column($report['samples'], 'selectedContract'), 'tokens'))));
        $this->assertCount(1, array_unique(array_column(array_column($payloads['samples'], 'catalog'), 'sha256')));
        $this->assertCount(1, array_unique(array_column(array_column($payloads['samples'], 'selectedContract'), 'sha256')));
        foreach ($payloads['samples'] as $sample) {
            $entries = json_decode($sample['catalog']['text'], true, flags: \JSON_THROW_ON_ERROR);
            $this->assertSame(['search_tools', 'execute_tools'], array_column($entries['tools'], 'name'));
            $this->assertStringNotContainsString('record_read_', $sample['catalog']['text']);
            $selected = json_decode($sample['selectedContract']['text'], true, flags: \JSON_THROW_ON_ERROR);
            $this->assertSame(['record_read_001'], array_column($selected['tools'], 'name'));
            $this->assertSame($sample['availableTools'] > 8, $sample['broadSearch']['truncated']);
            $this->assertSame(min(8, $sample['availableTools']), $sample['broadSearch']['returned']);
        }
    }

    public function testThePublishedMaximumIsEnforcedRatherThanPromisingUnlimitedScale(): void
    {
        $definitions = [];
        for ($i = 0; $i < 129; ++$i) {
            $definitions[] = new ToolDefinition('read_'.$i, 'Read', (object) ['type' => 'object']);
        }
        $this->expectException(\InvalidArgumentException::class);
        new ToolCatalog($definitions);
    }
}
