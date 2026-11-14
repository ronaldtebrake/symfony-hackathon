<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\AuditDataset;
use App\Demo\AuditVerifier;
use App\Demo\BackendFactory;
use App\Demo\DemoQuestion;
use PHPUnit\Framework\TestCase;

final class AuditVerifierTest extends TestCase
{
    public function testImpactQuestionHasItsOwnOracleAndRejectsWrongAnswers(): void
    {
        $verifier = new AuditVerifier();
        $question = DemoQuestion::preset('impact');
        $calls = array_map(static fn ($name): array => ['name' => $name, 'status' => 'succeeded'], BackendFactory::FIXTURE_TOOLS);
        $expected = ['audited' => 48, 'groups' => ['missing_description' => [11, 22, 33, 44], 'missing_alt' => [7, 14, 21, 31, 38, 45]],
            'shared_images' => [['image_id' => 7, 'article_ids' => [7, 31]], ['image_id' => 14, 'article_ids' => [14, 38]], ['image_id' => 21, 'article_ids' => [21, 45]]]];
        $this->assertSame($expected, (new AuditDataset())->expectedImpact());
        $this->assertTrue($verifier->verify($expected, 'fixture', $calls, $question)['passed']);
        $this->assertFalse($verifier->verify($expected, 'fixture', [], $question)['passed']);
        $this->assertFalse($verifier->verify((new AuditDataset())->expected(), 'fixture', $calls, $question)['passed']);
        $expected['shared_images'][0]['article_ids'][] = 49;
        $this->assertFalse($verifier->verify($expected, 'fixture', $calls, $question)['passed']);
    }

    public function testCustomAnswersRequireReviewAndCannotClaimFixtureVerification(): void
    {
        $verifier = new AuditVerifier();
        $question = DemoQuestion::custom('Count published articles.');
        $calls = [['name' => 'cms_list_articles', 'status' => 'succeeded']];
        $this->assertNull($verifier->verify(['count' => 999], 'fixture', $calls, $question)['passed']);
        $this->assertFalse($verifier->verify(['count' => 48], 'fixture', [], $question)['passed']);
        $this->assertFalse($verifier->verify(['error' => 'Unsupported question'], 'fixture', $calls, $question)['passed']);
        $this->assertFalse($verifier->verify('48', 'fixture', $calls, $question)['passed']);
    }

    public function testCorrectLookingReportRequiresSuccessfulReads(): void
    {
        $calls = array_map(static fn ($name): array => ['name' => $name, 'status' => 'incomplete'], BackendFactory::FIXTURE_TOOLS);
        $this->assertFalse((new AuditVerifier())->verify((new AuditDataset())->expected(), 'fixture', $calls)['passed']);
    }

    public function testExternalResultsAreValidatedButNeverClaimedAsVerified(): void
    {
        $verifier = new AuditVerifier();
        $this->assertNull($verifier->verify((new AuditDataset())->expected(), 'http', [])['passed']);
        $this->assertFalse($verifier->verify(['audited' => -1, 'findings' => []], 'http', [])['passed']);
        $this->assertFalse($verifier->verify(['audited' => 1, 'findings' => [['id' => 1, 'title' => 'Article', 'issues' => ['missing_alt', 'missing_alt']]]], 'http', [])['passed']);
    }
}
