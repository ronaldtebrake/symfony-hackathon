<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\Skills\Fixture;
use App\Demo\Skills\WorkflowSecurityProof;
use PHPUnit\Framework\TestCase;

final class WorkflowSecurityProofTest extends TestCase
{
    public function testReviewedSkillInputsAreCheckedBeforeInferenceOrExecution(): void
    {
        $trace = $this->evidence('skills-http')['trace'];
        $inputs = [];
        foreach (['reader.negotiate', 'catalog', 'granted.reader.skills/list', 'granted.reader.skills/get', 'granted.reader.resources/read'] as $step) {
            $inputs[$trace[$step]['method']] = $trace[$step]['response'];
        }

        $checks = WorkflowSecurityProof::hostChecks($inputs);
        $this->assertSame(['edited text', 'edited description', 'different resource URI'], array_column($checks, 'change'));
        $this->assertSame([0, 0, 0], array_column($checks, 'providerRequests'));
        $this->assertSame([0, 0, 0], array_column($checks, 'agentToolCalls'));
        $this->assertSame($this->evidence('workflow-security')['hostIntegrityChecks'], $checks);
    }

    public function testHttpEvidenceUsesTheSameReadAcrossPrincipalsAndRevocation(): void
    {
        $proof = $this->evidence('workflow-security');
        $this->assertSame(Fixture::REVISION, $proof['sdkRevision']);
        $steps = $proof['httpIdentityAndRevocation'];
        $this->assertSame(['publication_read'], $steps['readerDiscovery']['visible']);
        $this->assertSame([], $steps['viewerDiscovery']['visible']);
        $reader = $steps['sameCallAsReader'];
        $this->assertSame('reader', $reader['user']);
        $this->assertSame('viewer', $steps['sameCallAsViewer']['user']);
        $this->assertSame(['results' => [['name' => 'publication_read', 'value' => ['id' => Fixture::ARTICLE, 'title' => Fixture::TITLE]]]], $reader['result']);
        foreach (['skillOnly' => 'discovery_required', 'sameCallAsViewer' => 'discovery_required', 'sameCallAfterRevoke' => 'tool_denied'] as $step => $reason) {
            $this->assertSame($reader['arguments'], $steps[$step]['arguments']);
            $this->assertSame($reason, $steps[$step]['result']['error']['reason']);
            $this->assertSame(0, $steps[$step]['result']['completedCalls']);
            $this->assertArrayNotHasKey('results', $steps[$step]['result']);
        }

        $records = $proof['httpRecordAccessAndBudget'];
        $this->assertSame(['publication_read'], $records['rediscovery']['visible']);
        $this->assertSame(202, $records['unpublished']['arguments']['calls'][0]['arguments']['id']);
        $this->assertSame(404, $records['missing']['arguments']['calls'][0]['arguments']['id']);
        $this->assertSame($steps['sameCallAfterRevoke']['result'], $records['unpublished']['result']);
        $this->assertSame($records['unpublished']['result'], $records['missing']['result']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $records['unpublished']['rpcResultSha256']);
        $this->assertSame($records['unpublished']['rpcResultSha256'], $records['missing']['rpcResultSha256']);
        $this->assertSame($reader['arguments'], $records['publishedAfterTwoDenials']['arguments']);
        $this->assertSame(['error' => ['reason' => 'execution_limit'], 'completedCalls' => 0], $records['publishedAfterTwoDenials']['result']);
    }

    private function evidence(string $name): array
    {
        return json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/'.$name.'.json'), true, flags: \JSON_THROW_ON_ERROR);
    }
}
