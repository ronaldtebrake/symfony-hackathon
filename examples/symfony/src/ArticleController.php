<?php

declare(strict_types=1);

namespace Adoption;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class ArticleController
{
    public function __construct(private ArticleCatalog $articles) {}

    #[IsGranted(ArticleVoter::READ, subject: 'id')]
    public function __invoke(int $id): JsonResponse
    {
        return new JsonResponse($this->articles->readPublished($id));
    }
}
