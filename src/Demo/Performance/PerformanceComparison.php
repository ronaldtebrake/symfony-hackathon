<?php

declare(strict_types=1);

namespace App\Demo\Performance;

/** Compare paired successful captures only; failed attempts still expose their observed costs. */
final class PerformanceComparison
{
    public function summarize(array $direct, string $source): array
    {
        $code = json_decode($source, true) ?? [];
        $codeCheck = (new ConversationVerifier())->verify($code);
        $directCheck = (new BaselineVerifier())->verify($direct, $source);
        $paired = $codeCheck['passed'] && $directCheck['passed'];
        $codeMetrics = self::metrics($code);
        $directMetrics = self::metrics($direct);

        return ['paired' => $paired, 'codeMode' => $codeMetrics, 'directTools' => $directMetrics,
            'codeModeVerification' => $codeCheck, 'directVerification' => $directCheck,
            'totalTokensSavedByCodeMode' => $paired ? $directMetrics['total'] - $codeMetrics['total'] : null,
            'modelRequestsSavedByCodeMode' => $paired ? $directMetrics['requests'] - $codeMetrics['requests'] : null,
            'tokenReductionPercent' => $paired && $directMetrics['total'] > 0 ? round(100 * (1 - $codeMetrics['total'] / $directMetrics['total']), 1) : null,
            'method' => 'Same model, generation settings, two questions, exact public dataset, approved readers and identity. Only calling instructions and tool interface differ. Both turns share conversation history. Automatic provider routing; serving provider not recorded. One paired capture, not a repeated benchmark. Direct calls use supervised native readers; generated PHP uses gVisor. Both keep the same authorization, schemas, ten reads per turn and bounded broker workers.',
        ];
    }

    public static function metrics(array $report): array
    {
        $trace = $report['trace'] ?? [];
        $turns = [];
        foreach ($report['questions'] ?? [] as $i => $question) {
            $turn = $report['turns'][$i] ?? [];
            $end = $turn['requestEnd'] ?? ($trace['modelRequests'] ?? 0);
            $start = $turn['requestStart'] ?? $end;
            $responses = \array_slice($trace['providerResponses'] ?? [], $start, max(0, $end - $start));
            $metered = $end > $start && \count($responses) === $end - $start && !\in_array(null, array_column($responses, 'tokens'), true);
            $turns[] = ['question' => $question, 'complete' => isset($turn['answer']), 'requests' => $end - $start,
                'input' => $metered ? array_sum(array_column(array_column($responses, 'tokens'), 'prompt')) : null,
                'output' => $metered ? array_sum(array_column(array_column($responses, 'tokens'), 'completion')) : null,
                'total' => $metered ? array_sum(array_column(array_column($responses, 'tokens'), 'total')) : null];
        }

        return ['input' => $trace['providerTokens']['prompt'] ?? null, 'output' => $trace['providerTokens']['completion'] ?? null,
            'total' => $trace['providerTokens']['total'] ?? null, 'requests' => $trace['modelRequests'] ?? 0,
            'readerCalls' => \count($trace['brokerCalls'] ?? []), 'turns' => $turns];
    }
}
