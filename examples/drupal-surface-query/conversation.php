<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/src/ObservedProvider.php';
require __DIR__.'/src/Conversation.php';
require __DIR__.'/src/ConversationVerifier.php';

use SurfaceProof\Conversation;

umask(0077);
if (!is_dir(__DIR__.'/var')) { mkdir(__DIR__.'/var', 0700, true); }
// Share the reference proof's lock: no concurrent permission-revocation probe.
$lock = fopen(__DIR__.'/var/proof.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Another Data Surface capture is active.'); }
$directory = __DIR__.'/var/conversation-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
mkdir($directory, 0700);
$save = static function (array $report) use ($directory): void {
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    file_put_contents($directory.'/report.json', $json, LOCK_EX);
    file_put_contents(__DIR__.'/var/latest-conversation.json', $json, LOCK_EX);
    echo $report['status'].'; completed turns: '.count(array_filter($report['turns'], static fn ($t) => isset($t['answer'])))
        .'; model requests: '.($report['trace']['modelRequests'] ?? 0)."\n";
};
try {
    $report = (new Conversation())->run($directory, $save);
    $markdown = "# Data Surface — live agent conversation\n\n"
        .'Model: '.$report['model'].'. Provider: '.$report['provider'].'. Capture: '.$report['status'].".\n\n"
        ."Operator-authored prompts; model-generated PHP and answers. Separate from the Symfony workbench.\n\n";
    foreach ($report['turns'] as $i => $turn) {
        $markdown .= '## Turn '.($i + 1)."\n\n**User:** ".$turn['question']."\n\n**Agent (verbatim JSON):**\n\n````json\n".($turn['answer'] ?? '[No answer]')."\n````\n\n";
        foreach (array_slice($report['trace']['executions'], $turn['executionStart'], ($turn['executionEnd'] ?? $turn['executionStart']) - $turn['executionStart']) as $run) {
            $markdown .= "**Model-generated PHP:**\n\n````php\n".$run['php']."\n````\n\n"
                ."**Host-observed broker calls:**\n\n````json\n".json_encode($run['toolCalls'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n````\n\n"
                .'Runtime: '.$run['runtime']['executor'].'. Container removed: '.($run['containerRemoved'] ? 'yes' : 'no').".\n\n";
        }
    }
    $markdown .= "## Actual MCP-backed invocations\n\n````json\n".json_encode($report['trace']['upstreamCalls'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n````\n\n"
        ."## Provider usage\n\n".$report['trace']['modelRequests'].' requests; '.json_encode($report['trace']['providerTokens'])." tokens. No direct-tool comparison or savings claim.\n\n"
        ."Restricted queries returned no visible matches; these were successful filtered reads, not explicit permission errors.\n";
    file_put_contents($directory.'/conversation.md', $markdown, LOCK_EX);
    file_put_contents(__DIR__.'/var/latest-conversation.md', $markdown, LOCK_EX);
    foreach ($report['verification'] as $name => $passed) { echo ($passed ? 'PASS ' : 'FAIL ').$name."\n"; }
    echo 'Saved separately: '.$directory."\n";
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
exit('complete' === $report['status'] ? 0 : 1);
