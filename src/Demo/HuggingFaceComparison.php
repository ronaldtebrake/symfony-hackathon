<?php

declare(strict_types=1);

namespace App\Demo;

/** Pair an observed direct invocation with the exact reviewed Code Mode capture it references. */
final class HuggingFaceComparison
{
    public static function encodeCapture(array $capture): string
    {
        return json_encode(json_decode(json_encode($capture, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR), \JSON_THROW_ON_ERROR);
    }

    public function build(string $codeJson, array $direct): array
    {
        $code = json_decode($codeJson, true, flags: \JSON_THROW_ON_ERROR);
        $descriptions = null;
        foreach ($code['result']['agentCalls'] ?? [] as $call) {
            if ('tools_describe' === $call['name']) {
                $descriptions = json_decode($call['result'], true);
            }
        }
        $dataset = new AuditDataset();
        $protocol = $direct['protocol'] ?? 'legacy';
        $matchedProtocol = 'legacy' !== $protocol;
        if (\in_array($protocol, ['coverage-v10', AuditPrompt::COVERAGE_PROTOCOL], true)) {
            // Compact discovery omits output schemas on the wire; compare full host contracts separately.
            $descriptions = $code['approvedTools'] ?? null;
        }
        $codeLimit = AuditPrompt::maxRequests('code', $protocol);
        $directLimit = AuditPrompt::maxRequests('direct', $protocol);
        $checks = [
            'codeCaptureBound' => hash('sha256', $codeJson) === ($direct['codeCaptureSha256'] ?? null),
            'sameModel' => ($code['model'] ?? null) === ($direct['model'] ?? null) && \is_string($code['model'] ?? null),
            'sameQuestion' => AuditDataset::TASK === ($code['task'] ?? null) && $code['task'] === ($direct['task'] ?? null),
            'sameEndpoint' => ($code['endpoint'] ?? null) === ($direct['endpoint'] ?? null),
            'sameComponents' => ($code['components'] ?? null) === ($direct['components'] ?? null),
            'baselineGenerationSettings' => (AuditPrompt::options($protocol) + ['providerRouting' => 'auto']) === ($direct['generationSettings'] ?? null),
            'approvedReadIdentity' => ['subject' => 'demo-editor', 'capabilities' => ['content.audit']] === ($direct['identity'] ?? null),
            'sameToolSchemas' => \is_array($descriptions) && $this->definitions($descriptions) === $this->definitions(json_decode(json_encode($direct['approvedTools'] ?? []), true)),
            'sameFixture' => 'Symfony MCP fixture' === ($code['backend'] ?? null) && $code['backend'] === ($direct['backend'] ?? null)
                && hash('sha256', json_encode([$dataset->articles(), $dataset->media()], \JSON_THROW_ON_ERROR)) === ($direct['datasetSha256'] ?? null),
            'codeResultVerified' => 'complete' === ($code['status'] ?? null) && (new HuggingFaceProofVerifier())->verify($code['result'] ?? [], $codeLimit)['passed'],
            'directResultVerified' => 'complete' === ($direct['status'] ?? null) && (new HuggingFaceBaselineVerifier())->verify($direct, $directLimit)['passed'],
            'providerUsageReported' => $this->validUsage($code) && $this->validUsage($direct),
        ];
        if (\in_array($protocol, ['coverage-v10', AuditPrompt::COVERAGE_PROTOCOL], true)) {
            $checks['coverage-v10' === $protocol ? 'compactContractsDisclosed' : 'fullContractsDisclosed'] = $this->compactContractsDisclosed($code, allowCompact: 'coverage-v10' === $protocol);
        }
        if ($matchedProtocol) {
            $checks += [
                'sameProtocol' => $protocol === ($code['protocol'] ?? null),
                'sameSharedInstructions' => hash('sha256', AuditPrompt::shared($protocol)) === ($code['sharedPromptSha256'] ?? null) && $code['sharedPromptSha256'] === ($direct['sharedPromptSha256'] ?? null),
                'recordedPromptsMatchProtocol' => AuditPrompt::build('code', DemoQuestion::preset(), $protocol) === ($code['systemPrompt'] ?? null)
                    && AuditPrompt::build('direct', DemoQuestion::preset(), $protocol) === ($direct['systemPrompt'] ?? null),
                'sameGenerationSettings' => ($code['generationSettings'] ?? null) === ($direct['generationSettings'] ?? null),
                'sameIdentity' => ($code['identity'] ?? null) === ($direct['identity'] ?? null),
                'sameDatasetDigest' => ($code['datasetSha256'] ?? null) === ($direct['datasetSha256'] ?? null),
                'equalRequestCeilings' => $codeLimit === ($code['limits']['maxModelRequests'] ?? null) && $directLimit === ($direct['limits']['maxModelRequests'] ?? null),
                'allToolMessagesRetained' => $this->messagesRetained($code) && $this->messagesRetained($direct),
                'perRequestUsageReconciled' => $this->requestUsage($code) && $this->requestUsage($direct),
            ];
            if ('coverage-v7' === $protocol) {
                $checks['sameUnconditionalReview'] = AuditPrompt::review() === ($code['result']['review']['instruction'] ?? null)
                    && AuditPrompt::review() === ($direct['result']['review']['instruction'] ?? null);
            }
        }
        $complete = !\in_array(false, $checks, true);

        return ['kind' => 'huggingface-comparison', 'status' => $complete ? 'complete' : (\in_array($direct['status'] ?? 'empty', ['running', 'empty'], true) ? ($direct['status'] ?? 'empty') : 'failed'),
            'verifiedComparison' => $complete, 'protocol' => $protocol, 'checks' => $checks, 'code' => $code, 'direct' => $direct,
            'observedTotals' => ['code' => $this->totals($code), 'direct' => $this->totals($direct)],
            'difference' => $complete ? [
                'tokens' => $direct['result']['providerTokens']['total'] - $code['result']['providerTokens']['total'],
                'modelRequests' => $direct['result']['modelRequests'] - $code['result']['modelRequests'],
                'tokenReductionPercent' => 100 * (1 - $code['result']['providerTokens']['total'] / $direct['result']['providerTokens']['total']),
            ] : null];
    }

    private function compactContractsDisclosed(array $capture, bool $allowCompact = true): bool
    {
        $approved = array_column($capture['approvedTools'] ?? [], null, 'name');
        $disclosed = [];
        foreach ($capture['result']['agentCalls'] ?? [] as $call) {
            if (!\in_array($call['name'], $allowCompact ? ['tools_search', 'tools_describe'] : ['tools_describe'], true)) {
                continue;
            }
            $result = json_decode($call['result'], true, flags: \JSON_THROW_ON_ERROR);
            foreach ($result['tools'] ?? $result as $tool) {
                if (!isset($tool['inputSchema'])) {
                    continue;
                }
                $name = $tool['name'];
                if (!isset($approved[$name]) || $approved[$name]['inputSchema'] !== $tool['inputSchema']) {
                    return false;
                }
                if (!$allowCompact && ($approved[$name]['outputSchema'] ?? null) !== ($tool['outputSchema'] ?? null)) {
                    return false;
                }
                if ('tools_describe' === $call['name'] || false === ($tool['requiresDescribe'] ?? null)) {
                    $disclosed[$name] = true;
                }
            }
        }

        return [] !== $approved && [] === array_diff(array_keys($approved), array_keys($disclosed));
    }

    private function definitions(array $definitions): array
    {
        usort($definitions, static fn ($a, $b) => $a['name'] <=> $b['name']);

        return $definitions;
    }

    private function messagesRetained(array $capture): bool
    {
        $result = $capture['result'] ?? [];
        $requests = $result['requestEvidence'] ?? [];
        $expected = array_map(static fn ($call) => ['bytes' => \strlen($call['result']), 'sha256' => hash('sha256', $call['result'])], $result['agentCalls'] ?? []);

        return [] !== $expected && \count($requests) === ($result['modelRequests'] ?? null)
            && $expected === ($requests[array_key_last($requests)]['toolMessages'] ?? null);
    }

    private function requestUsage(array $capture): bool
    {
        $result = $capture['result'] ?? [];
        $responses = $result['providerResponses'] ?? [];
        $total = ['prompt' => 0, 'completion' => 0, 'total' => 0];
        if ([] === $responses || \count($responses) !== ($result['modelRequests'] ?? null)) {
            return false;
        }
        foreach ($responses as $index => $response) {
            if ($index + 1 !== $response['request'] || !$this->validUsage(['result' => ['providerTokens' => $response['tokens']]])) {
                return false;
            }
            foreach ($total as $key => &$value) {
                $value += $response['tokens'][$key];
            }
            unset($value);
        }

        return 'stop' === ($responses[array_key_last($responses)]['finishReason'] ?? null) && $total === ($result['providerTokens'] ?? null);
    }

    private function validUsage(array $capture): bool
    {
        $usage = $capture['result']['providerTokens'] ?? [];

        return \is_int($usage['prompt'] ?? null) && $usage['prompt'] >= 0 && \is_int($usage['completion'] ?? null) && $usage['completion'] >= 0
            && \is_int($usage['total'] ?? null) && $usage['total'] > 0 && $usage['prompt'] + $usage['completion'] === $usage['total'];
    }

    private function totals(array $capture): array
    {
        $attempts = [...($capture['previousAttempts'] ?? []), $capture];
        $tokens = 0;
        $requests = 0;
        $unreported = 0;
        foreach ($attempts as $attempt) {
            $requests += $attempt['result']['modelRequests'] ?? 0;
            if ($this->validUsage($attempt)) {
                $tokens += $attempt['result']['providerTokens']['total'];
            } else {
                ++$unreported;
            }
        }

        return ['attempts' => \count($attempts), 'modelRequests' => $requests, 'providerTokens' => 0 === $unreported ? $tokens : null, 'knownProviderTokens' => $tokens, 'unreportedTokenAttempts' => $unreported];
    }
}
