<?php

declare(strict_types=1);

namespace App\Demo\AuditMatrix;

use App\Demo\FixtureServer;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\AI\Platform\Tool\ToolEffectHints;

#[AsTool('cms_list_articles', 'List published article IDs and titles for a content metadata audit. Paginate with offset and limit (maximum 12). Returns items, total and next_offset (null at the end). Drafts are excluded by the server.', method: 'listArticles', effectHints: new ToolEffectHints(readOnlyHint: true))]
#[AsTool('cms_get_articles', 'Read metadata for up to 12 published article IDs in one call. Returns items with id, title, meta_description, image_id, language, path and updated. Draft IDs are denied. Empty meta_description needs editorial attention.', method: 'getArticles', effectHints: new ToolEffectHints(readOnlyHint: true))]
#[AsTool('cms_get_media', 'Read up to 12 public media records in one batch. Returns items with id, alt, file_name, width and height. An empty alt needs accessibility review. Join article image_id to media id.', method: 'getMedia', effectHints: new ToolEffectHints(readOnlyHint: true))]
final readonly class Tools
{
    public const NAMES = ['cms_list_articles', 'cms_get_articles', 'cms_get_media'];

    public function __construct(private FixtureServer $fixture = new FixtureServer())
    {
    }

    public function listArticles(#[Schema(minimum: 0, maximum: 1000)] int $offset, #[Schema(minimum: 1, maximum: 12)] int $limit): array
    {
        return $this->fixture->listArticles($offset, $limit)->structuredContent;
    }

    /** @param list<int> $ids */
    public function getArticles(#[Schema(description: 'Article IDs returned by cms_list_articles. Read this metadata before planning dependent media reads.', minItems: 1, maxItems: 12, uniqueItems: true)] array $ids): array
    {
        return $this->fixture->getArticles($ids)->structuredContent;
    }

    /** @param list<int> $ids */
    public function getMedia(#[Schema(description: 'Deduplicated image_id values from returned cms_get_articles metadata. Media IDs are not article IDs. Do not infer IDs from article counts or guessed ranges.', minItems: 1, maxItems: 12, uniqueItems: true)] array $ids): array
    {
        return $this->fixture->getMedia($ids)->structuredContent;
    }
}
