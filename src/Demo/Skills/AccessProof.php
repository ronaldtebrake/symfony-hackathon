<?php

declare(strict_types=1);

namespace App\Demo\Skills;

final class AccessProof
{
    private const UNKNOWN_URI = 'skill://unlisted-example/SKILL.md';
    private const METHODS = ['skills/list', 'skills/get', 'resources/list', 'resources/read'];

    public static function record(string $root, string $sdk): array
    {
        $fixture = new Fixture($root, $sdk);
        try {
            $rows = [];
            $revokeObserved = false;
            foreach (self::requests() as $label => [$phase, $user, $method, $params]) {
                if ('revoked' === $phase && !$revokeObserved) {
                    $fixture->revoke();
                    $revokeObserved = true;
                }
                $rows[$label] = ['phase' => $phase, 'user' => $user, 'method' => $method, 'params' => (object) $params,
                    'response' => $fixture->rpc($user, $method, $params)];
            }
            $capture = ['format' => 'symfony-skills-access/v1', 'capturedAt' => gmdate(\DATE_ATOM), 'modelRequests' => 0,
                'protocol' => Fixture::VERSION, 'sdkRevision' => Fixture::REVISION,
                'skillUri' => Fixture::URI, 'articleId' => Fixture::ARTICLE, 'trace' => $rows];

            return $capture;
        } finally {
            $fixture->close();
        }
    }

