<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\CodeMode\ReadArticle;
use CodeMode\BatchResult;
use CodeMode\BatchSession;
use CodeMode\CatalogSearchInterface;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\SandboxException;
use CodeMode\Symfony\Ai\Search\SymfonyCatalogSearch;
use CodeMode\Symfony\Ai\Search\Upstream\KeywordToolSearch;
use CodeMode\Symfony\SessionFactory;
use CodeMode\Tool;
use CodeMode\ToolBroker;
use CodeMode\ToolCatalog;
use CodeMode\ToolDefinition;
use CodeMode\ToolVisibilityInterface;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool as AiTool;

final class SymfonyCatalogSearchTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNativeComponentTakesPrecedenceWhenItsContractIsInstalled(): void
    {
        $native = 'Symfony\\AI\\Agent\\Toolbox\\ToolSearch\\Bm25ToolSearch';
        if (!class_exists($native)) {
            class_alias(\CodeMode\Symfony\Ai\Search\Upstream\ToolSearchInterface::class, 'Symfony\\AI\\Agent\\Toolbox\\ToolSearch\\ToolSearchInterface');
            class_alias(\CodeMode\Symfony\Ai\Search\Upstream\Bm25ToolSearch::class, $native);
        }
        $search = new SymfonyCatalogSearch();
        $this->assertSame($native, $search->component());
        $this->assertSame(['record_read'], $search->search('records', [$this->definition('record_read')]));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnIncompatibleInstalledComponentDoesNotSilentlyFallBack(): void
    {
        $native = 'Symfony\\AI\\Agent\\Toolbox\\ToolSearch\\Bm25ToolSearch';
        if (class_exists($native)) {
            $this->markTestSkipped('A real native implementation is installed; do not replace it.');
        }
        class_alias(self::class, $native);
        $this->expectException(\LogicException::class);
        new SymfonyCatalogSearch();
    }

    public function testExistingSymfonyFactoryUsesBm25WithoutAdditionalConfiguration(): void
    {
        $factory = new SessionFactory([new ReadArticle()]);
        // BM25 normalizes the plural; neither the name nor the description contains "permissions".
        $this->assertSame(['articles_read'], array_column($factory->catalog()->searchContracts('permissions')['tools'], 'name'));
        $this->assertStringEndsWith('Bm25ToolSearch', (new SymfonyCatalogSearch())->component());
    }

    public function testSchemaOnlyMatchesIncludeNestedFieldsAndEnumValues(): void
    {
        $tool = new ToolDefinition('publication_read', 'Read a published record.', json_decode('{"type":"object","properties":{"external_reference":{"type":"string"},"filters":{"type":"object","properties":{"workflow":{"enum":["archived"]}}}}}'));
        $catalog = new ToolCatalog([$tool], new SymfonyCatalogSearch());
        foreach (['external_reference', 'externalReference', 'workflow', 'archived', 'publications'] as $query) {
            $this->assertSame(['publication_read'], array_column($catalog->searchContracts($query)['tools'], 'name'), $query);
        }
        $keyword = new ToolCatalog([$tool], new SymfonyCatalogSearch((new KeywordToolSearch())->search(...)));
        $this->assertSame(0, $keyword->searchContracts('external_reference')['matched']);
        $this->assertSame(['publication_read'], array_column($keyword->searchContracts('publication_read')['tools'], 'name'));
    }

    public function testBrowseExactNamesAndTruncationKeepTheExistingProtocol(): void
    {
        $definitions = [];
        for ($i = 1; $i <= 128; ++$i) {
            $definitions[] = $this->definition('record_'.$i);
        }
        $catalog = new ToolCatalog($definitions, new SymfonyCatalogSearch());
        foreach (['', 'records'] as $query) {
            $result = $catalog->searchContracts($query);
            $this->assertSame(128, $result['matched']);
            $this->assertSame(8, $result['returned']);
            $this->assertTrue($result['truncated']);
            $this->assertStringContainsString('Narrow the query', $result['hint']);
        }
        $this->assertSame(['record_128'], array_column($catalog->searchContracts(' RECORD_128 ')['tools'], 'name'));
        $this->assertSame(0, $catalog->searchContracts('unrelated')['matched']);
        $this->expectException(\InvalidArgumentException::class);
        $catalog->searchContracts(str_repeat('x', 257));
    }

    public function testContractSizeLimitStillRejectsOversizedDisclosure(): void
    {
        $tool = new ToolDefinition('huge', 'Read huge record.', (object) ['type' => 'object', 'description' => str_repeat('x', 32200)]);
        $catalog = new ToolCatalog([$tool], new SymfonyCatalogSearch());
        $this->expectException(SandboxException::class);
        $catalog->searchContracts('record');
    }

    public function testRankerSeesOnlyCurrentUserToolsAndNoSchemaMutationEscapes(): void
    {
        $seen = [];
        $rank = new SymfonyCatalogSearch(static function (string $query, array $tools, int $limit) use (&$seen): array {
            $seen[] = array_map(static fn (AiTool $tool) => $tool->getName(), $tools);

            return $tools;
        });
        $visibility = new class implements ToolVisibilityInterface {
            public function isVisible(ExecutionIdentity $identity, string $tool): bool
            {
                return 'public' === $tool || $identity->allows('private.read');
            }
        };
        $catalog = new ToolCatalog([$this->definition('public'), $this->definition('private')], $rank);
        $editor = $catalog->visibleTo(new ExecutionIdentity('editor', ['private.read']), $visibility);
        $reader = $catalog->visibleTo(new ExecutionIdentity('reader'), $visibility);
        $this->assertSame(2, $editor->searchContracts('records')['matched']);
        $this->assertSame(1, $reader->searchContracts('records')['matched']);
        $this->assertSame([['public', 'private'], ['public']], $seen);
        $mutator = new class implements CatalogSearchInterface {
            public function search(string $query, array $tools): array
            {
                $tools[0]->inputSchema->properties->id->type = 'string';

                return [$tools[0]->name];
            }
        };
        $original = $this->definition('read');
        $catalog = new ToolCatalog([$original], $mutator);
        $this->assertSame('integer', $catalog->searchContracts('records')['tools'][0]['inputSchema']->properties->id->type);
        $this->assertSame('integer', $original->inputSchema->properties->id->type);
    }

    public function testForeignDuplicateOrFabricatedResultsFailClosed(): void
    {
        $foreign = new AiTool(new ExecutionReference(self::class), 'private', 'PRIVATE_SENTINEL');
        $callbacks = [
            static fn ($q, $tools) => [$foreign],
            static fn ($q, $tools) => [$tools[0], $tools[0]],
            static fn ($q, $tools) => [clone $tools[0]],
            static fn () => ['private'],
            static fn () => throw new \RuntimeException('PRIVATE_SENTINEL'),
        ];
        foreach ($callbacks as $callback) {
            $catalog = new ToolCatalog([$this->definition('public')], new SymfonyCatalogSearch($callback));
            try {
                $catalog->searchContracts('records');
                $this->fail('Invalid search result disclosed.');
            } catch (SandboxException $error) {
                $this->assertSame('discovery_failed', $error->reason);
                $this->assertStringNotContainsString('PRIVATE_SENTINEL', $error->getMessage());
            }
        }
    }

    public function testCoreRankerCannotReintroduceAHiddenName(): void
    {
        $rank = new class implements CatalogSearchInterface {
            public function search(string $query, array $tools): array
            {
                return ['private'];
            }
        };
        $catalog = new ToolCatalog([$this->definition('public')], $rank);
        $this->expectException(SandboxException::class);
        $catalog->searchContracts('records');
    }

    public function testBm25DiscoveryDoesNotGrantAccessAndRevocationStillConsumesBudget(): void
    {
        $tool = $this->definition('publication_read');
        $invocations = new class implements \Countable {
            private int $calls = 0;

            public function record(): void
            {
                ++$this->calls;
            }

            public function count(): int
            {
                return $this->calls;
            }
        };
        $visibility = new class implements ToolVisibilityInterface {
            public bool $revoked = false;

            public function isVisible(ExecutionIdentity $identity, string $tool): bool
            {
                return !$this->revoked && $identity->allows('read');
            }
        };
        $catalog = new ToolCatalog([$tool], new SymfonyCatalogSearch());
        $broker = new ToolBroker([new Tool($tool->name, $tool->inputSchema,
            static fn ($identity, $arguments) => $identity->allows('read') && 101 === ($arguments->id ?? null),
            static function () use ($invocations) {
                $invocations->record();

                return ['id' => 101];
            })]);
        $identity = new ExecutionIdentity('reader', ['read']);
        $reader = new BatchSession($catalog, $broker, $identity, visibility: $visibility);
        $other = new BatchSession($catalog, $broker, new ExecutionIdentity('other'), visibility: $visibility);
        $read = static fn ($id) => [['name' => 'publication_read', 'arguments' => (object) ['id' => $id]]];
        $this->assertSame(['publication_read'], array_column($reader->searchContracts('publications')['tools'], 'name'));
        $this->assertSame(0, $other->searchContracts('publications')['matched']);
        $this->assertSame(101, $this->response($reader, $read(101))['results'][0]['value']->id);
        $visibility->revoked = true;
        $this->assertSame(0, $reader->searchContracts('publications')['matched']);
        $this->assertSame('tool_denied', $this->response($reader, $read(101))['error']['reason']);
        $this->assertCount(1, $invocations);
        $this->assertSame(2, $reader->state()['executions']);
        $this->assertSame(2, $reader->state()['toolCalls']);
        $visibility->revoked = false;
        $restored = new BatchSession($catalog, $broker, $identity, state: $reader->state(), visibility: $visibility);
        $this->assertSame('execution_limit', $this->response($restored, $read(101))['error']['reason']);
        $restricted = new BatchSession($catalog, $broker, $identity, visibility: $visibility);
        $restricted->searchContracts('publications');
        $this->assertSame('tool_denied', $this->response($restricted, $read(202))['error']['reason']);
        $this->assertSame('tool_denied', $this->response($restricted, $read('101'))['error']['reason']);
        $this->assertCount(1, $invocations);
        $limited = new BatchSession($catalog, $broker, $identity, new ExecutionLimits(maxToolCalls: 0), visibility: $visibility);
        $limited->searchContracts('publications');
        $this->assertSame('tool_call_limit', $this->response($limited, $read(101))['error']['reason']);
        $this->assertCount(1, $invocations);
    }

    public function testPinnedSourcesRetainTheirOriginalLogicAndLicense(): void
    {
        $path = \dirname(__DIR__, 2).'/packages/symfony-code-mode/src/Ai/Search/Upstream';
        $manifest = file_get_contents($path.'/README.md');
        preg_match_all('/\| `([A-Za-z0-9]+\.php)` \| `([a-f0-9]{64})` \|/', $manifest, $files, \PREG_SET_ORDER);
        $this->assertCount(3, $files);
        foreach ($files as [, $file, $hash]) {
            $source = str_replace('namespace CodeMode\\Symfony\\Ai\\Search\\Upstream;', 'namespace Symfony\\AI\\Agent\\Toolbox\\ToolSearch;', file_get_contents($path.'/'.$file));
            $this->assertSame($hash, hash('sha256', $source));
        }
        $this->assertStringContainsString('Permission is hereby granted', file_get_contents($path.'/LICENSE'));
    }

    private function response(BatchSession $session, array $calls): array
    {
        try {
            return $session->respond($calls);
        } catch (\Throwable $error) {
            return BatchResult::failure($error);
        }
    }

    private function definition(string $name): ToolDefinition
    {
        return new ToolDefinition($name, 'Read a public record.', json_decode('{"type":"object","properties":{"id":{"type":"integer"}},"required":["id"],"additionalProperties":false}'));
    }
}
