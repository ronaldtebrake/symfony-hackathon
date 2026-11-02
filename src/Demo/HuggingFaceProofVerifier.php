<?php

declare(strict_types=1);

namespace App\Demo;

/** Check execution evidence, not a model's assertion that it succeeded. */
final class HuggingFaceProofVerifier
{
    public function verify(array $result): array
    {
        $audit = new AuditVerifier();
        $value = $audit->verify($result['report'] ?? null, 'fixture', $result['brokerCalls'] ?? []);
        try {
            $answer = json_decode($result['answer'] ?? '', true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $answer = null;
        }
        $final = $audit->verify($answer, 'fixture', $result['brokerCalls'] ?? []);
        $calls = $result['agentCalls'] ?? [];
        $executions = $result['executions'] ?? [];
        $programs = $result['programs'] ?? [];
        $submitted = array_values(array_filter($calls, static fn ($call) => 'php_execute' === ($call['name'] ?? null)));
        $linked = [] !== $programs && count($programs) <= 2 && count($programs) === count($submitted) && count($executions) === count($programs);
        $ledger = [];
        $ids = [];
        $cleanup = [] !== $executions;
        foreach ($executions as $index => $execution) {
            $linked = $linked && $index === ($execution['programIndex'] ?? null)
                && is_string($programs[$index] ?? null)
                && $programs[$index] === ($submitted[$index]['arguments']['code'] ?? null);
            array_push($ledger, ...($execution['toolCalls'] ?? []));
            $id = $execution['runId'] ?? null;
            $cleanup = $cleanup && is_string($id) && 1 === preg_match('/\Acode-mode-[a-f0-9]{32}\z/D', $id)
                && !in_array($id, $ids, true) && true === ($execution['containerRemoved'] ?? null);
            $ids[] = $id;
        }
        $linked = $linked && $ledger === ($result['brokerCalls'] ?? null)
            && 'succeeded' === ($executions[array_key_last($executions)]['status'] ?? null);
        $measuredBytes = 0;
        $validBytes = count($calls) === ($result['agentToolCalls'] ?? null);
        foreach ($calls as $call) {
            $validBytes = $validBytes && is_string($call['result'] ?? null) && strlen($call['result']) === ($call['bytesToModel'] ?? null);
            $measuredBytes += is_string($call['result'] ?? null) ? strlen($call['result']) : 0;
        }
        $validBytes = $validBytes && $measuredBytes > 0 && $measuredBytes === ($result['toolPayloadBytes'] ?? null);
        $checks = [
            'phpResultMatchesFixture' => true === $value['passed'],
            'finalAnswerMatchesFixture' => true === $final['passed'],
            'submittedPhpAndHostLedgerLinked' => $linked,
            'containersRemoved' => $cleanup,
            'payloadBytesRecounted' => $validBytes,
            'modelRequestsObserved' => ($result['modelRequests'] ?? 0) > 0 && ($result['modelRequests'] ?? 0) <= 8,
        ];
        return ['passed' => !in_array(false, $checks, true), 'checks' => $checks, 'message' => !in_array(false, $checks, true)
            ? 'PHP and final answer match all 48 articles and 10 findings; source, host calls, payload bytes and cleanup checked.'
            : 'One or more required execution observations did not pass. Inspect the retained attempt.'];
    }
}
