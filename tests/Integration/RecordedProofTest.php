<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\AuditDataset;
use App\Demo\AuditVerifier;
use App\Demo\BackendFactory;
use App\Demo\ReportStore;
use PHPUnit\Framework\TestCase;

final class RecordedProofTest extends TestCase
{
    public function testReferenceBaselineRecordsEquivalentWorkAndMeasuredPayloads(): void
    {
        $root = dirname(__DIR__, 2);
        $capture = json_decode(file_get_contents($root.'/docs/evidence/reference-comparison-2026-10-31.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('reference', $capture['mode']);
        self::assertSame('complete', $capture['status']);
        self::assertSame('fixture', $capture['backend']);
        self::assertSame(['direct', 'code'], array_column($capture['results'], 'approach'));
        self::assertSame(AuditDataset::TASK, $capture['task']);
        foreach ($capture['results'] as $run) {
            self::assertSame('reference', $run['mode']);
            self::assertSame(0, $run['modelRequests']);
            self::assertNull($run['providerTokens']);
            self::assertTrue((new AuditVerifier())->verify($run['report'], 'fixture', $run['brokerCalls'])['passed']);
            self::assertSame((new AuditDataset())->expected(), $run['report']);
            self::assertSame(array_fill(0, 10, 'succeeded'), array_column($run['brokerCalls'], 'status'));
            self::assertSame(['cms_list_articles' => 4, 'cms_get_articles' => 4, 'cms_get_media' => 2], array_count_values(array_column($run['brokerCalls'], 'name')));
            $events = array_values(array_filter($run['events'], static fn ($event) => 'tool_result' === $event['stage']));
            self::assertCount($run['agentToolCalls'], $events);
            self::assertSame($run['toolPayloadBytes'], array_sum(array_map(static fn ($event) => $event['details']['bytesToModel'], $events)));
        }
        [$direct, $code] = $capture['results'];
        self::assertSame($direct['report'], $code['report']);
        self::assertSame([], $direct['programs']);
        self::assertSame([file_get_contents($root.'/resources/demo/audit.php.txt')], $code['programs']);
        self::assertSame(20154, $direct['toolPayloadBytes']);
        self::assertSame(3014, $code['toolPayloadBytes']);
        self::assertSame(10, $direct['agentToolCalls']);
        self::assertSame(3, $code['agentToolCalls']);
    }

    public function testFreshCheckoutUsesBaselineButNeverHidesALocalFailure(): void
    {
        $root = sys_get_temp_dir().'/reference-proof-'.bin2hex(random_bytes(8));
        $store = new ReportStore($root);
        self::assertNull($store->read());
        mkdir($root.'/docs/evidence', 0700, true);
        $baselinePath = $root.'/docs/evidence/reference-comparison-2026-10-31.json';
        $localPath = $root.'/var/demo/latest.json';
        try {
            $baseline = file_get_contents(dirname(__DIR__, 2).'/docs/evidence/reference-comparison-2026-10-31.json');
            file_put_contents($baselinePath, $baseline);
            self::assertSame($baseline, $store->read());
            foreach (['running', 'failed'] as $status) {
                $store->save(['status' => $status, 'results' => []]);
                self::assertSame($status, json_decode($store->read(), true)['status']);
            }
            file_put_contents($localPath, str_repeat(' ', 1048577));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('size limit');
            $store->read();
        } finally {
            if (is_file($localPath)) { unlink($localPath); }
            unlink($baselinePath);
            foreach (['var/demo', 'var', 'docs/evidence', 'docs', ''] as $directory) {
                if (is_dir($root.'/'.$directory)) { rmdir($root.'/'.$directory); }
            }
        }
    }

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
