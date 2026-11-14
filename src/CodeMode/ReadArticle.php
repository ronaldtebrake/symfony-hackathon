<?php

declare(strict_types=1);

namespace App\CodeMode;

use App\Demo\AuditDataset;
use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\ReadToolInterface;
use CodeMode\ToolDefinition;

final class ReadArticle implements ReadToolInterface
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition('articles_read', 'Read an article by ID. Resource access is checked by the host; an ID does not grant permission. No writes.', json_decode('{"type":"object","properties":{"id":{"type":"integer","minimum":1,"maximum":2147483647}},"required":["id"],"additionalProperties":false}'));
    }

    public function authorize(ExecutionIdentity $identity, \stdClass $arguments): bool
    {
        if (!$identity->allows('articles.read_published') || !\is_int($arguments->id ?? null)) {
            return false;
        }
        $article = (new AuditDataset())->articles()[$arguments->id] ?? null;

        return null !== $article && 'published' === $article['status'];
    }

    public function invoke(\stdClass $arguments, ExecutionIdentity $identity): mixed
    {
        if (!$this->authorize($identity, $arguments)) {
            throw new \RuntimeException('Read denied.');
        }
        $article = (new AuditDataset())->articles()[$arguments->id];

        return array_intersect_key($article, array_flip(['id', 'title', 'meta_description']));
    }
}
