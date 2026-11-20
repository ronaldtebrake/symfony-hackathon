<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\DemoController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;

final class DocumentationBuildTest extends TestCase
{
    public function testPagesPublishesTheSameWorkbenchWithOnlyItsLinkedAssets(): void
    {
        $root = \dirname(__DIR__, 2);
        (new Process([\PHP_BINARY, 'scripts/build-docs.php'], $root))->mustRun();
        $output = $root.'/var/pages';
        $request = Request::create('http://localhost/demo', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $this->assertSame((new DemoController($root))->index($request)->getContent(), file_get_contents($output.'/index.html'));
        $this->assertFileEquals($root.'/docs/evidence/judge-demo.json', $output.'/evidence/judge-demo.json');
        $this->assertSame(['judge-demo.json'], array_map(basename(...), glob($output.'/evidence/*')));
        $this->assertSame(['editorial-review-90s.mp4', 'editorial-review-poster.jpg'], array_map(basename(...), glob($output.'/media/*')));
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
