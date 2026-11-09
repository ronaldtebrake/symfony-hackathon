<?php

declare(strict_types=1);

// Offline: does not load .env.local, boot the app, execute PHP from evidence or contact providers.
require dirname(__DIR__, 2).'/vendor/autoload.php';
foreach (['Connection', 'Verifier', 'Conversation', 'ConversationVerifier', 'ClientCapture'] as $class) {
    require __DIR__.'/src/'.$class.'.php';
}
$directory = $argv[1] ?? throw new InvalidArgumentException('Provide a captured client directory.');
$report = (new SurfaceProof\ClientCapture())->read($directory);
file_put_contents($directory.'/reviewed-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", LOCK_EX);
foreach ($report['verification'] as $name => $passed) { echo ($passed ? 'PASS ' : 'FAIL ').$name."\n"; }
if ('complete' !== $report['status']) { exit(1); }
$text = "# Data Surface: a real agent conversation\n\n"
    .'Captured '.$report['client']['createdAt'].' using '.$report['client']['client'].' / '.$report['client']['model'].".\n\n"
    ."The two user prompts were scripted by the operator. The agent generated both answers and both PHP programs. One ephemeral client and one Code Mode MCP session handled both turns. No Drupal permissions or content changed.\n\n"
    ."The initial Symfony AI/Hugging Face attempts were rate-limited before generation. Their failed captures remain in local `var/conversation-*` directories. This successful capture uses Codex and is not a Symfony AI/Hugging Face execution or a token-savings comparison.\n\n";
foreach ($report['client']['turns'] as $i => $turn) {
    $answer = array_values(array_filter($turn['messages'], static fn ($m) => 'final_answer' === ($m['phase'] ?? null)))[0]['text'];
    $parsed = json_decode($answer, true, flags: JSON_THROW_ON_ERROR);
    $text .= '## Turn '.($i + 1)."\n\n**User:** ".$turn['question']."\n\n**Agent:** ".$parsed['message']."\n\n";
    if (is_string($parsed['alternative'] ?? null)) { $text .= $parsed['alternative']."\n\n"; }
    $text .= "Model-generated PHP:\n\n````php\n".$report['executions'][$i]['code']."\n````\n\n"
        ."Actual MCP-backed calls (observed on the host):\n\n````json\n".json_encode(array_slice($report['upstreamCalls'], 2 * $i, 2), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n````\n\n"
        ."Returned data:\n\n````json\n".json_encode($parsed['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n````\n\n";
}
$text .= "## Verification\n\nAll ".count($report['verification'])." checks passed. Both gVisor containers were removed. The reader's permissions, node data and query source/config hashes stayed unchanged.\n\n"
    ."The restricted searches succeeded with no visible matches. They did not return explicit permission-denied errors. Independent host snapshots prove that the draft is unpublished and the other fixture's title is denied; those snapshots were not given to the agent.\n\n"
    ."Client-reported cumulative usage, including client context and cached input:\n\n````json\n".json_encode($report['client']['tokenUsage']['total'] ?? null, JSON_PRETTY_PRINT)."\n````\n\n"
    ."Full verbatim answers, discovery events, submitted and executed source, actual tool calls and access snapshots are in `reviewed-report.json`. The prose was reviewed separately from the automated data checks.\n";
file_put_contents($directory.'/conversation.md', $text, LOCK_EX);
echo 'Reviewed capture and transcript: '.$directory."\n";
