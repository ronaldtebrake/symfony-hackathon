<?php

declare(strict_types=1);

namespace App\Demo;

use App\Demo\AuditMatrix\Tools;

/** Offline checks of the recorded editorial audit against the original source digest and dataset. */
final class EditorialAuditReplay
{
    public const SOURCE = 'examples/symfony/recipes/editorial-audit.php';
    public const DIGEST = '3fd383311b673047d36f360dde7df6a551eb5cd1653b28305b65a9c6f4e71244';
    public const FIELDS = ['cms_list_articles' => ['id'], 'cms_get_articles' => ['id', 'meta_description', 'image_id'], 'cms_get_media' => ['id', 'alt']];

    public static function checkpoint(): array
    {
        $oracle = (new AuditDataset())->expectedImpact();

        return ['audited' => $oracle['audited'], 'missingDescriptionArticleIds' => $oracle['groups']['missing_description']];
    }

    public static function verify(array $report): void
    {
        $check = static function (bool $pass, string $detail): void {
            if (!$pass) {
                throw new \UnexpectedValueException('Invalid editorial replay evidence: '.$detail);
            }
        };
        $check('symfony-editorial-audit-replay/v1' === $report['format'] && self::SOURCE === $report['sourceFile'] && self::DIGEST === $report['sourceSha256']
            && self::DIGEST === hash('sha256', $report['source'])
            && 0 === $report['modelRequests'], 'recipe or inference');
        $expected = self::checkpoint();
        $dataset = new AuditDataset();
        $check(hash('sha256', json_encode([$dataset->articles(), $dataset->media()], \JSON_THROW_ON_ERROR)) === $report['datasetSha256'], 'dataset');
        foreach (['control', 'revoked'] as $key) {
            $run = $report['runs'][$key];
            $revoked = 'revoked' === $key;
            $guest = $run['sandbox'];
            $check('matrix-reader' === $run['identity'] && 'gvisor' === $guest['profile'] && true === $guest['containerRemoved']
                && hash('sha256', substr($report['source'], 5)) === $guest['programSha256'] && 1 === preg_match('/^sha256:[a-f0-9]{64}$/D', $guest['image']), 'identity or guest');
            $events = $run['editorEvents'];
            $check(['descriptions', 'result'] === array_column($events, 'type') && $expected === $events[0]['value']
                && $expected === $run['outcome']['descriptions'] && $run['outcome'] === $events[1]['value'], 'partial publication');
            $calls = $run['calls'];
            $check(['search_tools', 'execute_tools'] === array_column($calls[0]['response']['result']['tools'], 'name'), 'advertised tools');
            $check(12 === (int) $events[0]['afterCall'] && $events[1]['afterCall'] === \count($calls), 'checkpoint order');
            $check($revoked ? ['afterCall' => 12, 'beforeCall' => 13, 'grant' => 'content.audit', 'action' => 'revoked'] === $run['operator'] : null === $run['operator'], 'operator order');
            $names = [];
            foreach ($calls as $i => $call) {
                $check(200 === $call['httpStatus'] && $call['request']['id'] === $call['response']['id'], 'HTTP or RPC identity');
                if ($i < 4) {
                    if ($i > 0) {
                        $name = Tools::NAMES[$i - 1];
                        $check('search_tools' === $call['request']['params']['name'] && $name === $call['request']['params']['arguments']['query']
                            && \in_array($name, array_column($call['response']['result']['structuredContent']['tools'], 'name'), true), 'discovery');
                    }
                    continue;
                }
                $request = $call['request']['params'];
                $ops = $request['arguments']['calls'];
                $check('execute_tools' === $request['name'] && 1 === \count($ops), 'catalog request');
                $name = $ops[0]['name'];
                $names[] = $name;
                $check(self::FIELDS[$name] === $ops[0]['arguments']['fields'], 'projection');
                $result = $call['response']['result'];
                $trace = $call['trace']['operations'][0];
                $check(1 === \count($result['content']) && 'text' === $result['content'][0]['type'] && $result['structuredContent'] === json_decode($result['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR), 'dual representation');
                $budget = $trace['budget'];
                $after = $budget[array_key_last($budget)];
                $check(0 === $call['trace']['dropped'] && $i - 4 === $budget[0]['attempts'] && $i - 4 === $budget[0]['reservedCalls']
                    && $i - 3 === $after['attempts'] && $i - 3 === $after['reservedCalls'], 'persistent budget');
                if ($revoked && 12 === $i) {
                    $check(true === $result['isError'] && ['error' => ['reason' => 'tool_denied', 'tool' => 'cms_get_media', 'index' => 0], 'completedCalls' => 0] === $result['structuredContent']
                        && [] === $trace['nativeEvents'] && 'denied' === $trace['calls'][0]['status'], 'denied media call');
                    continue;
                }
                $check(!($result['isError'] ?? false) && ['requested', 'succeeded'] === array_column($trace['nativeEvents'], 'phase')
                    && [$name, $name] === array_column($trace['nativeEvents'], 'name'), 'authorized handler');
                self::verifyProjection($i - 4, $ops[0], $result['structuredContent'], $dataset);
            }
            $expectedNames = [];
            for ($i = 0; $i < 4; ++$i) {
                array_push($expectedNames, 'cms_list_articles', 'cms_get_articles');
            }
            $expectedNames[] = 'cms_get_media';
            if (!$revoked) {
                $expectedNames[] = 'cms_get_media';
            }
            $check($names === $expectedNames, 'ordered calls');
            array_splice($expectedNames, 8, 0, ['editor_checkpoint']);
            $check($expectedNames === array_column($guest['calls'], 'name'), 'client broker sequence');
            $expectedMedia = $revoked ? ['status' => 'unavailable', 'reason' => 'tool_denied'] : ['checked' => \count($dataset->media()), 'missingAlt' => $dataset->expectedImpact()['shared_images']];
            $check(['status' => $revoked ? 'partial' : 'complete', 'descriptions' => $expected, 'media' => $expectedMedia] === $run['outcome'], 'editor result');
        }
        $control = $report['runs']['control'];
        $revoked = $report['runs']['revoked'];
        $check($control['sandbox']['image'] === $revoked['sandbox']['image'] && $control['sandbox']['runId'] !== $revoked['sandbox']['runId'], 'distinct guests');
        foreach ($revoked['calls'] as $i => $row) {
            $check($row['request']['params'] === $control['calls'][$i]['request']['params'], 'same tool arguments');
        }
    }

    public static function verifyProjection(int $index, array $operation, array $result, AuditDataset $dataset): void
    {
        $name = $operation['name'];
        $args = $operation['arguments'];
        $offset = $index < 8 ? 12 * intdiv($index, 2) : 12 * ($index - 8);
        $ids = range($offset + 1, $offset + 12);
        $pagination = [];
        if ('cms_list_articles' === $name) {
            $arguments = ['offset' => $offset, 'limit' => 12];
            $pagination = ['total' => 48, 'next_offset' => $offset < 36 ? $offset + 12 : null];
        } else {
            $arguments = ['ids' => $ids];
        }
        $records = 'cms_get_media' === $name ? $dataset->media() : $dataset->articles();
        $rows = array_map(static fn ($id) => array_intersect_key($records[$id], array_flip(self::FIELDS[$name])), $ids);
        if ($arguments + ['fields' => self::FIELDS[$name]] !== $args
            || ['results' => [['name' => $name, 'value' => ['items' => $rows] + $pagination]]] !== $result) {
            throw new \UnexpectedValueException('Projected rows or requested IDs differ from the fixture oracle.');
        }
    }
}
