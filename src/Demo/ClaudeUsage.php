<?php

declare(strict_types=1);

namespace App\Demo;

/** Main-conversation API usage from Claude's transcript, not a tokenizer estimate or a bill. */
final class ClaudeUsage
{
    public const FIELDS = ['input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'output_tokens'];

    public static function summarize(array $rows): array
    {
        $responses = [];
        $complete = [] !== $rows;
        $duplicates = 0;
        foreach ($rows as $row) {
            if ('<synthetic>' === ($row['model'] ?? null)) {
                continue;
            }
            $id = $row['messageId'] ?? '';
            $valid = '' !== $id && '' !== ($row['requestId'] ?? '') && isset($row['turn'], $row['model']);
            foreach (self::FIELDS as $field) {
                $valid = $valid && \is_int($row['usage'][$field] ?? null) && $row['usage'][$field] >= 0;
            }
            $complete = $complete && $valid;
            if (!$valid) {
                continue;
            }
            if (isset($responses[$id])) {
                ++$duplicates;
                $previous = $responses[$id];
                // One API response can be logged once per content block. Its final counters win.
                foreach (['requestId', 'model', 'turn'] as $key) {
                    $complete = $complete && $previous[$key] === $row[$key];
                }
                foreach (self::FIELDS as $field) {
                    $complete = $complete && $row['usage'][$field] >= $previous['usage'][$field];
                }
            }
            $responses[$id] = $row;
        }
        $totals = array_fill_keys(self::FIELDS, 0);
        foreach ($responses as $row) {
            foreach (self::FIELDS as $field) {
                $totals[$field] += $row['usage'][$field];
            }
        }
        $input = $totals['input_tokens'] + $totals['cache_creation_input_tokens'] + $totals['cache_read_input_tokens'];
        $complete = $complete && [] !== $responses && \count($responses) === \count(array_unique(array_column($responses, 'requestId')));

        return ['complete' => $complete, 'requests' => \count($responses), 'duplicateRowsExcluded' => $duplicates,
            'uncachedInput' => $totals['input_tokens'], 'cacheWrite' => $totals['cache_creation_input_tokens'],
            'cacheRead' => $totals['cache_read_input_tokens'], 'input' => $input, 'output' => $totals['output_tokens'],
            'total' => $input + $totals['output_tokens'], 'responses' => array_values($responses)];
    }
}
