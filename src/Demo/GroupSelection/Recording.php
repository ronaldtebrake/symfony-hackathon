<?php

declare(strict_types=1);

namespace App\Demo\GroupSelection;

use App\Demo\Refund\Fixture;
use Composer\InstalledVersions;
use Symfony\Component\Filesystem\Filesystem;

/** A private single-task capture for filming. The paired comparison stays in its own report. */
final class Recording
{
    public static function capture(string $root, #[\SensitiveParameter] string $key, string $directory, ?\Closure $onProfilerReady = null): void
    {
        $fs = new Filesystem();
        $fixture = new Fixture($root, null, 'catalog');
        try {
            $share = is_dir($root.'/examples/symfony/var/cache/dev/profiler');
            $endpoint = $fixture->enableProfiler($share);
            $searchUrl = $share ? 'http://127.0.0.1:8001/_profiler/empty/search/results?'.http_build_query([
                'method' => 'POST', 'url' => $endpoint, 'start' => time(), 'limit' => 20,
            ]) : null;
            if (null !== $searchUrl && null !== $onProfilerReady) {
                $onProfilerReady($searchUrl);
            }
            $attempt = Study::attempt($root, null, 'refund', 'groups', $key, $fixture, guided: true);
            if (null !== $searchUrl) {
                $searchUrl .= '&end='.time();
            }
            $protocol = array_intersect_key(Study::protocol(), array_flip(['model', 'provider', 'settings', 'system', 'modelRequestLimit', 'handlerCallLimit', 'account', 'permission']));
            $capture = ['format' => 'symfony-guided-groups-profiler-v1', 'capturedAt' => gmdate(\DATE_ATOM),
                'source' => 'Symfony AI using Hugging Face, authenticated refund catalog and native Symfony Profiler',
                'sdk' => ['package' => 'mcp/sdk', 'version' => InstalledVersions::getPrettyVersion('mcp/sdk'), 'reference' => InstalledVersions::getReference('mcp/sdk')],
                'protocol' => $protocol + ['guidance' => 'The refund-review group returns its reviewed procedure with its tool definitions. The model chooses when and how to call the readers.'],
                'profilerSearchUrl' => $searchUrl, 'attempt' => $attempt];
            $json = json_encode($capture, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
            if (\strlen($json) > 512000 || str_contains($json, $key) || preg_match('/Bearer |Mcp-Session-Id|X-Debug-Token|[?&]_hash=/i', $json)) {
                throw new \UnexpectedValueException('Unsafe profiler capture.');
            }
            $fs->dumpFile($directory.'/conversation.json', $json);
            Proof::attempt($attempt, guided: true);
            if ('passed' !== $attempt['status'] || !\in_array('refund-review', $attempt['selectedGroups'], true)) {
                throw new \RuntimeException('The live review was incomplete. The attempt is retained.');
            }
            $operations = [];
            foreach ($attempt['calls'] as $receipt) {
                foreach ($receipt['trace']['operations'] as $operation) {
                    $operations[] = $operation;
                }
            }
            $panels = $fixture->profilerPanels($operations);
            if ([] === $panels || \count($panels) > 20) {
                throw new \UnexpectedValueException('Profiler panels unavailable.');
            }
            $links = [];
            foreach ($panels as $index => $panel) {
                $operation = $operations[$index];
                $label = $operation['operation'];
                $expected = \sprintf('%03d-%s-%s.html', $index + 1, str_replace('/', '-', $label), $operation['status']);
                if ($expected !== basename($panel)) {
                    throw new \UnexpectedValueException('The Symfony profile does not match the recorded operation.');
                }
                $html = file_get_contents($panel);
                if (false === $html || \strlen($html) > 2 * 1048576 || str_contains($html, $key) || preg_match('/Bearer |Mcp-Session-Id|[?&]_hash=/i', $html)) {
                    throw new \UnexpectedValueException('Unsafe profiler panel.');
                }
                $fs->dumpFile($directory.'/'.$expected, $html);
                $links[] = '<li><a href="'.htmlspecialchars($expected).'">'.htmlspecialchars($label).'</a></li>';
            }
            $index = '<!doctype html><html lang="en"><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                .'<title>Refund review · Symfony Profiler</title><style>body{max-width:44rem;margin:10vh auto;padding:1rem;font:1.15rem/1.65 system-ui;color:#25173b}h1{line-height:1.2}a{color:#60309f}li{padding:.25rem}</style>'
                .'<main><h1>Refund review</h1><p>'.htmlspecialchars($attempt['question']).'</p><h2>Symfony Profiler</h2>'
                .(null !== $searchUrl ? '<p><a href="'.htmlspecialchars($searchUrl).'">Open this run in the local Symfony Profiler</a></p>' : '')
                .'<ol>'.implode('', $links)
                .'</ol><p><a href="conversation.json">Recorded conversation and MCP exchanges</a></p></main></html>';
            $fs->dumpFile($directory.'/index.html', $index);
        } finally {
            $fixture->close();
        }
    }
}
