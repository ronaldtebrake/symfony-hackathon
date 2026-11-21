<?php

declare(strict_types=1);

namespace App\Demo\RefundRecording;

use App\Demo\Refund\Fixture;
use App\Demo\Refund\Tools;

/** Publish only the native client events and Symfony trace for the disposable refund fixture. */
final class ProgrammaticProof
{
    public static function export(array $transcript, array $wire): array
    {
        [$programs, $model, $version, $question, $answer] = self::client($transcript);
        [$events, $primary, $advertised, $readback] = self::matchCalls($programs, $wire);
        $receipt = ['format' => 'symfony-programmatic-refund/v1', 'client' => 'Claude Code '.$version, 'model' => $model,
            'scope' => 'One native client run against an isolated Symfony HTTP fixture. The host revoked the grant after successful order dispatch and before returning the order response. The client executed the JavaScript locally.',
            'question' => $question, 'advertised' => $advertised, 'script' => $programs[$primary]['source'], 'programId' => $primary, 'events' => $events,
            'stdout' => $programs[$primary]['result']['text'], 'outputKind' => 'Native REPL tool result', 'orderReadbackProgramId' => $readback, 'answer' => $answer, 'programs' => array_values($programs)];
        self::check(!preg_match('~Bearer\s+[a-z0-9._-]+|sk-(?:ant-|proj-)|/Users/|/private/(?:tmp|var)/|<(?:thinking|system-reminder)>~i', json_encode($receipt, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)), 'private field in selected evidence');

        return $receipt;
    }

    /** Recheck the public artifacts without private chat history or a live provider. */
    public static function verify(array $receipt): void
    {
        self::check('symfony-programmatic-refund/v1' === $receipt['format'], 'unknown format');
        $programs = array_column($receipt['programs'], null, 'id');
        self::check(\count($programs) === \count($receipt['programs']), 'duplicate program');
        $wire = [['method' => 'tools/list', 'httpStatus' => 200, 'tools' => $receipt['advertised']]];
        foreach ($receipt['events'] as $event) {
            $wire[] = array_diff_key($event, array_flip(['label', 'programId', 'clientCallId']));
        }
        [$events, $primary, , $readback] = self::matchCalls($programs, $wire);
        self::check($receipt['events'] === $events && $receipt['programId'] === $primary && $receipt['orderReadbackProgramId'] === $readback && $receipt['script'] === $programs[$primary]['source']
            && $receipt['stdout'] === $programs[$primary]['result']['text'], 'display differs from matched events or program');
    }

    private static function client(array $transcript): array
    {
        $programs = $nested = $results = $questions = [];
        $answer = $model = $version = $session = null;
        foreach ($transcript as $row) {
            $role = $row['type'] ?? null;
            if (!\in_array($role, ['assistant', 'user'], true) || ($row['isSidechain'] ?? false)) {
                continue;
            }
            $session ??= $row['sessionId'];
            self::check($session === $row['sessionId'], 'mixed client sessions');
            $message = $row['message'];
            if ('user' === $role && \is_string($message['content'])) {
                $questions[] = $message['content'];
                continue;
            }
            foreach ($message['content'] as $block) {
                if ('user' === $role && 'tool_result' === $block['type']) {
                    $id = $block['tool_use_id'];
                    self::check(!isset($results[$id]) && \is_string($block['content']), 'unexpected client result');
                    $results[$id] = ['text' => $block['content'], 'isError' => $block['is_error'] ?? false];
                } elseif ('assistant' === $role && 'text' === $block['type'] && '<synthetic>' !== $message['model']) {
                    $answer = $block['text'];
                } elseif ('assistant' === $role && 'tool_use' === $block['type']) {
                    $name = $block['name'];
                    $id = $block['id'];
                    if ('REPL' === $name) {
                        $model ??= $message['model'];
                        $version ??= $row['version'];
                        self::check(str_starts_with($model, 'claude-') && $model === $message['model'] && $version === $row['version'] && !isset($programs[$id]), 'mixed or synthetic program authorship');
                        $source = $block['input']['code'];
                        $programs[$id] = ['id' => $id, 'source' => $source, 'sha256' => hash('sha256', $source), 'calls' => []];
                        $answer = null;
                        continue;
                    }
                    $program = array_key_last($programs);
                    self::check(null !== $program && '<synthetic>' === $message['model'] && ($row['isVirtual'] ?? false) && str_starts_with($id, 'repl_'), 'unexpected tool outside the native runtime');
                    self::check(\in_array($name, ['searchTools', 'mcp__symfony_code_mode__search_tools', 'mcp__symfony_code_mode__execute_tools'], true) && !isset($nested[$id]), 'unexpected client tool');
                    $nested[$id] = ['id' => $id, 'name' => $name, 'arguments' => $block['input'], 'programId' => $program];
                }
            }
        }
        self::check(1 === \count($questions) && null !== $answer && false !== stripos($answer, 'incomplete'), 'missing completed conversation');
        foreach ($programs as $id => &$program) {
            self::check(isset($results[$id]), 'missing REPL tool result');
            $program['result'] = $results[$id];
        }
        unset($program);
        foreach ($nested as $id => $call) {
            self::check(isset($results[$id]), 'missing nested tool result');
            $programs[$call['programId']]['calls'][] = $call + ['result' => $results[$id]];
        }

        return [$programs, $model, $version, $questions[0], $answer];
    }

