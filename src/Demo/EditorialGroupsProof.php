<?php

declare(strict_types=1);

namespace App\Demo;

use App\Demo\AuditMatrix\Tools;
use App\Demo\Groups\PublishingGroups;

final class EditorialGroupsProof
{
    private const PATHS = ['catalog', 'catalogBrowse', 'groups'];

    public static function verify(array $report, string $root): void
    {
        $archive = json_decode(file_get_contents($root.'/docs/evidence/editorial-audit-replay.json'), true, flags: \JSON_THROW_ON_ERROR);
        $source = $archive['source'];
        $dataset = new AuditDataset();
        $initial = 'symfony-editorial-groups/v1' === $report['format'];
        $paths = $initial ? ['catalog', 'groups'] : self::PATHS;
        self::check(($initial || 'symfony-editorial-groups/v2' === $report['format']) && $paths === array_keys($report['paths']) && 0 === $report['modelRequests']
            && EditorialAuditReplay::SOURCE === $report['sourceFile'] && EditorialAuditReplay::DIGEST === $report['sourceSha256']
            && $report['sourceSha256'] === hash('sha256', $source)
            && $report['datasetSha256'] === hash('sha256', json_encode([$dataset->articles(), $dataset->media()], \JSON_THROW_ON_ERROR)), 'recipe or fixture');
        if (!$initial) {
            self::check(isset($report['hostSourceSha256']['src/Demo/AuditMatrix/Gateway.php'], $report['hostSourceSha256']['composer.lock']), 'source manifest');
            foreach ($report['hostSourceSha256'] as $hash) {
                self::check(1 === preg_match('/^[a-f0-9]{64}$/D', $hash), 'source digest');
            }
        }
        foreach ($report['responses'] as $hash => $body) {
            self::check(hash('sha256', $body) === $hash, 'response digest');
        }
        $guests = $images = $contracts = [];
        $seen = [];
        foreach ($paths as $path) {
            foreach (['control', 'revoked'] as $scenario) {
                $run = $report['paths'][$path][$scenario];
                $guest = $run['sandbox'];
                $guests[] = $guest['runId'];
                $images[] = $guest['image'];
                self::check('matrix-reader' === $run['identity'] && 'gvisor' === $guest['profile'] && true === $guest['containerRemoved']
                    && preg_match('/^sha256:[a-f0-9]{64}$/D', $guest['image'])
                    && hash('sha256', substr($source, 5)) === $guest['programSha256'], 'replay isolation');
                $group = 'groups' === $path;
                $revoked = 'revoked' === $scenario;
                $meta = $run['clientMeta'];
                self::check('2026-07-28' === $meta['io.modelcontextprotocol/protocolVersion']
                    && $group === isset($meta['io.modelcontextprotocol/clientCapabilities']['extensions'][PublishingGroups::ID]), 'negotiation');
                $calls = $run['calls'];
                $metrics = self::metrics($calls, $report['responses']);
                self::check($run['metrics'] === $metrics && 1 === $metrics['negotiationRequests']
                    && (match ($path) {
                        'catalog' => 4,
                        'catalogBrowse' => 2,
                        'groups' => 3,
                    }) === $metrics['discoveryRequests'] && ($revoked ? 9 : 10) === $metrics['executionRequests'], 'discovery or execution counts');
                $discovered = match ($path) {
                    'catalog' => ['server/discover', 'tools/list', 'tools/call', 'tools/call', 'tools/call'],
                    'catalogBrowse' => ['server/discover', 'tools/list', 'tools/call'],
                    'groups' => ['server/discover', 'tools/list', 'groups/list', 'groups/get'],
                };
                $offset = \count($discovered);
                $workflow = [];
                foreach ($calls as $i => $receipt) {
                    $seen[$receipt['responseSha256']] = true;
                    $reply = json_decode($report['responses'][$receipt['responseSha256']], true, flags: \JSON_THROW_ON_ERROR);
                    $request = $receipt['request'];
                    $result = $reply['result'];
                    self::check(200 === $receipt['httpStatus'] && $request['id'] === $i + 1 && $reply['id'] === $request['id']
                        && '2.0' === $reply['jsonrpc'] && '2.0' === $request['jsonrpc'] && !isset($reply['error']), 'HTTP exchange');
                    if ($i < $offset) {
                        self::check($request['method'] === $discovered[$i], 'discovery method order');
                        if (0 === $i && $group) {
                            self::check(isset($result['capabilities']['extensions'][PublishingGroups::ID]), 'server capability');
                        } elseif (1 === $i) {
                            self::check(['search_tools', 'execute_tools'] === array_column($result['tools'], 'name'), 'initial tool list');
                        } elseif (2 === $i && 'catalogBrowse' === $path) {
                            self::check(['name' => 'search_tools', 'arguments' => ['query' => '']] === $request['params'], 'broad catalog browse');
                            $contracts[$path][$scenario] = array_column($result['structuredContent']['tools'], 'inputSchema', 'name');
                        } elseif ($i >= 2 && 'catalog' === $path) {
                            $name = Tools::NAMES[$i - 2];
                            $tools = $result['structuredContent']['tools'];
                            self::check('search_tools' === $request['params']['name'] && $name === $request['params']['arguments']['query'], 'catalog query');
                            $found = array_column($tools, 'inputSchema', 'name');
                            self::check(isset($found[$name]), 'catalog contract');
                            $contracts[$path][$scenario][$name] = $found[$name];
                        } elseif (2 === $i) {
                            self::check(['publishing'] === array_column($result['groups'], 'name'), 'group listing');
                        } elseif (3 === $i) {
                            self::check(['name' => 'publishing'] === $request['params'] && \count(Tools::NAMES) === \count($result['tools']), 'selected group');
                            $contracts[$path][$scenario] = array_column($result['tools'], 'inputSchema', 'name');
                        }
                        continue;
                    }
                    $index = $i - $offset;
                    $p = $request['params'];
                    self::check('tools/call' === $request['method'] && ($group ? \in_array($p['name'], Tools::NAMES, true) : 'execute_tools' === $p['name'] && 1 === \count($p['arguments']['calls'])), 'reader invocation');
                    $op = $group ? $p : $p['arguments']['calls'][0];
                    $workflow[] = $op['name'];
                    self::verifyRead($index, $op, $result, $receipt['trace'], $revoked && 8 === $index, $group, $dataset);
                }
                ksort($contracts[$path][$scenario]);
                $names = [];
                for ($i = 0; $i < 4; ++$i) {
                    array_push($names, 'cms_list_articles', 'cms_get_articles');
                }
                $names[] = 'cms_get_media';
                if (!$revoked) {
                    $names[] = 'cms_get_media';
                }
                self::check($names === $workflow, 'ordered readers');
                array_splice($names, 8, 0, ['editor_checkpoint']);
                self::check($names === array_column($guest['calls'], 'name'), 'guest broker operations');
                $checkpoint = EditorialAuditReplay::checkpoint();
                $media = $revoked ? ['status' => 'unavailable', 'reason' => 'tool_denied'] : ['checked' => \count($dataset->media()), 'missingAlt' => $dataset->expectedImpact()['shared_images']];
                $outcome = ['status' => $revoked ? 'partial' : 'complete', 'descriptions' => $checkpoint, 'media' => $media];
                self::check($outcome === $run['outcome'] && [
                    ['type' => 'descriptions', 'afterCall' => $offset + 8, 'value' => $checkpoint],
                    ['type' => 'result', 'afterCall' => \count($calls), 'value' => $outcome],
                ] === $run['editorEvents'], 'editor output');
                self::check(($revoked ? ['afterCall' => $offset + 8, 'beforeCall' => $offset + 9, 'grant' => 'content.audit', 'action' => 'revoked'] : null) === $run['operator'], 'operator checkpoint');
            }
        }
        self::check(2 * \count($paths) === \count(array_unique($guests)) && 1 === \count(array_unique($images)) && !array_diff_key($report['responses'], $seen), 'unlinked evidence');
        foreach ($contracts as $runs) {
            self::check($contracts['catalog']['control'] === $runs['control'] && $runs['control'] === $runs['revoked'], 'same projected schemas');
        }
    }