    /** Replay the same checks against the retained public responses, with no draft dependency or server. */
    public static function verify(array $capture, string $skill): void
    {
        $expect = static function (bool $ok, string $message): void {
            if (!$ok) {
                throw new \UnexpectedValueException($message);
            }
        };
        $trace = $capture['trace'];
        $expect('symfony-skills-access/v1' === $capture['format'] && Fixture::VERSION === $capture['protocol'] && Fixture::REVISION === $capture['sdkRevision']
            && 0 === $capture['modelRequests'] && Fixture::URI === $capture['skillUri'] && Fixture::ARTICLE === $capture['articleId'], 'Unexpected study or fixture.');
        $expect(array_keys($trace) === array_keys(self::requests()), 'Incomplete or reordered trace.');
        $id = 0;
        foreach (self::requests() as $label => [$phase, $user, $method, $params]) {
            ++$id;
            $row = $trace[$label];
            $response = $row['response'];
            $expect([$phase, $user, $method, $params] === [$row['phase'], $row['user'], $row['method'], (array) $row['params']], 'Wrong request for '.$label);
            $cache = array_map('trim', explode(',', strtolower($response['cacheControl'])));
            $vary = array_map('trim', explode(',', strtolower($response['vary'])));
            $expect(\in_array('private', $cache, true) && !\in_array('public', $cache, true) && \in_array('no-store', $cache, true) && \in_array('authorization', $vary, true), 'Unsafe HTTP cache headers on '.$label);
            $expect(1 === preg_match('/^[a-f0-9]{64}$/D', $response['requestSha256']) && 1 === preg_match('/^[a-f0-9]{64}$/D', $response['responseSha256']), 'Missing HTTP body digest on '.$label);
            $reply = $response['reply'];
            if ('anonymous' === $user) {
                $expect(401 === $response['status'] && null === $reply, 'Anonymous user could access metadata.');
                continue;
            }
            $expect($id === $reply['id'] && '2.0' === $reply['jsonrpc'], 'Wrong reply ID on '.$label);
            $hidden = 'viewer' === $user || 'revoked' === $phase;
            if ($hidden && \in_array($method, ['skills/get', 'resources/read'], true)) {
                $expect(400 === $response['status'] && -32602 === ($reply['error']['code'] ?? null) && !isset($reply['result']), 'Hidden URI returned data on '.$label);
                if (Fixture::URI === $params['uri']) {
                    $unknown = $trace[$label.'.absent']['response']['reply']['error'];
                    $expect(str_replace(Fixture::URI, self::UNKNOWN_URI, json_encode($reply['error'], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)) === json_encode($unknown, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), 'Hidden URI is distinguishable from a missing file on '.$label);
                    $expected = 'skills/get' === $method
                        ? ['code' => -32602, 'message' => 'No skill is served at '.self::UNKNOWN_URI]
                        : ['code' => -32602, 'message' => 'Resource not found for uri: "'.self::UNKNOWN_URI.'".', 'data' => ['uri' => self::UNKNOWN_URI]];
                    $expect($expected === $unknown, 'Unexpected data in hidden-entry error.');
                }
                continue;
            }
            $expect(200 === $response['status'] && isset($reply['result']) && !isset($reply['error']), 'Expected a JSON-RPC result on '.$label);
            $result = $reply['result'];
            if ('tools/call' !== $method) {
                $expect(0 === ($result['ttlMs'] ?? null) && 'private' === ($result['cacheScope'] ?? null), 'Unsafe MCP cache result on '.$label);
            }
            if ('server/discover' === $method) {
                $expect(isset($result['capabilities']['extensions'][Fixture::EXTENSION], $result['capabilities']['resources'], $result['capabilities']['tools']), 'Missing negotiated capabilities.');
            } elseif ('skills/list' === $method) {
                $expected = $hidden ? [] : [$trace['granted.reader.skills/get']['response']['reply']['result']['skill']];
                $expect($expected === $result['skills'] && !isset($result['nextCursor']), 'Wrong skill listing on '.$label);
            } elseif ('skills/get' === $method) {
                $manifest = $result['skill'];
                $expect(Fixture::URI === $manifest['uri'] && 'read-published-article' === $manifest['frontmatter']['name']
                    && [['uri' => Fixture::URI, 'digest' => 'sha256:'.hash('sha256', $skill), 'size' => \strlen($skill)]] === $manifest['resources'], 'Unverified skill manifest.');
            } elseif ('resources/list' === $method) {
                $expect(($hidden ? [] : [Fixture::URI]) === array_column($result['resources'], 'uri') && !isset($result['nextCursor']), 'Wrong resource listing on '.$label);
                $expect(!$hidden || [] === $result['resources'], 'Hidden entries in resource listing.');
            } elseif ('resources/read' === $method) {
                $expect(1 === \count($result['contents']) && $skill === $result['contents'][0]['text'] && Fixture::URI === $result['contents'][0]['uri']
                    && 'text/markdown' === $result['contents'][0]['mimeType'], 'Resource differs from reviewed local skill.');
            } elseif ('tools/list' === $method) {
                $tools = array_column($result['tools'], 'name');
                sort($tools);
                $expect(['execute_tools', 'search_tools'] === $tools, 'Unexpected catalog surface.');
            } elseif ('tools/call' === $method) {
                $data = $result['structuredContent'];
                $expect(1 === \count($result['content']) && 'text' === $result['content'][0]['type']
                    && $data === json_decode($result['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR), 'Mismatched MCP representations on '.$label);
                if ('search_tools' === $params['name']) {
                    $expect(false === ($result['isError'] ?? false) && ($hidden ? [] : ['publication_read']) === array_column($data['tools'], 'name')
                        && ($hidden ? 0 : 1) === $data['matched'] && false === $data['truncated'], 'Wrong visible operation.');
                } elseif ($hidden) {
                    $expect(true === $result['isError'] && ['error' => ['reason' => 'tool_denied', 'tool' => 'publication_read', 'index' => 0], 'completedCalls' => 0] === $data, 'Known operation returned data after revocation.');
                } else {
                    $expect(false === ($result['isError'] ?? false) && ['results' => [['name' => 'publication_read', 'value' => ['id' => Fixture::ARTICLE, 'title' => Fixture::TITLE]]]] === $data, 'Wrong held-out article.');
                }
            }
        }
    }

    private static function requests(): array
    {
        $requests = [
            'anonymous.list' => ['granted', 'anonymous', 'skills/list', []],
            'reader.negotiate' => ['granted', 'reader', 'server/discover', []],
            'viewer.negotiate' => ['granted', 'viewer', 'server/discover', []],
            'catalog' => ['granted', 'reader', 'tools/list', []],
        ];
        $search = ['name' => 'search_tools', 'arguments' => ['query' => 'publication_read']];
        $read = ['name' => 'execute_tools', 'arguments' => ['calls' => [['name' => 'publication_read', 'arguments' => ['id' => Fixture::ARTICLE]]]]];
        foreach (['granted', 'revoked'] as $phase) {
            foreach (self::METHODS as $method) {
                // Issue each identical request as the reader, then the viewer, against one running app.
                foreach (['reader', 'viewer'] as $user) {
                    $key = $phase.'.'.$user.'.'.$method;
                    $knownUri = \in_array($method, ['skills/get', 'resources/read'], true);
                    $requests[$key] = [$phase, $user, $method, $knownUri ? ['uri' => Fixture::URI] : []];
                    if ($knownUri && ('viewer' === $user || 'revoked' === $phase)) {
                        $requests[$key.'.absent'] = [$phase, $user, $method, ['uri' => self::UNKNOWN_URI]];
                    }
                }
            }
            if ('granted' === $phase) {
                $requests['discover.operation'] = [$phase, 'reader', 'tools/call', $search];
                $requests['viewer.search'] = [$phase, 'viewer', 'tools/call', $search];
                $requests['read.article'] = [$phase, 'reader', 'tools/call', $read];
            } else {
                $requests['same.read.after.revoke'] = [$phase, 'reader', 'tools/call', $read];
                $requests['same.search.after.revoke'] = [$phase, 'reader', 'tools/call', $search];
            }
        }

        return $requests;
    }
}
