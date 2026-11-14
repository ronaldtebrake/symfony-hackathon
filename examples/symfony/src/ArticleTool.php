<?php

declare(strict_types=1);

namespace Adoption;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;
use Symfony\AI\Platform\Tool\ToolEffectHints;

#[AsTool('publication_read', 'Read the ID and title of a published article by integer ID.',
    method: 'read', effectHints: new ToolEffectHints(readOnlyHint: true),
    metadata: ['code_mode.output_schema' => [
        'type' => 'object', 'required' => ['id', 'title'], 'additionalProperties' => false,
        'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string']],
    ]])]
// This second tool deliberately remains outside the Code Mode allowlist.
#[AsTool('publication_count', 'Count published articles.', method: 'count', effectHints: new ToolEffectHints(readOnlyHint: true))]
final class ArticleTool
{
    public function __construct(private readonly ArticleCatalog $articles) {}

    /** @return array{id: int, title: string} */
    #[IsGrantedTool(ArticleVoter::READ, subject: 'id')]
    public function read(int $id): array { return $this->articles->readPublished($id); }

    public function count(): int { return 1; }
}