    private static function metrics(array $receipts, array $bodies): array
    {
        $counts = ['negotiationRequests' => 0, 'discoveryRequests' => 0, 'executionRequests' => 0, 'totalRequests' => \count($receipts), 'discoveryResponseBytes' => 0];
        foreach ($receipts as $receipt) {
            $request = $receipt['request'];
            if ('server/discover' === $request['method']) {
                ++$counts['negotiationRequests'];
            } elseif ('tools/call' === $request['method'] && 'search_tools' !== $request['params']['name']) {
                ++$counts['executionRequests'];
            } else {
                ++$counts['discoveryRequests'];
                $counts['discoveryResponseBytes'] += \strlen($bodies[$receipt['responseSha256']]);
            }
        }

        return $counts;
    }

    private static function verifyRead(int $index, array $op, array $result, array $trace, bool $denied, bool $group, AuditDataset $dataset): void
    {
        $name = $op['name'];
        $audit = $trace['operations'][0];
        $budget = $audit['budget'];
        $last = $budget[array_key_last($budget)];
        self::check(0 === $trace['dropped'] && 1 === \count($trace['operations']) && $index === $budget[0]['attempts'] && $index === $budget[0]['reservedCalls']
            && $index + 1 === $last['attempts'] && $index + 1 === $last['reservedCalls'], 'per-read budget');
        self::check(1 === \count($result['content']) && 'text' === $result['content'][0]['type']
            && $result['structuredContent'] === json_decode($result['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR), 'text and structured result');
        $data = $result['structuredContent'];
        if ($denied) {
            self::check(['name' => 'cms_get_media', 'arguments' => ['ids' => range(1, 12), 'fields' => ['id', 'alt']]] === $op
                && true === $result['isError'] && ['error' => ['reason' => 'tool_denied', 'tool' => 'cms_get_media', 'index' => 0], 'completedCalls' => 0] === $data
                && [] === $audit['nativeEvents'] && 'denied' === $audit['calls'][0]['status'], 'denied media read and handler');

            return;
        }
        self::check(!($result['isError'] ?? false) && ['requested', 'succeeded'] === array_column($audit['nativeEvents'], 'phase')
            && [$name, $name] === array_column($audit['nativeEvents'], 'name'), 'authorized handler');
        EditorialAuditReplay::verifyProjection($index, $op, $group ? ['results' => [['name' => $name, 'value' => $data]]] : $data, $dataset);
    }

    private static function check(bool $valid, string $why): void
    {
        if (!$valid) {
            throw new \UnexpectedValueException('Invalid Groups proof: '.$why);
        }
    }
}
