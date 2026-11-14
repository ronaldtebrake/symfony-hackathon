<?php

declare(strict_types=1);

namespace App\CodeMode;

use App\Demo\AuditDataset;
use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\ReadToolInterface;
use CodeMode\ToolDefinition;

final class ListPublishedArticles implements ReadToolInterface
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition('articles_list_published', 'List published articles with title and meta_description, twelve per page. Start at page 1 and follow nextPage until null. Drafts and other private fields are excluded on the host.', json_decode('{"type":"object","properties":{"page":{"type":"integer","minimum":1,"maximum":100}},"required":["page"],"additionalProperties":false}'));
    }

    public function authorize(ExecutionIdentity $identity, \stdClass $arguments): bool
    {
        return $identity->allows('articles.read_published');
    }

    public function invoke(\stdClass $arguments, ExecutionIdentity $identity): mixed
    {
        if (!$this->authorize($identity, $arguments)) {
            throw new \RuntimeException('Read denied.');
        }
        $published = array_values(array_filter((new AuditDataset())->articles(), static fn (array $article) => 'published' === $article['status']));
        $offset = ($arguments->page - 1) * 12;
        $items = array_map(static fn (array $article) => array_intersect_key($article, array_flip(['id', 'title', 'meta_description'])), \array_slice($published, $offset, 12));

        return ['items' => $items, 'nextPage' => $offset + 12 < \count($published) ? $arguments->page + 1 : null];
    }
}
