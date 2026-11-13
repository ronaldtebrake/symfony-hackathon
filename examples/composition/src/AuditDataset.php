<?php

declare(strict_types=1);

namespace Composition;

/** A deterministic publishing fixture for the Symfony audit. */
final class AuditDataset
{
    public const TASK = 'Audit every published article for an empty meta_description and an empty alt text on its hero image. Use the available batched tools. Return JSON with audited (the number of published articles checked) and findings (sorted by article id). Each finding has id, title, and issues containing missing_description and/or missing_alt. Exclude drafts. Do not change any content.';

    public function articles(): array
    {
        $topics = ['Designing accessible navigation', 'A field guide to urban gardens', 'Making room for slower travel', 'The people behind the workshop', 'A practical guide to local energy', 'What a library can become', 'Repairing the things we keep', 'Notes from the neighbourhood'];
        $articles = [];
        for ($id = 1; $id <= 52; ++$id) {
            $topic = $topics[($id - 1) % count($topics)];
            $articles[$id] = [
                'id' => $id, 'title' => $topic.' · '.(intdiv($id - 1, 8) + 1),
                'status' => $id <= 48 ? 'published' : 'draft',
                'language' => ['en', 'nl', 'de'][($id - 1) % 3],
                'path' => '/journal/'.$id, 'updated' => '2026-10-'.sprintf('%02d', 1 + ($id % 28)),
                'meta_description' => 0 === $id % 11 ? '' : $topic.'. A collection of practical observations, interviews and useful ideas from our editorial team.',
                'image_id' => 1 + (($id - 1) % 24),
            ];
        }
        return $articles;
    }

    public function media(): array
    {
        $media = [];
        for ($id = 1; $id <= 24; ++$id) {
            $media[$id] = ['id' => $id, 'alt' => 0 === $id % 7 ? '' : 'A community workshop, with people working around a shared table.', 'file_name' => 'journal-'.$id.'.webp', 'width' => 1600, 'height' => 1067];
        }
        return $media;
    }

    /** Independent expected result used to score both approaches; never supplied to the model. */
    public function expected(): array
    {
        $findings = [];
        $media = $this->media();
        $audited = 0;
        foreach ($this->articles() as $article) {
            if ('published' !== $article['status']) {
                continue;
            }
            ++$audited;
            $issues = [];
            if ('' === trim($article['meta_description'])) {
                $issues[] = 'missing_description';
            }
            if ('' === trim($media[$article['image_id']]['alt'])) {
                $issues[] = 'missing_alt';
            }
            if ([] !== $issues) {
                $findings[] = ['id' => $article['id'], 'title' => $article['title'], 'issues' => $issues];
            }
        }
        return ['audited' => $audited, 'findings' => $findings];
    }

}
