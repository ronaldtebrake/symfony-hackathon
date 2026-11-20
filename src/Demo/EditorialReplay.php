<?php

declare(strict_types=1);

namespace App\Demo;

/** Replay inputs come only from the retained conversation. Expected outputs come only from the fixture. */
final class EditorialReplay
{
    /** @return list<string> the two original php_execute arguments in conversation order */
    public function programs(ReportStore $store): array
    {
        $capture = json_decode($store->readCodexProof() ?? '', true, flags: \JSON_THROW_ON_ERROR);
        if (2 !== \count($capture['turns'] ?? [])) {
            throw new \RuntimeException('Expected the recorded audit and its repair-plan follow-up.');
        }
        $programs = [];
        foreach ($capture['turns'] as $turn) {
            $calls = array_values(array_filter($turn['items'], static fn ($item) => 'mcpToolCall' === $item['type'] && 'php_execute' === $item['tool']));
            if (1 !== \count($calls) || 'completed' !== $calls[0]['status'] || !\is_string($code = $calls[0]['arguments']['code'] ?? null) || '' === $code) {
                throw new \RuntimeException('Expected one completed PHP submission per turn.');
            }
            $programs[] = $code;
        }

        return $programs;
    }

    /** The agent chose different JSON field names from the canonical audit and impact contracts. */
    public function expectedAnswers(): array
    {
        $dataset = new AuditDataset();
        $audit = $dataset->expected();
        $findings = $audit['findings'];
        $labels = ['missing_description' => 'missing_meta_description', 'missing_alt' => 'missing_hero_image_alt_text'];
        foreach ($findings as &$finding) {
            $finding['issues'] = array_map(static fn (string $issue) => $labels[$issue], $finding['issues']);
        }
        unset($finding);

        $impact = $dataset->expectedImpact();
        $articles = $dataset->articles();
        $media = $dataset->media();
        $article = static fn (int $id): array => ['id' => $id, 'title' => $articles[$id]['title']];
        $images = [];
        foreach ($impact['groups']['missing_alt'] as $articleId) {
            $images[$articles[$articleId]['image_id']][] = $articleId;
        }
        $imageFixes = [];
        foreach ($images as $id => $ids) {
            $imageFixes[] = ['image_id' => $id, 'file_name' => $media[$id]['file_name'], 'affected_article_count' => \count($ids),
                'affected_article_ids' => $ids, 'affected_articles' => array_map($article, $ids)];
        }
        usort($imageFixes, static fn ($a, $b) => ($b['affected_article_count'] <=> $a['affected_article_count']) ?: ($a['image_id'] <=> $b['image_id']));
        $descriptions = array_map($article, $impact['groups']['missing_description']);

        return [
            ['total_audited' => $audit['audited'], 'affected_count' => \count($findings), 'affected_articles' => $findings],
            ['total_audited' => $impact['audited'], 'meta_description_fix_count' => \count($descriptions), 'meta_description_fixes' => $descriptions,
                'hero_image_alt_fix_count' => \count($imageFixes), 'hero_image_alt_affected_article_count' => \count($impact['groups']['missing_alt']),
                'shared_hero_image_fix_count' => \count($impact['shared_images']), 'hero_image_alt_fixes_ranked_by_impact' => $imageFixes,
                'articles_without_hero_image' => []],
        ];
    }
}
