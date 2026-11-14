<?php

declare(strict_types=1);

namespace App\Demo;

final class AuditVerifier
{
    public function verify(mixed $report, string $backend, array $brokerCalls, ?DemoQuestion $question = null): array
    {
        $report = json_decode(json_encode($report, \JSON_THROW_ON_ERROR), true, 32, \JSON_THROW_ON_ERROR);
        $readTools = array_unique(array_column(array_filter($brokerCalls, static fn ($call): bool => 'succeeded' === ($call['status'] ?? null)), 'name'));
        if ('custom' === $question?->id) {
            if (!\is_array($report) || array_is_list($report) || \array_key_exists('error', $report)
                || [] === array_intersect(BackendFactory::FIXTURE_TOOLS, $readTools)) {
                return ['passed' => false, 'message' => 'No supported JSON answer with successful MCP reads was returned.'];
            }

            return ['passed' => null, 'message' => 'Custom question: execution returned JSON with MCP reads. Review the answer; correctness has not been independently verified.'];
        }
        if ('impact' === $question?->id) {
            $expected = (new AuditDataset())->expectedImpact();
            // Object-key order is irrelevant; list order and scalar types must match the contract.
            $passed = 'fixture' === $backend && \is_array($report)
                && $this->canonical($report) === $this->canonical($expected)
                && [] === array_diff(BackendFactory::FIXTURE_TOOLS, $readTools);

            return ['passed' => $passed, 'message' => $passed
                ? 'All 48 articles accounted for; both issue groups and all 3 shared-image impacts match.'
                : 'The grouped result differs from the fixture or has no supporting MCP reads.'];
        }
        if (!\is_array($report) || !\is_int($report['audited'] ?? null) || $report['audited'] < 0 || !\is_array($report['findings'] ?? null) || !array_is_list($report['findings']) || \count($report['findings']) > $report['audited']) {
            return ['passed' => false, 'message' => 'No valid structured audit was returned.'];
        }
        $actual = [];
        foreach ($report['findings'] as $finding) {
            if (!\is_array($finding) || !\is_int($finding['id'] ?? null) || !\is_string($finding['title'] ?? null) || !\is_array($finding['issues'] ?? null)
                || !array_is_list($finding['issues']) || [] === $finding['issues'] || \count($finding['issues']) !== \count(array_unique($finding['issues'], \SORT_REGULAR))
                || isset($actual[$finding['id']]) || array_filter($finding['issues'], static fn ($issue): bool => !\in_array($issue, ['missing_description', 'missing_alt'], true))) {
                return ['passed' => false, 'message' => 'The findings contain an invalid or repeated item.'];
            }
            $issues = $finding['issues'];
            sort($issues);
            $actual[$finding['id']] = ['id' => $finding['id'], 'title' => $finding['title'], 'issues' => $issues];
        }
        if ('fixture' !== $backend) {
            return ['passed' => null, 'message' => 'External data: review these findings against the source application. No fixture oracle applies.'];
        }
        $expected = (new AuditDataset())->expected();
        $wanted = [];
        foreach ($expected['findings'] as $finding) {
            sort($finding['issues']);
            $wanted[$finding['id']] = $finding;
        }
        ksort($actual);
        ksort($wanted);
        $passed = $actual === $wanted && $report['audited'] === $expected['audited'] && [] === array_diff(BackendFactory::FIXTURE_TOOLS, $readTools);

        return ['passed' => $passed, 'message' => $passed ? 'All 48 published articles checked; all 10 expected findings match.' : 'The returned audit differs from the fixture or has no supporting MCP reads.'];
    }

    private function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (\is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}
