<?php

declare(strict_types=1);

use App\Demo\BackendFactory;
use App\Demo\HintEvaluation\Study;
use App\Demo\HttpConversation\Runner;
use App\Demo\ThreeWayAuditRunner;
use Symfony\Component\Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$args = array_slice($argv, 1);
$provider = Study::PROVIDER;
if (2 === count($args) && str_starts_with($args[1], '--provider=')) {
    $provider = substr(array_pop($args), strlen('--provider='));
}
if (['--live'] !== $args || !in_array($provider, Study::PROVIDERS, true)) {
    fwrite(\STDERR, "Usage: php scripts/compare-permission-hints.php --live [--provider=groq|--provider=deepinfra]\nFour fresh fixtures, three questions per arm, at most 24 Hugging Face requests on one fixed route.\n");
    exit(2);
}
(new Dotenv())->bootEnv($root.'/.env');
$key = BackendFactory::env('HUGGINGFACE_API_KEY');
if ('' === trim($key)) {
    fwrite(\STDERR, "Configure the Hugging Face Inference Providers key in the ignored .env.local.\n");
    exit(2);
}
umask(0077);
$directory = $root.'/var/demo/permission-hints';
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Cannot create evidence directory.');
}
$file = $directory.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
$report = ['format' => 'symfony-permission-hint-eval/v1', 'mode' => 'live', 'startedAt' => gmdate(\DATE_ATOM), 'status' => 'running',
    'model' => ThreeWayAuditRunner::MODEL, 'platform' => 'Symfony AI / Hugging Face Inference Providers', 'pinnedRoute' => $provider, 'settings' => Runner::SETTINGS,
    'questions' => Study::QUESTIONS, 'commonSystemPrompt' => Study::PROMPT, 'hint' => Study::HINT, 'cellOrder' => Study::ORDER,
    'maxRequestsPerTurn' => Study::REQUEST_LIMIT, 'expected' => ['direct' => Study::TITLE, 'indirect' => Study::TITLE, 'revoked' => 'One previously discovered read denied with no result, then a model-initiated search returning no visible contract.'],
    'protocol' => 'Each cell starts with a disposable Symfony app, the same reader, synthetic article and two-call execution budget. The direct question and revoked follow-up share the same connection and message history. Only the system message is rebuilt from current hints. The indirect question starts a fresh conversation. Both arms fetch the same voter-protected bootstrap once per turn. Only the hint arm injects it. One sample per question per arm.',
    'modelToolBridge' => 'symfony/ai-mcp-tool', 'sourceSha256' => [], 'cells' => []];
foreach (['composer.lock', 'scripts/compare-permission-hints.php', 'src/Demo/BudgetedPlatform.php', 'src/Demo/HintEvaluation/Study.php', 'src/Demo/HintEvaluation/Fixture.php', 'src/Demo/HttpConversation/Application.php',
    'examples/symfony/config/packages/capability_hint.php', 'examples/symfony/config/routes/capability_hint.php', 'examples/symfony/config/routes.php', 'examples/symfony/config/services.php',
    'examples/symfony/src/CapabilityHintController.php', 'examples/symfony/src/ArticleVoter.php', 'examples/symfony/src/ArticleTool.php', 'examples/symfony/src/ArticleCatalog.php', 'examples/symfony/composer.lock'] as $path) {
    $report['sourceSha256'][$path] = hash_file('sha256', $root.'/'.$path);
}
$save = static function () use (&$report, $file, $key): void {
    $bytes = json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
    if (str_contains($bytes, $key) || str_contains($bytes, 'PRIVATE_FIXTURE_DO_NOT_RETURN') || strlen($bytes) > 2 * 1048576) {
        throw new RuntimeException('Refusing to retain an unsafe evaluation report.');
    }
    if (file_put_contents($file.'.tmp', $bytes, \LOCK_EX) !== strlen($bytes) || !rename($file.'.tmp', $file)) {
        throw new RuntimeException('Cannot retain the evaluation.');
    }
};
$save();
echo 'Capture: '.substr($file, strlen($root) + 1)."\n";
$study = new Study($provider);
foreach (Study::ORDER as $index => [$arm, $scenario]) {
    $cell = $study->runCell($root, $key, $arm, $scenario, static function (array $cell) use (&$report, $index, $save): void {
        $report['cells'][$index] = $cell;
        $save();
    });
    echo $arm.' / '.$scenario.' — '.$cell['status']."\n";
}
$report['summary'] = [];
foreach (['search', 'hint'] as $arm) {
    $turns = [];
    foreach ($report['cells'] as $cell) {
        if ($cell['arm'] === $arm) {
            array_push($turns, ...array_values($cell['turns']));
        }
    }
    $complete = 3 === count($turns) && [] === array_filter($turns, static fn ($t) => 'completed' !== $t['status'] || null === ($t['tokens'] ?? null));
    $report['summary'][$arm] = ['plannedQuestions' => 3, 'attemptedQuestions' => count($turns),
        'correct' => count(array_filter($turns, static fn ($t) => $t['score']['correct'] ?? false)),
        'providerUsageComplete' => $complete, 'modelRequests' => array_sum(array_column($turns, 'modelRequests')),
        'mcpToolCalls' => array_sum(array_column($turns, 'mcpToolCalls')), 'hintHttpRequests' => array_sum(array_column($turns, 'hintHttpRequests')),
        'tokens' => $complete ? ['prompt' => array_sum(array_column(array_column($turns, 'tokens'), 'prompt')),
            'completion' => array_sum(array_column(array_column($turns, 'tokens'), 'completion')),
            'total' => array_sum(array_column(array_column($turns, 'tokens'), 'total'))] : null];
}
$report['completedAt'] = gmdate(\DATE_ATOM);
$report['status'] = !in_array(false, array_column($report['summary'], 'providerUsageComplete'), true) ? 'complete' : 'incomplete';
$save();
echo json_encode($report['summary'], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n";
exit('complete' === $report['status'] ? 0 : 1);
