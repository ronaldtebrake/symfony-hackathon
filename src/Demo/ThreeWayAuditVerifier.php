<?php

declare(strict_types=1);

namespace App\Demo;

final class ThreeWayAuditVerifier
{
    public static function verify(array $capture): array
    {
        $result = $capture['result'] ?? [];
        $approach = $capture['approach'] ?? '';
        $protocol = $capture['protocol'] ?? ThreeWayAuditPrompt::ORIGINAL;
        $validProtocol = \in_array($protocol, [ThreeWayAuditPrompt::ORIGINAL, ThreeWayAuditPrompt::CURRENT, ThreeWayAuditPrompt::CATALOG, ThreeWayAuditPrompt::TEXT_REVIEW, ThreeWayAuditPrompt::EXPLICIT_REVIEW, ThreeWayAuditPrompt::UNICODE_REVIEW, ThreeWayAuditPrompt::UTF8_RESULTS, ThreeWayAuditPrompt::PLAIN_REVIEW, ThreeWayAuditPrompt::PRESERVING_REVIEW, ThreeWayAuditPrompt::DECISION_REVIEW], true);
        $withReview = $validProtocol && ThreeWayAuditPrompt::ORIGINAL !== $protocol;
        $ledger = $result['brokerCalls'] ?? [];
        $agentCalls = $result['agentCalls'] ?? [];
        $dataset = new AuditDataset();
        $articles = $media = [];
        $upstream = $result['upstream'] ?? [];
        $allowed = true;
        foreach ($upstream as $event) {
            $allowed = $allowed && \in_array($event['name'] ?? null, BackendFactory::FIXTURE_TOOLS, true) && 'demo-editor' === ($event['subject'] ?? null);
            if ('succeeded' !== ($event['status'] ?? null)) {
                continue;
            }
            foreach ($event['value']['items'] ?? [] as $item) {
                if ('cms_get_articles' === $event['name']) {
                    $articles[$item['id']] = $item;
                }
                if ('cms_get_media' === $event['name']) {
                    $media[$item['id']] = $item;
                }
            }
        }
        ksort($articles);
        ksort($media);
        $expectedArticles = array_filter($dataset->articles(), static fn ($row) => 'published' === $row['status']);
        $responses = $result['providerResponses'] ?? [];
        $known = ['prompt' => 0, 'completion' => 0, 'total' => 0];
        $reported = 0;
        $usageValid = [] !== $responses && \count($responses) === ($result['modelRequests'] ?? null);
        foreach ($responses as $index => $response) {
            $tokens = $response['tokens'] ?? [];
            $valid = $index + 1 === ($response['request'] ?? null) && \is_int($tokens['prompt'] ?? null) && $tokens['prompt'] >= 0
                && \is_int($tokens['completion'] ?? null) && $tokens['completion'] >= 0 && \is_int($tokens['total'] ?? null)
                && $tokens['prompt'] + $tokens['completion'] === $tokens['total'];
            $usageValid = $usageValid && $valid;
            if ($valid) {
                ++$reported;
                foreach ($known as $key => &$value) {
                    $value += $tokens[$key];
                } unset($value);
            }
        }
        $usage = $known;
        $requests = $result['requestEvidence'] ?? [];
        $expectedMessages = array_map(static fn ($call) => ['bytes' => \strlen($call['result']), 'sha256' => hash('sha256', $call['result'])], $agentCalls);
        $history = \count($requests) === ($result['modelRequests'] ?? null) && [] !== $requests && [] !== $expectedMessages
            && $expectedMessages === ($requests[array_key_last($requests)]['toolMessages'] ?? null);
        foreach ($requests as $request) {
            $system = $capture['systemPrompt'] ?? '';
            $users = [hash('sha256', AuditDataset::TASK)];
            if ($withReview && ($capture['review']['modelRequest'] ?? null) === ($request['request'] ?? null)) {
                $system = ThreeWayAuditPrompt::reviewSystem($protocol, $system);
                if (ThreeWayAuditPrompt::dedicatedReview($protocol)) {
                    $users[] = hash('sha256', ThreeWayAuditPrompt::reviewInstruction($protocol));
                }
            }
            $history = $history && hash('sha256', $system) === ($request['systemPromptSha256'] ?? null)
                && $users === ($request['userMessageSha256'] ?? null);
        }
        $bytes = 0;
        $validBytes = \count($agentCalls) === ($result['agentToolCalls'] ?? null);
        foreach ($agentCalls as $call) {
            $bytes += \strlen($call['result'] ?? '');
            $validBytes = $validBytes && \strlen($call['result'] ?? '') === ($call['bytesToModel'] ?? null);
        }
        $boundAnswer = $result['answer'] ?? '';
        $decisionValid = true;
        if (ThreeWayAuditPrompt::DECISION_REVIEW === $protocol) {
            $boundAnswer = $capture['review']['response'] ?? '';
            try {
                $decision = ThreeWayAuditReview::apply($boundAnswer, $capture['draft']['answer'] ?? '');
                $decisionValid = $decision['decision'] === ($capture['review']['decision'] ?? null) && $decision['answer'] === ($result['answer'] ?? null);
            } catch (\Throwable) {
                $decisionValid = false;
            }
        }
        $checks = [
            'noCaptureError' => !\array_key_exists('error', $capture),
            'sameAuditContract' => ThreeWayAuditRunner::PROTOCOL === ($capture['kind'] ?? null) && ThreeWayAuditRunner::MODEL === ($capture['model'] ?? null)
                && AuditDataset::TASK === ($capture['task'] ?? null) && \in_array($approach, ['direct', 'batch', 'code'], true),
            'fixedSettingsAndIdentity' => (AuditPrompt::options(AuditPrompt::COVERAGE_PROTOCOL) + ['providerRouting' => 'auto']) === ($capture['generationSettings'] ?? null)
                && ['subject' => 'demo-editor', 'capabilities' => ['content.audit']] === ($capture['identity'] ?? null),
            'promptRecorded' => $validProtocol && \in_array($approach, ['direct', 'batch', 'code'], true)
                && ThreeWayAuditPrompt::build($approach, $protocol) === ($capture['systemPrompt'] ?? null)
                && hash('sha256', ThreeWayAuditPrompt::shared($protocol)) === ($capture['sharedPromptSha256'] ?? null),
            'fixtureRecorded' => hash('sha256', json_encode([$dataset->articles(), $dataset->media()], \JSON_THROW_ON_ERROR)) === ($capture['datasetSha256'] ?? null),
            'answerCorrect' => (new AuditVerifier())->verify(json_decode($result['answer'] ?? '', true), 'fixture', $ledger)['passed'],
            'completeActualReads' => $allowed && $articles === $expectedArticles && $media === $dataset->media(),
            'callsWithinBudget' => [] !== $ledger && \count($ledger) <= 60 && !array_diff(array_column($ledger, 'name'), BackendFactory::FIXTURE_TOOLS),
            'modelBudget' => ($result['modelRequests'] ?? 0) > 0 && $result['modelRequests'] <= 14,
            'payloadRecounted' => $validBytes && $bytes > 0 && $bytes === ($result['toolPayloadBytes'] ?? null),
            'historyRetained' => $history,
            'finalAnswerBoundToProvider' => '' !== ($result['answer'] ?? '') && $decisionValid
                && hash('sha256', $boundAnswer) === ($responses[array_key_last($responses)]['answerSha256'] ?? null),
            'usageReconciled' => $usageValid && $usage === ($result['providerTokens'] ?? null) && 'stop' === ($responses[array_key_last($responses)]['finishReason'] ?? null),
        ];
        if ($withReview) {
            $draft = $capture['draft'] ?? [];
            $review = $capture['review'] ?? [];
            $draftRequest = $draft['modelRequest'] ?? 0;
            $lastRequest = $requests[array_key_last($requests)] ?? [];
            $draftTokens = [];
            foreach (['prompt', 'completion', 'total'] as $key) {
                $draftTokens[$key] = array_sum(array_map(static fn ($r) => $r['tokens'][$key] ?? 0, \array_slice($responses, 0, $draftRequest)));
            }
            $checks['sharedReviewWithoutOracle'] = 'complete' === ($review['status'] ?? null)
                && ThreeWayAuditPrompt::reviewInstruction($protocol) === ($review['instruction'] ?? null)
                && false === ($review['toolsAvailable'] ?? null) && false === ($review['oracleFeedback'] ?? null) && true === ($review['costIncluded'] ?? null)
                && $draftRequest > 0 && $draftRequest <= 13 && $draftRequest + 1 === ($review['modelRequest'] ?? null)
                && ($review['modelRequest'] ?? null) === ($result['modelRequests'] ?? null)
                && false === ($lastRequest['toolsAvailable'] ?? null)
                && \count($agentCalls) === ($review['agentCallStart'] ?? null)
                && hash('sha256', $draft['answer'] ?? '') === ($responses[$draftRequest - 1]['answerSha256'] ?? null)
                && \in_array(hash('sha256', $draft['answer'] ?? ''), $lastRequest['assistantMessageSha256'] ?? [], true)
                && ($review['answer'] ?? null) === ($result['answer'] ?? null)
                && $draftTokens === ($draft['providerTokens'] ?? null)
                && ($review['providerTokens'] ?? null) === ($responses[array_key_last($responses)]['tokens'] ?? null);
            if (ThreeWayAuditPrompt::dedicatedReview($protocol)) {
                $checks['textOnlyReview'] = true === ($review['explicitUserTurn'] ?? null)
                    && ThreeWayAuditPrompt::reviewSystem($protocol, $capture['systemPrompt'] ?? '') === ($review['systemPrompt'] ?? null)
                    && ThreeWayAuditPrompt::reviewOptions($protocol) === ($review['generationSettings'] ?? null)
                    && 'none' === ($lastRequest['tool_choice'] ?? null)
                    && (\in_array($protocol, [ThreeWayAuditPrompt::PLAIN_REVIEW, ThreeWayAuditPrompt::PRESERVING_REVIEW, ThreeWayAuditPrompt::DECISION_REVIEW], true) ? !\array_key_exists('response_format', $lastRequest) : ['type' => 'json_object'] === ($lastRequest['response_format'] ?? null));
                if (ThreeWayAuditPrompt::explicitEmptyTools($protocol)) {
                    $checks['textOnlyReview'] = $checks['textOnlyReview'] && true === ($lastRequest['toolsDeclared'] ?? null);
                }
            }
        }
        if (ThreeWayAuditPrompt::literalToolResults($protocol)) {
            $literal = 'literal-utf8' === ($capture['toolResultEncoding'] ?? null);
            foreach ($agentCalls as $call) {
                try {
                    $literal = $literal && $call['result'] === json_encode(json_decode($call['result'], false, 64, \JSON_THROW_ON_ERROR), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE, 64);
                } catch (\JsonException) {
                    $literal = false;
                }
            }
            $checks['literalUnicodeToolResults'] = $literal;
        }
        if ('code' === $approach) {
            $checks['phpEvidence'] = (new HuggingFaceProofVerifier())->verify($result, 14)['passed'];
        } elseif ('direct' === $approach) {
            $checks['directEvidence'] = (new HuggingFaceBaselineVerifier())->verify($capture, 14)['passed'];
        } elseif ('batch' === $approach) {
            $executeName = ThreeWayAuditPrompt::usesCatalog($protocol) ? 'execute_tools' : 'tools_execute';
            $batchCalls = array_values(array_filter($agentCalls, static fn ($call) => $executeName === ($call['name'] ?? null)));
            $executions = $result['executions'] ?? [];
            $linked = [] !== $batchCalls && \count($batchCalls) === \count($executions) && \count($batchCalls) <= 14 && [] === ($result['programs'] ?? null);
            $batchLedger = [];
            $observedSuccesses = array_values(array_filter($upstream, static fn ($event) => 'succeeded' === ($event['status'] ?? null)));
            $observedIndex = 0;
            foreach ($executions as $index => $execution) {
                $call = $batchCalls[$index] ?? [];
                $linked = $linked && ($call['arguments']['calls'] ?? null) === ($execution['submittedCalls'] ?? null)
                    && 'json-batch' === ($execution['runtime']['executor'] ?? null) && false === ($execution['runtime']['generatedCode'] ?? null);
                array_push($batchLedger, ...($execution['toolCalls'] ?? []));
                foreach ($execution['toolCalls'] ?? [] as $callIndex => $hostCall) {
                    if ('succeeded' !== ($hostCall['status'] ?? null)) {
                        continue;
                    }
                    $observed = $observedSuccesses[$observedIndex++] ?? [];
                    $submitted = $execution['submittedCalls'][$callIndex] ?? [];
                    $linked = $linked && ($observed['name'] ?? null) === ($submitted['name'] ?? null)
                        && ($observed['arguments'] ?? null) === ($submitted['arguments'] ?? null);
                    if ('succeeded' === ($execution['status'] ?? null)) {
                        $linked = $linked && ($execution['results'][$callIndex]['value'] ?? null) === ($observed['value'] ?? null);
                    }
                }
                if ('succeeded' === ($execution['status'] ?? null)) {
                    $linked = $linked && ($execution['results'] ?? null) === (json_decode($call['result'] ?? '', true)['results'] ?? null)
                        && \count($execution['results'] ?? []) === \count($execution['submittedCalls'] ?? []);
                }
            }
            $checks['batchEvidence'] = $linked && $observedIndex === \count($observedSuccesses) && $batchLedger === $ledger;
            if (ThreeWayAuditPrompt::usesCatalog($protocol)) {
                $disclosed = [];
                $contracts = true;
                $approved = array_column($capture['approvedTools'] ?? [], null, 'name');
                foreach ($agentCalls as $call) {
                    if ('search_tools' === ($call['name'] ?? null)) {
                        foreach (json_decode($call['result'], true)['tools'] ?? [] as $definition) {
                            $contracts = $contracts && ($approved[$definition['name']] ?? null) === $definition;
                            $disclosed[$definition['name']] = true;
                        }
                    } elseif ('execute_tools' === ($call['name'] ?? null)) {
                        foreach ($call['arguments']['calls'] ?? [] as $submitted) {
                            if (!isset($disclosed[$submitted['name'] ?? ''])) {
                                $contracts = $contracts && isset(json_decode($call['result'], true)['error']);
                            }
                        }
                    } else {
                        $contracts = false;
                    }
                }
                $checks['stableTwoToolCatalog'] = $contracts && [] !== $disclosed && ['search_tools', 'execute_tools'] === ($capture['entryTools'] ?? null);
            }
        }

        return ['passed' => !\in_array(false, $checks, true), 'checks' => $checks, 'knownProviderTokens' => $known,
            'unreportedRequests' => max(0, ($result['modelRequests'] ?? 0) - $reported)];
    }

