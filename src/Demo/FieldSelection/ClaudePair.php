<?php

declare(strict_types=1);

namespace App\Demo\FieldSelection;

use App\Demo\ClaudeAuditProof;
use App\Demo\ClaudeUsage;

/** Grade both native sessions, including runs where the model ignores the optional fields argument. */
final class ClaudePair
{
    public static function load(string $root, string $question): array
    {
        $pair = json_decode(file_get_contents($root.'/docs/evidence/claude-field-selection.json'), true, flags: \JSON_THROW_ON_ERROR);
        $attempts = $pair['attempts'];
        $matched = ['baseline', 'offered'] === array_column($attempts, 'arm')
            && ['catalog', 'catalog-fields'] === array_column($attempts, 'surface')
            && $question === $pair['protocol']['question'];
        foreach ($attempts as &$run) {
            $run['verification'] = ClaudeAuditProof::verify($run, $question);
            $run['usage'] = ClaudeUsage::summarize($run['usageRows']);
            $run['selectedReads'] = 0;
            foreach ($run['http'] as $exchange) {
                foreach ($exchange['request']['params']['arguments']['calls'] ?? [] as $call) {
                    if (isset($call['arguments']['fields'])) {
                        ++$run['selectedReads'];
                    }
                }
            }
            $matched = $matched && $run['verification']['checks']['sameSingleQuestion'] && $run['client']['nativeCodeMode'];
        }
        unset($run);
        [$baseline, $offered] = $attempts;
        foreach (['client', 'datasetSha256', 'limits', 'identity', 'runtime'] as $key) {
            $matched = $matched && $baseline[$key] === $offered[$key];
        }
        $sources = $offered['sourceSha256'];
        $decorator = 'packages/symfony-code-mode/src/Ai/SelectToolFields.php';
        $matched = $matched && isset($sources[$decorator]);
        unset($sources[$decorator]);
        $matched = $matched && $baseline['sourceSha256'] === $sources;
        $contracts = ClaudeAuditProof::contracts($offered);
        foreach ($contracts as &$contract) {
            $matched = $matched && isset($contract['inputSchema']['properties']['fields']);
            unset($contract['inputSchema']['properties']['fields']);
        }
        unset($contract);
        $matched = $matched && 3 === \count($contracts) && $contracts === ClaudeAuditProof::contracts($baseline);

        return ['matched' => $matched, 'protocol' => $pair['protocol'], 'attempts' => $attempts];
    }
}
