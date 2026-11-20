<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\Skills\AgentStudy;
use App\Demo\Skills\Fixture;
use PHPUnit\Framework\TestCase;

final class ArticleSkillStudyTest extends TestCase
{
    public function testPublishedComparisonsPreserveTheMatchedProtocolsAndTheOriginalScores(): void
    {
        $report = json_decode(file_get_contents(\dirname(__DIR__, 2).'/docs/evidence/skills-agent.json'), true, flags: \JSON_THROW_ON_ERROR);
        $byProvider = [];
        foreach ($report['attempts'] as $attempt) {
            $protocol = $attempt['protocol'];
            $provider = $protocol['providerRoute'];
            // The second capture added the SDK revision to the provenance metadata.
            if (isset($protocol['sdkRevision'])) {
                $this->assertSame(Fixture::REVISION, $protocol['sdkRevision']);
            }
            unset($protocol['providerRoute'], $protocol['sdkRevision']);
            $byProvider[$provider] = $protocol;
            $this->assertSame(['search', 'skill'], array_column($attempt['arms'], 'arm'));
            [$search, $skill] = $attempt['arms'];
            $this->assertSame($search['catalogSha256'], $skill['catalogSha256']);
            foreach ($attempt['arms'] as $arm) {
                $this->assertSame(AgentStudy::score($arm['answer'], $arm['calls']), $arm['score']);
                $this->assertSame($arm['requests']['provider'], \count($arm['providerHttp']));
                $this->assertSame([$protocol['model'].':'.$provider], array_values(array_unique(array_column($arm['providerHttp'], 'requestedModel'))));
                $this->assertSame(array_sum(array_column(array_column($arm['providerResponses'], 'tokens'), 'total')), $arm['tokens']['total']);
            }
            if ('novita' === $provider) {
                $this->assertTrue($search['score']['readHeldOutArticle']);
                $this->assertFalse($search['score']['exactAnswer']);
                $this->assertSame(AgentStudy::TITLE, str_replace("\u{00a0}", ' ', $search['answer']));
                $this->assertTrue($skill['score']['correct']);
            }
        }
        $this->assertSame($byProvider['deepinfra'], $byProvider['novita']);
    }

    public function testActivationRejectsAChangedManifestEvenWhenTheRemoteDigestAgrees(): void
    {
        $root = \dirname(__DIR__, 2);
        $record = json_decode(file_get_contents($root.'/docs/evidence/skill-interop.json'), true, flags: \JSON_THROW_ON_ERROR);
        $manifest = $record['manifest'];
        $text = file_get_contents($root.'/examples/symfony/skills/read-published-article/SKILL.md');
        $file = ['uri' => AgentStudy::URI, 'mimeType' => 'text/markdown', 'text' => $text];
        $this->assertSame($text, AgentStudy::verifiedText(['skills' => [$manifest]], ['skill' => $manifest], ['contents' => [$file]]));
        $this->assertStringNotContainsString('317', $text);
        $this->assertStringNotContainsString(AgentStudy::TITLE, $text);

        $file['text'] = str_replace('101', '202', $text);
        $manifest['resources'][0]['digest'] = 'sha256:'.hash('sha256', $file['text']);
        $this->expectException(\UnexpectedValueException::class);
        AgentStudy::verifiedText(['skills' => [$manifest]], ['skill' => $manifest], ['contents' => [$file]]);
    }

    public function testExecutionAndAnswerMustBothBelongToTheHeldOutArticle(): void
    {
        $search = ['name' => 'search_tools', 'isError' => false, 'result' => ['tools' => [['name' => 'publication_read']]]];
        $read = ['name' => 'execute_tools', 'arguments' => ['calls' => [['name' => 'publication_read', 'arguments' => ['id' => 317]]]], 'isError' => false,
            'result' => ['results' => [['name' => 'publication_read', 'value' => ['id' => 317, 'title' => AgentStudy::TITLE]]]]];
        $this->assertTrue(AgentStudy::score(AgentStudy::TITLE, [$search, $read])['correct']);
        $this->assertFalse(AgentStudy::score(AgentStudy::TITLE, [])['correct']);
        $this->assertFalse(AgentStudy::score('Unknown', [$search, $read])['correct']);
        $this->assertFalse(AgentStudy::score(AgentStudy::TITLE, [$read, $search])['correct']);
        $read['arguments']['calls'][0]['arguments']['id'] = 101;
        $this->assertFalse(AgentStudy::score(AgentStudy::TITLE, [$search, $read])['correct']);
    }
}
