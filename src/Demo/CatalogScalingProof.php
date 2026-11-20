<?php

declare(strict_types=1);

namespace App\Demo;

use CodeMode\Symfony\Ai\Search\SymfonyCatalogSearch;
use CodeMode\ToolCatalog;
use CodeMode\ToolDefinition;
use Mcp\Capability\Discovery\Discoverer;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;

/** Offline schema scaling, bound to the actual authenticated HTTP proof. */
final class CatalogScalingProof
{
    public static function payloads(string $root): array
    {
        $http = HttpCatalogProof::historical($root);
        if (!$http['verification']['passed']) {
            throw new \RuntimeException('The recorded HTTP proof must pass before measuring its contracts.');
        }
        $discovery = (new Discoverer())->discover($root, ['packages/symfony-code-mode/src/Http'], namePatterns: ['CatalogTools.php']);
        $entryTools = array_values(array_map(static fn ($reference) => $reference->tool, $discovery->getTools()));
        $entryList = new ListToolsResult($entryTools);
        $recorded = $http['capture']['httpChecks']['advertised_tools']['response']['result'];
        if (json_decode(json_encode($entryList, \JSON_THROW_ON_ERROR), true) !== $recorded) {
            throw new \RuntimeException('Current SDK contracts differ from the actual HTTP tools/list capture.');
        }
        $payload = static function (mixed $value): array {
            $text = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

            return ['bytes' => \strlen($text), 'sha256' => hash('sha256', $text), 'text' => $text];
        };
        $search = new SymfonyCatalogSearch();
        $samples = [];
        foreach ([3, 10, 20, 40, 100, 128] as $count) {
            $definitions = [];
            for ($i = 1; $i <= $count; ++$i) {
                $definitions[] = new ToolDefinition(\sprintf('record_read_%03d', $i), 'Read approved public records using the supplied filters.',
                    json_decode('{"type":"object","properties":{"id":{"type":"integer","minimum":1},"locale":{"type":"string"},"offset":{"type":"integer","minimum":0},"limit":{"type":"integer","minimum":1,"maximum":12}},"required":["id","locale","offset","limit"],"additionalProperties":false}', flags: \JSON_THROW_ON_ERROR));
            }
            $catalog = new ToolCatalog($definitions, $search);
            $direct = new ListToolsResult(array_map(static fn ($tool) => Tool::fromArray(['name' => $tool->name, 'description' => $tool->description, 'inputSchema' => get_object_vars($tool->inputSchema)]), $definitions));
            $selected = $catalog->searchContracts('record_read_001');
            $broad = $catalog->searchContracts('record');
            $samples[] = ['availableTools' => $count,
                'direct' => ['advertisedTools' => $count, ...$payload($direct)],
                'catalog' => ['advertisedTools' => \count($entryTools), ...$payload($entryList)],
                'selectedContract' => ['returned' => $selected['returned'], ...$payload($selected)],
                'broadSearch' => ['matched' => $broad['matched'], 'returned' => $broad['returned'], 'truncated' => $broad['truncated']]];
        }

        return ['format' => 'symfony-http-catalog-scaling/v1',
            'scope' => 'Offline tokenization of one serialized MCP tools/list result. The two current HTTP contracts match the authenticated execution capture. Direct catalogs contain synthetic four-field tools. Exact-name searches use the real catalog implementation. No model or tool handler runs; no provider usage, correctness comparison or billing is measured.',
            'excludes' => ['JSON-RPC envelope', 'client model framing', 'system/user prompts', 'search results from the advertised-list count', 'task data', 'conversation history', 'model output'],
            'limits' => ['maxRegisteredTools' => 128, 'searchResults' => 8],
            'searchComponent' => $search->component(),
            'searchComponentSha256' => hash_file('sha256', (new \ReflectionClass($search->component()))->getFileName()),
            'catalogSourceSha256' => hash_file('sha256', $root.'/packages/php-code-mode/src/ToolCatalog.php'),
            'httpCaptureSha256' => $http['captureSha256'],
            'contractSourceSha256' => hash_file('sha256', $root.'/packages/symfony-code-mode/src/Http/CatalogTools.php'),
            'samples' => $samples];
    }

    public static function load(string $root): array
    {
        $report = json_decode(file_get_contents($root.'/docs/evidence/catalog-scaling.json'), true, flags: \JSON_THROW_ON_ERROR);
        $actual = self::payloads($root);
        $saved = $report;
        unset($saved['measurement']);
        foreach ($actual['samples'] as &$sample) {
            foreach (['direct', 'catalog', 'selectedContract'] as $kind) {
                unset($sample[$kind]['text']);
            }
        }
        unset($sample);
        foreach ($saved['samples'] as &$sample) {
            foreach (['direct', 'catalog', 'selectedContract'] as $kind) {
                if (!\is_int($sample[$kind]['tokens']) || $sample[$kind]['tokens'] < 1) {
                    throw new \RuntimeException('Missing offline token measurement.');
                }
                unset($sample[$kind]['tokens']);
            }
        }
        unset($sample);
        if ($actual !== $saved) {
            throw new \RuntimeException('Catalog scaling capture does not match the current contracts and fixture.');
        }

        return $report;
    }
}
