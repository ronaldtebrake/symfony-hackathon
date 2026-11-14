<?php

declare(strict_types=1);

namespace App\Demo\Performance;

/** Standalone payload counts, deliberately separate from provider-reported usage. */
final class TokenBreakdown
{
    public function samples(array $report): array
    {
        $samples = [];
        $add = static function (string $category, string $source, string $text) use (&$samples): void {
            $samples[] = ['category' => $category, 'source' => $source, 'text' => $text, 'bytes' => \strlen($text), 'sha256' => hash('sha256', $text)];
        };
        $add('Tool interface', 'entryTools', json_encode($report['entryTools'] ?? [], \JSON_THROW_ON_ERROR));
        foreach ($report['trace']['agentCalls'] ?? [] as $i => $call) {
            $category = match ($call['name']) {
                'php_execute' => 'Returned evidence', 'tools_search', 'tools_describe' => 'Discovery', default => 'Other tool responses',
            };
            $add($category, 'trace.agentCalls.'.$i.'.result', $call['result']);
        }
        foreach ($report['trace']['programs'] ?? [] as $i => $php) {
            $add('Generated PHP', 'trace.programs.'.$i, $php);
        }

        return $samples;
    }

    public function summarize(array $report): array
    {
        $saved = $report['tokenBreakdown'] ?? null;
        if (null === $saved) {
            return ['status' => 'missing', 'groups' => []];
        }
        $valid = 'o200k_harmony' === ($saved['encoding'] ?? null) && 'tiktoken==0.12.0' === ($saved['library'] ?? null)
            && 'openai/gpt-oss-120b' === ($report['model'] ?? null);
        $samples = $this->samples($report);
        $groups = [];
        $valid = $valid && \count($samples) === \count($saved['samples'] ?? []);
        foreach ($samples as $i => $sample) {
            $measured = $saved['samples'][$i] ?? [];
            foreach (['category', 'source', 'bytes', 'sha256'] as $key) {
                $valid = $valid && $sample[$key] === ($measured[$key] ?? null);
            }
            $tokens = $measured['tokens'] ?? null;
            $valid = $valid && \is_int($tokens) && $tokens >= 0;
            $name = $sample['category'];
            $groups[$name] ??= ['category' => $name, 'items' => 0, 'bytes' => 0, 'tokens' => 0];
            ++$groups[$name]['items'];
            $groups[$name]['bytes'] += $sample['bytes'];
            $groups[$name]['tokens'] += \is_int($tokens) ? $tokens : 0;
        }
        $ordered = [];
        foreach (['Tool interface', 'Discovery', 'Generated PHP', 'Returned evidence', 'Other tool responses'] as $name) {
            if (isset($groups[$name])) {
                $ordered[] = $groups[$name];
            }
        }
        $replayed = [];
        $byHash = [];
        foreach ($saved['samples'] ?? [] as $sample) {
            if (str_ends_with($sample['source'], '.result')) {
                $byHash[$sample['sha256']] = $sample;
            }
        }
        foreach ($report['trace']['requestEvidence'] ?? [] as $request) {
            foreach ($request['toolMessages'] ?? [] as $message) {
                $sample = $byHash[$message['sha256']] ?? null;
                if (null === $sample || $message['bytes'] !== $sample['bytes']) {
                    $valid = false;
                    continue;
                }
                $category = $sample['category'];
                $replayed[$category] ??= ['category' => $category, 'occurrences' => 0, 'tokens' => 0];
                ++$replayed[$category]['occurrences'];
                $replayed[$category]['tokens'] += $sample['tokens'];
            }
        }

        return ['status' => $valid ? 'verified' : 'stale', 'encoding' => $saved['encoding'] ?? null, 'groups' => $ordered,
            'replayedResults' => array_values($replayed), 'replayedScope' => 'Exact tool-result text counted once per observed occurrence in model input, matched by SHA-256 and byte length. Excludes chat framing, prompts, tool definitions, PHP and other assistant messages. These local counts are not an allocation of provider-billed tokens.'];
    }
}
