<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\AuditDataset;
use App\Demo\BackendFactory;
use App\Demo\ReportStore;
use PHPUnit\Framework\TestCase;

final class RecordedProofTest extends TestCase
{
    public function testFreshConversationResultsMatchIndependentFixture(): void
    {
        $capture = json_decode((new ReportStore(dirname(__DIR__, 2)))->readCodexProof(), true, flags: JSON_THROW_ON_ERROR);
        $results = [];
        foreach ($capture['turns'] as $turn) {
            self::assertSame('completed', $turn['status']);
            $executions = array_values(array_filter($turn['items'], static fn ($item) => 'mcpToolCall' === $item['type'] && 'php_execute' === $item['tool']));
            self::assertCount(1, $executions);
            self::assertSame('completed', $executions[0]['status']);
            self::assertNull($executions[0]['error']);
            $result = $executions[0]['result']['structuredContent'];
            self::assertSame(['succeeded'], array_values(array_unique(array_column($result['toolCalls'], 'status'))));
            self::assertSame(BackendFactory::FIXTURE_TOOLS, array_values(array_unique(array_column($result['toolCalls'], 'name'))));
            $results[] = $result['value'];
        }
        self::assertCount(2, $results);
        $dataset = new AuditDataset();
        $findings = array_map(static function (array $article): array {
            $article['issues'] = array_map(static fn ($issue) => match ($issue) {
                'missing_meta_description' => 'missing_description',
                'missing_hero_image_alt_text' => 'missing_alt',
            }, $article['issues']);
            return $article;
        }, $results[0]['affected_articles']);
        self::assertSame($dataset->expected(), ['audited' => $results[0]['total_audited'], 'findings' => $findings]);
        self::assertSame(count($findings), $results[0]['affected_count']);

        $plan = $results[1];
        $groups = ['missing_description' => array_column($plan['meta_description_fixes'], 'id'), 'missing_alt' => []];
        $shared = [];
        $previousImpact = PHP_INT_MAX;
        foreach ($plan['hero_image_alt_fixes_ranked_by_impact'] as $fix) {
            self::assertSame(count($fix['affected_article_ids']), $fix['affected_article_count']);
            self::assertLessThanOrEqual($previousImpact, $fix['affected_article_count']);
            $previousImpact = $fix['affected_article_count'];
            array_push($groups['missing_alt'], ...$fix['affected_article_ids']);
            if ($fix['affected_article_count'] > 1) {
                $shared[] = ['image_id' => $fix['image_id'], 'article_ids' => $fix['affected_article_ids']];
            }
        }
        sort($groups['missing_alt']);
        self::assertSame($dataset->expectedImpact(), ['audited' => $plan['total_audited'], 'groups' => $groups, 'shared_images' => $shared]);
        self::assertSame(count($shared), $plan['shared_hero_image_fix_count']);
        self::assertSame(count($plan['hero_image_alt_fixes_ranked_by_impact']), $plan['hero_image_alt_fix_count']);
        self::assertSame(count($groups['missing_alt']), $plan['hero_image_alt_affected_article_count']);
        self::assertSame(count($groups['missing_description']), $plan['meta_description_fix_count']);
        self::assertSame([], $plan['articles_without_hero_image']);
    }

    public function testMissingOrOversizedCodexCaptureCannotBeReadAsEvidence(): void
    {
        $root = sys_get_temp_dir().'/codex-proof-'.bin2hex(random_bytes(8));
        $store = new ReportStore($root);
        self::assertNull($store->readCodexProof());
        mkdir($root.'/docs/evidence', 0700, true);
        $path = $root.'/docs/evidence/codex-conversation-2026-11-01.json';
        try {
            file_put_contents($path, str_repeat(' ', 1048577));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('size limit');
            $store->readCodexProof();
        } finally {
            unlink($path);
            rmdir($root.'/docs/evidence');
            rmdir($root.'/docs');
            rmdir($root);
        }
    }
}
