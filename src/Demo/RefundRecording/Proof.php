<?php

declare(strict_types=1);

namespace App\Demo\RefundRecording;

use App\Demo\Refund\Tools;

/** Checks the public transcript against the independent, sanitized HTTP server log. */
final class Proof
{
    public const FORMAT = 'symfony-codex-refund-fallback/v1';

    public static function verify(array $report): void
    {
        self::keys($report, ['format', 'attempts']);
        self::check(self::FORMAT === $report['format'] && \is_array($report['attempts']) && array_is_list($report['attempts']));
        $encoded = json_encode($report, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        self::check(!preg_match('/authorization"?\s*[:=]|bearer\s+\S{12,}|[0-9a-f]{64}|sk-[a-z0-9_-]{16,}|session[-_]?id|\/Users\/|api[-_]?key/i', $encoded));
        $profileCount = 0;
        foreach ($report['attempts'] as $attempt) {
            self::keys($attempt, ['status', 'capturedAt', 'clientVersion', 'instructions', 'model', 'turns', 'wire', 'revokedAfterWire']);
            self::check('captured' === $attempt['status'] && str_starts_with($attempt['clientVersion'], 'codex-cli ')
                && \is_string($attempt['model']) && '' !== $attempt['model'] && \is_string($attempt['instructions']) && '' !== $attempt['instructions']
                && false !== \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $attempt['capturedAt']));
            self::check(2 === \count($attempt['turns']));
            [$first, $second] = $attempt['turns'];
            $order = ['tool' => 'refund_get_order', 'arguments' => ['orderId' => 'R-1042']];
            $policy = ['tool' => 'refund_get_policy', 'arguments' => ['policyId' => 'standard-eu']];
            foreach ([$first, $second] as $turn) {
                self::keys($turn, ['question', 'answer', 'calls', 'status']);
                self::check('completed' === $turn['status'] && \is_string($turn['answer']) && \strlen($turn['answer']) <= 3000);
            }
            self::check('Can you review refund R-1042? What should I do, and why?' === $first['question']
                && 'Can you check the applicable policy again before I act?' === $second['question']);
            self::check([$order, $policy] === $first['calls'] && [$policy] === $second['calls']);
            self::check(1 === preg_match('/manual(?:ly)?[\s*-]+review/i', $first['answer']) && 1 === preg_match('/denied|refused|access|permission|authoriz/i', $second['answer']));
            $wire = $attempt['wire'];
            self::check(['initialize', 'tools/list', 'tools/call', 'tools/call', 'tools/call'] === array_column($wire, 'method') && 4 === $attempt['revokedAfterWire']);
            self::keys($wire[0], ['method', 'httpStatus', 'protocolVersion']);
            self::keys($wire[1], ['method', 'httpStatus', 'tools']);
            self::check(\in_array($wire[0]['protocolVersion'], ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'], true)
                && Tools::NAMES === $wire[1]['tools'] && [200, 200, 200, 200, 200] === array_column($wire, 'httpStatus'));
            $fixture = new Tools();
            $results = [$fixture->order('R-1042'), $fixture->policy('standard-eu'), ['error' => ['reason' => 'tool_denied', 'tool' => 'refund_get_policy', 'index' => 0], 'completedCalls' => 0]];
            $profiles = 0;
            foreach ([$order, $policy, $policy] as $i => $call) {
                $row = $wire[$i + 2];
                $keys = ['method', 'httpStatus', 'tool', 'arguments', 'isError', 'result', 'status', 'nativeEvents'];
                if (isset($row['profiler'])) {
                    ++$profiles;
                    $keys[] = 'profiler';
                    self::check(['operation' => $call['tool'], 'status' => 2 === $i ? 'failed' : 'succeeded', 'reason' => 2 === $i ? 'tool_denied' : null,
                        'nativeEvents' => 2 === $i ? [] : ['requested', 'succeeded']] === $row['profiler']);
                }
                self::keys($row, $keys);
                self::check($call['tool'] === $row['tool'] && $call['arguments'] === $row['arguments'] && $row['result'] === $results[$i]);
                self::check($row['isError'] === (2 === $i) && $row['status'] === (2 === $i ? 'failed' : 'succeeded')
                    && $row['nativeEvents'] === (2 === $i ? [] : ['requested', 'succeeded']));
            }
            self::check(0 === $profiles || 3 === $profiles);
            $profileCount += $profiles;
        }
        self::check($profileCount > 0);
    }

    private static function keys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($keys);
        sort($actual);
        self::check($keys === $actual);
    }

    private static function check(bool $valid): void
    {
        if (!$valid) {
            throw new \UnexpectedValueException('Invalid or incomplete Codex refund rehearsal evidence.');
        }
    }
}
