<?php

declare(strict_types=1);

namespace App\Demo;

/** Verify direct reads from captured tool results; no model-written assertion establishes coverage. */
final class HuggingFaceBaselineVerifier
{
    public function verify(array $capture): array
    {
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
            $linked = $linked && in_array($name, BackendFactory::FIXTURE_TOOLS, true)
                && $name === ($ledger[$index]['name'] ?? null) && 'succeeded' === ($ledger[$index]['status'] ?? null)
                && $length > 0 && $length === ($call['bytesToModel'] ?? null) && $length === ($ledger[$index]['resultBytes'] ?? null);
            $bytes += $length;
            $value = is_string($encoded) ? json_decode($encoded, true) : null;
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
            'modelRequestsObserved' => ($result['modelRequests'] ?? 0) > 0 && ($result['modelRequests'] ?? 0) <= 12,
        ];
        $passed = !in_array(false, $checks, true);
        return ['passed' => $passed, 'checks' => $checks, 'message' => $passed
            ? 'Direct model answer matches all 48 articles and 10 findings; full article/media reads, host ledger and payload checked. No PHP executed.'
            : 'The direct baseline did not pass every required observation. Retain and inspect this attempt.'];
    }
}
