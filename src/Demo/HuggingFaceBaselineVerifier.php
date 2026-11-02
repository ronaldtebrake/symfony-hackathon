<?php

declare(strict_types=1);

namespace App\Demo;

/** Verify direct reads from captured tool results; no model-written assertion establishes coverage. */
final class HuggingFaceBaselineVerifier
{
    public function verify(array $capture, int $maxModelRequests = 12): array
    {
        if (!in_array($maxModelRequests, [12, 14], true)) { throw new \InvalidArgumentException('Unknown baseline request limit.'); }
        $result = $capture['result'] ?? [];
        $calls = $result['agentCalls'] ?? [];
        $ledger = $result['brokerCalls'] ?? [];
        $aliases = $capture['toolAliases'] ?? [];
        $linked = [] !== $calls && count($calls) === count($ledger) && count($calls) === ($result['agentToolCalls'] ?? null);
        $bytes = 0;
        $articles = [];
        $media = [];
        foreach ($calls as $index => $call) {
            $name = $aliases[$call['name'] ?? ''] ?? null;
            $encoded = $call['result'] ?? null;
            $length = is_string($encoded) ? strlen($encoded) : 0;
            $value = is_string($encoded) ? json_decode($encoded, true) : null;
            $host = $ledger[$index] ?? [];
            $succeeded = 'succeeded' === ($host['status'] ?? null) && !isset($value['error']);
            $rejected = 'incomplete' === ($host['status'] ?? null) && is_string($host['reason'] ?? null)
                && $host['reason'] === ($value['error']['reason'] ?? null) && !isset($value['items']);
            $linked = $linked && in_array($name, BackendFactory::FIXTURE_TOOLS, true)
                && $name === ($ledger[$index]['name'] ?? null) && ($succeeded || $rejected)
                && $length > 0 && $length === ($call['bytesToModel'] ?? null) && $length === ($ledger[$index]['resultBytes'] ?? null);
            $bytes += $length;
            foreach ($value['items'] ?? [] as $item) {
                if ('cms_get_articles' === $name) { $articles[$item['id']] = $item; }
                if ('cms_get_media' === $name) { $media[$item['id']] = $item; }
            }
        }
        ksort($articles);
        ksort($media);
        $dataset = new AuditDataset();
        $expectedArticles = array_filter($dataset->articles(), static fn ($item) => 'published' === $item['status']);
        $answer = json_decode($result['answer'] ?? '', true);
        $audit = new AuditVerifier();
        $checks = [
            'directOnly' => 'direct' === ($result['approach'] ?? null) && [] === ($result['programs'] ?? null) && [] === ($result['executions'] ?? null),
            'finalAnswerMatchesFixture' => $audit->verify($answer, 'fixture', $ledger)['passed'] && $audit->verify($result['report'] ?? null, 'fixture', $ledger)['passed'],
            'allPublishedArticlesAndMediaRead' => $articles === $expectedArticles && $media === $dataset->media(),
            'toolResultsLinkedToHostLedger' => $linked,
            'payloadBytesRecounted' => $bytes > 0 && $bytes === ($result['toolPayloadBytes'] ?? null),
            'modelRequestsObserved' => ($result['modelRequests'] ?? 0) > 0 && ($result['modelRequests'] ?? 0) <= $maxModelRequests,
        ];
        $passed = !in_array(false, $checks, true);
        return ['passed' => $passed, 'checks' => $checks, 'message' => $passed
            ? 'Direct model answer matches all 48 articles and 10 findings; full article/media reads, host ledger and payload checked. No PHP executed.'
            : 'The direct baseline did not pass every required observation. Retain and inspect this attempt.'];
    }
}
