<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\DemoController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;

final class DocumentationBuildTest extends TestCase
{
    public function testPagesPublishesTheSameWorkbenchWithTheJudgeFilmAndItsSources(): void
    {
        $root = \dirname(__DIR__, 2);
        (new Process([\PHP_BINARY, 'scripts/build-docs.php'], $root))->mustRun();
        $output = $root.'/var/pages';
        $request = Request::create('http://localhost/demo', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $this->assertSame((new DemoController($root))->index($request)->getContent(), file_get_contents($output.'/index.html'));
        $this->assertFileEquals($root.'/docs/evidence/agent-procedure.json', $output.'/evidence/agent-procedure.json');
        $this->assertFileEquals($root.'/docs/evidence/judge-demo.json', $output.'/evidence/judge-demo.json');
        $this->assertFileEquals($root.'/docs/evidence/direct-tool.json', $output.'/evidence/direct-tool.json');
        $this->assertFileEquals($root.'/docs/evidence/programmatic-refund.json', $output.'/evidence/programmatic-refund.json');
        $this->assertFileEquals($root.'/docs/evidence/claude-refund-film-session.json', $output.'/evidence/claude-refund-film-session.json');
        $this->assertFileEquals($root.'/docs/evidence/refund-group-discovery.json', $output.'/evidence/refund-group-discovery.json');
        $wire = (new Process([\PHP_BINARY, 'scripts/trace-refund-group.php'], $root))->mustRun();
        $current = json_decode($wire->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        $filmed = json_decode(file_get_contents($output.'/evidence/refund-group-discovery.json'), true, flags: \JSON_THROW_ON_ERROR);
        foreach (['singleTool', 'groups'] as $route) {
            foreach (['request', 'httpStatus', 'handlerEvents'] as $field) {
                $this->assertSame(array_column($filmed[$route]['calls'], $field), array_column($current[$route]['calls'], $field));
            }
        }
        $members = $current['groups']['calls'];
        $orderContract = $members[3]['response']['result']['tools'][0]['outputSchema'];
        $this->assertContains('policyId', $orderContract['required']);
        $this->assertSame('string', $orderContract['properties']['policyId']['type']);
        $this->assertSame($members[4]['response']['result']['structuredContent']['policyId'], $members[5]['request']['params']['arguments']['policyId']);
        $this->assertFileEquals($root.'/docs/evidence/claude-support-review.json', $output.'/evidence/claude-support-review.json');
        $this->assertFileEquals($root.'/docs/evidence/claude-plain-launch.json', $output.'/evidence/claude-plain-launch.json');
        $this->assertFileEquals($root.'/docs/evidence/claude-structured-refund.json', $output.'/evidence/claude-structured-refund.json');
        $this->assertFileEquals($root.'/docs/judge-workflow-voiceover.txt', $output.'/media/judge-workflow-voiceover.txt');
        $plainRun = json_decode(file_get_contents($output.'/evidence/claude-plain-launch.json'), true, flags: \JSON_THROW_ON_ERROR);
        [$orderRead, $policyRead] = \array_slice($plainRun['calls'], 2);
        $this->assertSame($orderRead['programId'], $policyRead['programId']);
        $this->assertSame($orderRead['result']['results'][0]['value']['policyId'], $policyRead['arguments']['calls'][0]['arguments']['policyId']);
        $this->assertSame(['agent-procedure.json', 'catalog-scaling.json', 'claude-plain-launch.json', 'claude-refund-film-session.json', 'claude-structured-refund.json', 'claude-support-review.json', 'direct-tool.json', 'group-selection.json', 'judge-demo.json', 'programmatic-refund.json', 'refund-group-discovery.json'], array_map(basename(...), glob($output.'/evidence/*')));
        $this->assertSame([
            'judge-workflow-voiceover.txt', 'symfony-code-mode-judge-90s.mp4', 'symfony-code-mode-judge-film.json', 'symfony-code-mode-judge-poster.jpg',
        ], array_map(basename(...), glob($output.'/media/*')));
        $manifest = json_decode(file_get_contents($output.'/media/symfony-code-mode-judge-film.json'), true, flags: \JSON_THROW_ON_ERROR);
        foreach ($manifest['assets'] as $filename => $digest) {
            $this->assertSame($digest, hash_file('sha256', $output.'/media/'.$filename), $filename);
        }
        $document = new \DOMDocument();
        $document->loadHTMLFile($output.'/index.html', \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $ids = [];
        foreach ($xpath->query('//@id') as $attribute) {
            $id = $attribute->nodeValue;
            $this->assertArrayNotHasKey($id, $ids, $id);
            $ids[$id] = true;
        }
        foreach ($xpath->query('//@href | //@src | //@poster') as $attribute) {
            $url = $attribute->nodeValue;
            if (str_starts_with($url, '#')) {
                $this->assertArrayHasKey(substr($url, 1), $ids, $url);
            } elseif (!str_starts_with($url, 'https://')) {
                $this->assertStringStartsNotWith('/', $url, 'Links must work when deployed under the repository path.');
                $this->assertFileExists($output.'/'.$url);
            }
        }
    }
}
