<?php

declare(strict_types=1);

namespace App\Demo;

/** Pair an observed direct invocation with the exact reviewed Code Mode capture it references. */
final class HuggingFaceComparison
{
    public function build(string $codeJson, array $direct): array
    {
        $code = json_decode($codeJson, true, flags: JSON_THROW_ON_ERROR);
        $descriptions = null;
        foreach ($code['result']['agentCalls'] ?? [] as $call) {
            if ('tools_describe' === $call['name']) { $descriptions = json_decode($call['result'], true); }
        }
        $dataset = new AuditDataset();
        $checks = [
            'codeCaptureBound' => hash('sha256', $codeJson) === ($direct['codeCaptureSha256'] ?? null),
            'sameModel' => ($code['model'] ?? null) === ($direct['model'] ?? null) && is_string($code['model'] ?? null),
            'sameQuestion' => AuditDataset::TASK === ($code['task'] ?? null) && $code['task'] === ($direct['task'] ?? null),
            'sameEndpoint' => ($code['endpoint'] ?? null) === ($direct['endpoint'] ?? null),
            'sameComponents' => ($code['components'] ?? null) === ($direct['components'] ?? null),
            'baselineGenerationSettings' => ['temperature' => 0, 'max_tokens' => 8192, 'providerRouting' => 'auto'] === ($direct['generationSettings'] ?? null),
            'approvedReadIdentity' => ['subject' => 'demo-editor', 'capabilities' => ['content.audit']] === ($direct['identity'] ?? null),
            'sameToolSchemas' => is_array($descriptions) && $descriptions === json_decode(json_encode($direct['approvedTools'] ?? null), true),
            'sameFixture' => 'Symfony MCP fixture' === ($code['backend'] ?? null) && $code['backend'] === ($direct['backend'] ?? null)
                && hash('sha256', json_encode([$dataset->articles(), $dataset->media()], JSON_THROW_ON_ERROR)) === ($direct['datasetSha256'] ?? null),
            'codeResultVerified' => 'complete' === ($code['status'] ?? null) && (new HuggingFaceProofVerifier())->verify($code['result'] ?? [])['passed'],
            'directResultVerified' => 'complete' === ($direct['status'] ?? null) && (new HuggingFaceBaselineVerifier())->verify($direct)['passed'],
            'providerUsageReported' => $this->validUsage($code) && $this->validUsage($direct),
        ];
        $complete = !in_array(false, $checks, true);
        return ['kind' => 'huggingface-comparison', 'status' => $complete ? 'complete' : (in_array($direct['status'] ?? 'empty', ['running', 'empty'], true) ? ($direct['status'] ?? 'empty') : 'failed'),
            'verifiedComparison' => $complete, 'checks' => $checks, 'code' => $code, 'direct' => $direct,
            'observedTotals' => ['code' => $this->totals($code), 'direct' => $this->totals($direct)],
            'difference' => $complete ? [
                'tokens' => $direct['result']['providerTokens']['total'] - $code['result']['providerTokens']['total'],
                'modelRequests' => $direct['result']['modelRequests'] - $code['result']['modelRequests'],
                'tokenReductionPercent' => 100 * (1 - $code['result']['providerTokens']['total'] / $direct['result']['providerTokens']['total']),
            ] : null];
    }

    private function validUsage(array $capture): bool
    {
        $usage = $capture['result']['providerTokens'] ?? [];
        return is_int($usage['prompt'] ?? null) && $usage['prompt'] >= 0 && is_int($usage['completion'] ?? null) && $usage['completion'] >= 0
            && is_int($usage['total'] ?? null) && $usage['total'] > 0 && $usage['total'] === $usage['prompt'] + $usage['completion'];
    }

    private function totals(array $capture): array
    {
        $attempts = [...($capture['previousAttempts'] ?? []), $capture];
        $tokens = 0;
        $requests = 0;
        foreach ($attempts as $attempt) {
            $requests += $attempt['result']['modelRequests'] ?? 0;
            $tokens = null === $tokens || !$this->validUsage($attempt) ? null : $tokens + $attempt['result']['providerTokens']['total'];
        }
        return ['attempts' => count($attempts), 'modelRequests' => $requests, 'providerTokens' => $tokens];
    }
}
