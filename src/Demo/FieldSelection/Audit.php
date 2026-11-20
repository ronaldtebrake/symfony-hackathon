<?php

declare(strict_types=1);

namespace App\Demo\FieldSelection;

use App\Demo\AuditMatrix\Tools;
use Mcp\Client;

/** One operator-authored client algorithm shared by all three real HTTP runs. */
final readonly class Audit
{
    private const FIELDS = [
        'cms_list_articles' => ['id'],
        'cms_get_articles' => ['id', 'title', 'meta_description', 'image_id'],
        'cms_get_media' => ['id', 'alt'],
    ];

    public function __construct(private Client $client, private bool $select)
    {
    }

    public function discover(bool $supportsFields): void
    {
        $names = array_column($this->client->listTools()->tools, 'name');
        $search = $this->client->callTool('search_tools', ['query' => 'article']);
        $contracts = array_column($search->structuredContent['tools'] ?? [], null, 'name');
        if (['search_tools', 'execute_tools'] !== $names || 3 !== \count($contracts) || [] !== array_diff(Tools::NAMES, array_keys($contracts))) {
            throw new \RuntimeException('The three approved reader contracts must be discovered.');
        }
        foreach ($contracts as $contract) {
            if ($supportsFields !== isset($contract['inputSchema']['properties']['fields'])) {
                throw new \RuntimeException('Discovered contract differs from the configured projection capability.');
            }
        }
    }

    public function run(): array
    {
        $first = $this->batch('cms_list_articles', [['offset' => 0, 'limit' => 12]])[0];
        $remaining = [];
        for ($offset = $first['next_offset']; null !== $offset && $offset < $first['total']; $offset += 12) {
            $remaining[] = ['offset' => $offset, 'limit' => 12];
        }
        $pages = [$first, ...($remaining ? $this->batch('cms_list_articles', $remaining) : [])];
        $listed = array_merge(...array_column($pages, 'items'));
        $articles = $this->readIds('cms_get_articles', array_column($listed, 'id'));
        $media = $this->readIds('cms_get_media', array_values(array_unique(array_column($articles, 'image_id'))));
        $mediaById = array_column($media, null, 'id');
        $findings = [];
        foreach ($articles as $article) {
            $issues = [];
            if ('' === trim($article['meta_description'])) {
                $issues[] = 'missing_description';
            }
            if (!isset($mediaById[$article['image_id']])) {
                throw new \RuntimeException('Missing requested image.');
            }
            if ('' === trim($mediaById[$article['image_id']]['alt'])) {
                $issues[] = 'missing_alt';
            }
            if ($issues) {
                $findings[] = ['id' => $article['id'], 'title' => $article['title'], 'issues' => $issues];
            }
        }
        $ids = static function (array $items): array {
            $ids = array_column($items, 'id');
            sort($ids);

            return $ids;
        };
        if (range(1, 48) !== $ids($listed) || range(1, 48) !== $ids($articles) || range(1, 24) !== $ids($media)) {
            throw new \RuntimeException('Expected complete article and image coverage.');
        }

        return ['answer' => ['audited' => \count($articles), 'findings' => $findings], 'coverage' => ['articles' => \count($articles), 'media' => \count($media)]];
    }

    public function expectDenied(int $id): string
    {
        $result = $this->client->callTool('execute_tools', ['calls' => [$this->call('cms_get_articles', ['ids' => [$id]])]]);
        $value = $result->structuredContent;
        if (!$result->isError || ['error' => ['reason' => 'tool_denied', 'tool' => 'cms_get_articles', 'index' => 0], 'completedCalls' => 0] !== $value
            || json_decode($result->content[0]->text ?? '', true) !== $value) {
            throw new \RuntimeException('The denied read returned unexpected data.');
        }

        return $value['error']['reason'];
    }

    private function readIds(string $name, array $ids): array
    {
        $arguments = array_map(static fn ($chunk) => ['ids' => $chunk], array_chunk($ids, 12));

        return array_merge(...array_column($this->batch($name, $arguments), 'items'));
    }

    private function batch(string $name, array $arguments): array
    {
        $calls = array_map(fn ($args) => $this->call($name, $args), $arguments);
        $result = $this->client->callTool('execute_tools', ['calls' => $calls]);
        $value = $result->structuredContent;
        if ($result->isError || json_decode($result->content[0]->text ?? '', true) !== $value || \count($calls) !== \count($value['results'] ?? [])) {
            throw new \RuntimeException('Batch did not return matching text and structured results.');
        }
        $expectedKeys = self::FIELDS[$name];
        sort($expectedKeys);
        foreach ($value['results'] as $entry) {
            if ($entry['name'] !== $name) {
                throw new \RuntimeException('Unexpected reader result.');
            }
            if ($this->select) {
                foreach ($entry['value']['items'] as $item) {
                    $actualKeys = array_keys($item);
                    sort($actualKeys);
                    if ($expectedKeys !== $actualKeys) {
                        throw new \RuntimeException('The response contains an unexpected field.');
                    }
                }
            }
        }

        return array_column($value['results'], 'value');
    }

    private function call(string $name, array $arguments): array
    {
        if ($this->select) {
            $arguments['fields'] = self::FIELDS[$name];
        }

        return ['name' => $name, 'arguments' => $arguments];
    }
}
