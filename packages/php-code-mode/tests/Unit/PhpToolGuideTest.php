<?php

declare(strict_types=1);

namespace CodeMode\Tests\Unit;

use CodeMode\PhpToolGuide;
use CodeMode\ToolDefinition;
use PHPUnit\Framework\TestCase;

final class PhpToolGuideTest extends TestCase
{
    public function testGuidesExplainObjectsListsAndNullWithoutInventingOutputTypes(): void
    {
        $input = json_decode('{"type":"object","properties":{"page":{"type":"integer","minimum":1}},"required":["page"]}');
        $output = json_decode('{"type":"object","properties":{"items":{"type":"array","items":{"type":"object","properties":{"id":{"type":"integer"}},"required":["id"]}},"nextPage":{"type":["integer","null"]}},"required":["items","nextPage"]}');
        $guide = (new PhpToolGuide())->describe(new ToolDefinition('publishing.list', 'Read', $input, $output));
        self::assertSame('$result = $tools->call(\'publishing.list\', [\'page\' => 1]);', $guide['example']);
        self::assertSame('array{"page": int}', $guide['arguments']);
        self::assertSame('stdClass{"items": list<stdClass{"id": int}>, "nextPage": int|null}', $guide['returns']);
        self::assertStringContainsString('no output schema', (new PhpToolGuide())->describe(new ToolDefinition('read', 'Read', $input))['returns']);
    }

    public function testReferencesAndHostileMetadataRemainBoundedDocumentation(): void
    {
        $schema = (object) ['type' => 'object', 'properties' => (object) ["x'); system('id'); //" => (object) ['type' => 'string']], 'required' => ["x'); system('id'); //"]];
        $guide = (new PhpToolGuide())->describe(new ToolDefinition('read', 'Read', $schema, (object) ['$ref' => 'https://invalid.example/schema']));
        self::assertSame('mixed', $guide['returns']);
        self::assertStringContainsString("x\\'); system(\\'id\\'); //", $guide['example']);
        $key = str_repeat('x', 9000);
        $guide = (new PhpToolGuide())->describe(new ToolDefinition('read', 'Read', (object) ['type' => 'object', 'required' => [$key]]));
        self::assertLessThan(8192, strlen(json_encode($guide)));
        self::assertStringContainsString('guide size limit', $guide['arguments']);
    }
}