    public static function compare(array $attempt): array
    {
        $runs = $attempt['runs'] ?? [];
        $checks = [];
        foreach (['direct', 'batch', 'code'] as $mode) {
            $checks[$mode.'Verified'] = isset($runs[$mode]) && self::verify($runs[$mode])['passed'];
        }
        $protocols = array_map(static fn ($mode) => $runs[$mode]['protocol'] ?? ThreeWayAuditPrompt::ORIGINAL, ['direct', 'batch', 'code']);
        $checks['protocolMatched'] = 1 === \count(array_unique($protocols));
        if (array_filter($protocols, ThreeWayAuditPrompt::literalToolResults(...))) {
            $checks['toolResultEncodingMatched'] = array_map(static fn ($mode) => $runs[$mode]['toolResultEncoding'] ?? null, ['direct', 'batch', 'code']) === ['literal-utf8', 'literal-utf8', 'literal-utf8'];
        }
        foreach (['model', 'task', 'endpoint', 'generationSettings', 'sharedPromptSha256', 'identity', 'datasetSha256', 'approvedTools', 'components'] as $key) {
            $checks[$key.'Matched'] = isset($runs['direct'][$key]) && $runs['direct'][$key] === ($runs['batch'][$key] ?? null) && $runs['direct'][$key] === ($runs['code'][$key] ?? null);
        }
        foreach (['maxModelRequests' => 14, 'maxUnderlyingCalls' => 60, 'toolTimeMs' => 3000, 'brokerMemoryMiB' => 64, 'maxToolResultBytes' => 16384, 'maxFrameBytes' => 16384, 'maxAggregateOutputBytes' => 65536] as $key => $expected) {
            $checks[$key.'Matched'] = array_map(static fn ($mode) => $runs[$mode]['limits'][$key] ?? null, ['direct', 'batch', 'code']) === [$expected, $expected, $expected];
        }

        return ['passed' => !\in_array(false, $checks, true), 'checks' => $checks];
    }
}
