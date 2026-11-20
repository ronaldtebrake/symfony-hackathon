<?php

declare(strict_types=1);

use App\Demo\BackendFactory;
use App\Demo\HttpConversation\Runner;
use App\Demo\RefundStudy\Proof;
use App\Demo\RefundStudy\Study;
use App\Demo\ThreeWayAuditRunner;
use Symfony\Component\Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
if ('--verify' === ($argv[1] ?? '') && $argc <= 3) {
    $file = $argv[2] ?? $root.'/docs/evidence/refund-skill.json';
    Proof::verify(json_decode(file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR), $root);
    echo "Recorded model conversations verified against their tool calls and reported usage.\n";

    return;
}
$provider = $argv[3] ?? Study::PROVIDER;
if ('--live' !== ($argv[1] ?? '') || !in_array($argc, [3, 4], true) || !in_array($provider, ['deepinfra', 'novita'], true)
    || false === ($sdk = realpath($argv[2])) || !is_file($sdk.'/vendor/autoload.php')) {
    fwrite(\STDERR, "Usage: php scripts/compare-refund-skill.php --verify [capture.json] | --live /path/to/installed-skills-sdk [novita|deepinfra]\n");

    exit(2);
}
(new Dotenv())->bootEnv($root.'/.env');
$key = BackendFactory::env('HUGGINGFACE_API_KEY');
if ('' === trim($key)) {
    fwrite(\STDERR, "Set HUGGINGFACE_API_KEY in the ignored local environment.\n");
    exit(2);
}
umask(0077);
$dir = $root.'/var/demo/refund-study';
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    throw new RuntimeException('Cannot create the private capture directory.');
}
$file = $dir.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
$report = ['format' => Proof::FORMAT, 'startedAt' => gmdate(\DATE_ATOM), 'protocol' => [
    'model' => ThreeWayAuditRunner::MODEL, 'provider' => $provider, 'settings' => Runner::SETTINGS,
    'agentBridge' => ['package' => 'symfony/ai-mcp-tool', 'toolPrefix' => 'symfony_'],
    'system' => Study::PROMPT, 'questions' => Study::QUESTIONS, 'order' => Study::ARMS, 'maxProviderRequestsPerTurn' => Study::LIMIT,
    'scope' => 'One live Symfony AI conversation per arm against fresh authenticated Symfony HTTP fixtures. The host opens the one named refund group. In the linked arm it confirms the single reviewed manifest and reads the Markdown on the same authenticated server. The two guidance arms supply the same body and role. The model chooses reader calls, arguments and final answers. Each run retains the same conversation and known schemas after revocation. No transaction operation exists.',
    'scoring' => 'Initial answer must cite a disposition of manual_review with a fresh authorized order and policy lookup. The second turn must repeat just the known policy read once, receive a denial, and report incomplete. Both JSON answers must say no refund was issued and give a nonempty explanation. Explanations are also available verbatim for human review.',
    'usageScope' => 'Provider-reported token usage includes model requests only. Host discovery and verified skill-loading exchanges are reported separately. Each turn records wall time from starting the model loop to the answer or failure. Fixed arm order and one conversation per arm do not estimate a causal cost or quality effect.',
], 'sourceSha256' => [], 'attempts' => []];
foreach (['scripts/compare-refund-skill.php', 'scripts/refund-server.php', 'src/Demo/BudgetedPlatform.php', 'src/Demo/Mcp/StudyToolset.php', 'src/Demo/Mcp/CaptureRequestFactory.php', 'composer.lock',
    ...glob('src/Demo/Refund/*.php'), ...glob('src/Demo/Refund/skills/refund-review/*.md'), ...glob('src/Demo/RefundStudy/*.php')] as $name) {
    if (is_file($root.'/'.$name)) {
        $report['sourceSha256'][$name] = hash_file('sha256', $root.'/'.$name);
    }
}
$save = static function () use (&$report, $file, $key): void {
    $json = json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
    if (strlen($json) > 4 * 1048576 || str_contains($json, $key) || str_contains($json, 'Bearer ') || str_contains($json, 'PRIVATE_FIXTURE_DO_NOT_RETURN')) {
        throw new RuntimeException('Refusing unsafe capture.');
    }
    if (file_put_contents($file.'.tmp', $json, \LOCK_EX) !== strlen($json) || !rename($file.'.tmp', $file)) {
        throw new RuntimeException('Could not retain the study.');
    }
};
$save();
echo 'Capture: '.substr($file, strlen($root) + 1)."\n";
foreach (Study::ARMS as $arm) {
    $attempt = Study::attempt($root, $sdk, $arm, $provider, $key);
    $report['attempts'][] = $attempt;
    $save();
    echo $arm.' '.$attempt['status'].', '.count($attempt['turns'])." turns\n";
}
$report['completedAt'] = gmdate(\DATE_ATOM);
$save();
exit(in_array('failed', array_column($report['attempts'], 'status'), true) ? 1 : 0);
