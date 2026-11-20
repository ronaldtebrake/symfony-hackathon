<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class FieldSelectionProofTest extends TestCase
{
    public function testActualMcpBodiesReproduceThePublishedComparison(): void
    {
        $root = \dirname(__DIR__, 2);
        $process = new Process([\PHP_BINARY, $root.'/scripts/field-selection-proof.php'], $root, timeout: 60);
        $process->mustRun();
        $proof = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        $capture = json_decode(file_get_contents($root.'/docs/evidence/field-selection.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame($capture, $proof, 'The reviewed evidence must reproduce over actual SDK HTTP exchanges.');
        $paths = $proof['paths'];
        $this->assertSame($paths['full']['discoveryBodyBytes'], $paths['selected']['discoveryBodyBytes']);
        $this->assertGreaterThan($paths['legacy']['discoveryBodyBytes'], $paths['full']['discoveryBodyBytes']);
        $this->assertSame($paths['legacy']['executionBodyBytes'], $paths['full']['executionBodyBytes']);
        $this->assertLessThan($paths['legacy']['totalBodyBytes'], $paths['selected']['totalBodyBytes']);
    }
}