    private static function matchCalls(array $programs, array $wire): array
    {
        $calls = [];
        $ids = [];
        foreach ($programs as $id => $program) {
            self::check($program['sha256'] === hash('sha256', $program['source']), 'program digest differs');
            foreach ($program['calls'] as $call) {
                self::check($call['programId'] === $id && !isset($ids[$call['id']]), 'mixed or duplicate nested call');
                $ids[$call['id']] = true;
                if ('searchTools' !== $call['name']) {
                    $calls[] = $call;
                }
            }
        }
        $events = [];
        $count = 0;
        $advertised = [];
        $primary = $order = $orderResult = null;
        $steps = [];
        foreach ($wire as $row) {
            if (isset($row['operator'])) {
                self::check(['operator' => 'revoke', 'permission' => 'refund.review', 'previous' => 'granted', 'current' => 'revoked'] === $row, 'operator did not confirm revocation');
                $events[] = ['label' => 'The operator revokes refund.review'] + $row;
                $steps[] = 'revoke';
                continue;
            }
            self::check(200 === $row['httpStatus'], 'MCP transport failure');
            if ('tools/list' === $row['method']) {
                $advertised = $row['tools'];
                continue;
            }
            if (\in_array($row['method'], ['initialize', 'server/discover'], true)) {
                continue;
            }
            $call = $calls[$count++] ?? null;
            self::check('tools/call' === $row['method'] && null !== $call && 'mcp__symfony_code_mode__'.$row['tool'] === $call['name'] && $row['arguments'] === $call['arguments'], 'client call and Symfony request differ');
            $args = $row['arguments'];
            $result = $row['result'];
            if ('search_tools' === $row['tool']) {
                $group = isset($args['group']);
                $names = $result['tools'];
                sort($names);
                $keys = $group ? ['tools', 'group', 'instructions'] : ['tools', 'groups'];
                self::check(!$row['isError'] && [] === $row['broker'] && [] === $row['nativeEvents'] && Tools::NAMES === $names && [] === array_diff(array_keys($result), $keys), 'discovery result mismatch');
                self::check($group ? ['group' => 'refund-review'] === $args && 'refund-review' === $result['group'] && Fixture::instructions() === $result['instructions'] : ['query'] === array_keys($args) && ['refund-review'] === $result['groups'], 'wrong Group');
                $steps[] = $group ? 'open' : 'search';
                $label = $group ? 'Open the refund-review Group' : 'Search the Symfony catalog';
            } elseif ('refund_get_order' === ($args['calls'][0]['name'] ?? null)) {
                $order = $result['results'][0]['value'] ?? [];
                $orderResult = $result;
                self::check(!$row['isError'] && [['name' => 'refund_get_order', 'arguments' => ['orderId' => 'R-1042']]] === $args['calls'] && (new Tools())->order('R-1042') === $order, 'wrong authorized order');
                self::check(['results' => [['name' => 'refund_get_order', 'value' => $order]]] === $orderResult && !$call['result']['isError'], 'unexpected order response');
                self::check([['name' => 'refund_get_order', 'status' => 'succeeded']] === $row['broker'] && [['name' => 'refund_get_order', 'phase' => 'requested'], ['name' => 'refund_get_order', 'phase' => 'succeeded']] === $row['nativeEvents'], 'missing order handler dispatch');
                $primary = $call['programId'];
                $steps[] = 'order';
                $label = 'Read R-1042 and receive its policy ID';
            } else {
                self::check(null !== $order && $call['programId'] === $primary && [['name' => 'refund_get_policy', 'arguments' => ['policyId' => $order['policyId']]]] === $args['calls'], 'policy call did not come from the same program with the returned ID');
                self::check(true === $row['isError'] && ['error' => ['reason' => 'tool_denied', 'tool' => 'refund_get_policy', 'index' => 0], 'completedCalls' => 0] === $result && [] === $row['nativeEvents'] && [['name' => 'refund_get_policy', 'status' => 'denied', 'reason' => 'tool_denied']] === $row['broker'], 'missing denial before policy dispatch');
                self::check($call['result']['isError'] && $result === json_decode($call['result']['text'], true, flags: \JSON_THROW_ON_ERROR), 'client did not receive the same denial');
                self::check($programs[$primary]['result']['isError'] && str_contains($programs[$primary]['result']['text'], json_encode($result, \JSON_THROW_ON_ERROR)), 'program did not report the actual denial');
                $steps[] = 'policy';
                $label = 'Deny the policy read before the handler runs';
            }
            $events[] = ['label' => $label, 'clientCallId' => $call['id'], 'programId' => $call['programId']]
                + array_intersect_key($row, array_flip(['method', 'httpStatus', 'tool', 'arguments', 'isError', 'result', 'broker', 'nativeEvents']));
        }
        self::check(['search_tools', 'execute_tools'] === $advertised && $count === \count($calls) && ['search', 'open', 'order', 'revoke', 'policy'] === $steps, 'incomplete or reordered trace');
        self::check(!str_contains($programs[$primary]['source'], $order['policyId']) && str_contains($programs[$primary]['source'], 'policyId'), 'program must use the returned policy ID');

        // The nested success result in this client transcript is empty. After the throw,
        // the client reads its retained order variable without another MCP request.
        $readback = null;
        $afterReads = false;
        foreach ($programs as $id => $program) {
            if ($afterReads && [] === $program['calls'] && !$program['result']['isError'] && preg_match('~<result>(.+?)</result>~s', $program['result']['text'], $match)
                && $orderResult === json_decode($match[1], true)) {
                $readback = $id;
                break;
            }
            $afterReads = $afterReads || $id === $primary;
        }
        self::check(null !== $readback, 'the order retained by the client differs from the server response');

        return [$events, $primary, $advertised, $readback];
    }

    private static function check(bool $valid, string $reason): void
    {
        if (!$valid) {
            throw new \UnexpectedValueException('Invalid programmatic refund capture: '.$reason.'.');
        }
    }
}
