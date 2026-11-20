<?php

declare(strict_types=1);

use App\Demo\BackendFactory;
use App\Demo\HttpConversation\Runner;
use App\Demo\Skills\AgentStudy;
use App\Demo\Skills\Fixture;
use App\Demo\ThreeWayAuditRunner;
use Symfony\AI\Platform\Bridge\HuggingFace\Factory;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\TraceableHttpClient;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$provider = $argv[3] ?? AgentStudy::PROVIDER;
if (!in_array($argc, [3, 4], true) || '--live' !== $argv[1] || !in_array($provider, [AgentStudy::PROVIDER, 'novita'], true)
    || false === ($sdk = realpath($argv[2])) || !is_file($sdk.'/vendor/autoload.php')) {
    fwrite(\STDERR, "Usage: php scripts/compare-article-skill.php --live /path/to/installed-php-sdk-draft [deepinfra|novita]\nTwo fresh Symfony apps, at most eight Hugging Face requests in total.\n");
    exit(2);
}
(new Dotenv())->bootEnv($root.'/.env');
$key = BackendFactory::env('HUGGINGFACE_API_KEY');
if ('' === trim($key)) {
    fwrite(\STDERR, "Set HUGGINGFACE_API_KEY in the ignored local environment.\n");
    exit(2);
}
umask(0077);
$dir = $root.'/var/demo/skills-agent';
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    throw new RuntimeException('Could not create evidence directory.');
}
$file = $dir.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
$attempt = ['startedAt' => gmdate(\DATE_ATOM), 'mode' => 'live', 'status' => 'running',
    'protocol' => ['question' => AgentStudy::QUESTION, 'expectedTitle' => AgentStudy::TITLE, 'commonSystemPrompt' => AgentStudy::PROMPT,
        'agentBridge' => ['package' => 'symfony/ai-mcp-tool', 'toolPrefix' => 'symfony_'],
        'model' => ThreeWayAuditRunner::MODEL, 'providerRoute' => $provider, 'settings' => Runner::SETTINGS, 'providerRequestLimitPerArm' => AgentStudy::LIMIT,
        'expectedSkillDigest' => AgentStudy::DIGEST, 'sdkRevision' => Fixture::REVISION, 'order' => ['search', 'skill'], 'executionAttemptsPerReader' => 2,
        'activation' => 'The authenticated host selects one reviewed text-only skill. Both arms get service discovery and the same two tools. Only the skill arm lists, gets and reads the pinned skill and adds its verified bytes as a separate user-role reference. The model chooses all catalog searches and executions. Fresh app, grant, budget and empty model history for each arm.',
        'scoring' => 'Fresh discovery followed by a successful read of integer ID 317 and the exact returned title as the final answer.'],
    'sourceSha256' => [], 'arms' => []];
foreach (['src/Demo/Skills/AgentStudy.php', 'src/Demo/Mcp/StudyToolset.php', 'src/Demo/Mcp/CaptureRequestFactory.php', 'src/Demo/Skills/Fixture.php', 'scripts/compare-article-skill.php', 'examples/symfony/skills/read-published-article/SKILL.md',
    'examples/symfony/src/SkillExperimentBundle.php', 'examples/symfony/src/SkillPresentation.php', 'examples/symfony/src/ArticleCatalog.php', 'examples/symfony/src/ArticleVoter.php', 'examples/symfony/src/ArticleTool.php', 'composer.lock'] as $source) {
    $attempt['sourceSha256'][$source] = hash_file('sha256', $root.'/'.$source);
}
$save = static function () use ($file, $key, &$attempt): void {
    $data = json_encode(['format' => 'symfony-skill-agent/v1', 'attempts' => [$attempt]], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
    if (strlen($data) > 2 * 1048576 || str_contains($data, $key) || str_contains($data, 'PRIVATE_FIXTURE_DO_NOT_RETURN')) {
        throw new RuntimeException('Refusing to retain unsafe evidence.');
    }
    if (file_put_contents($file.'.tmp', $data, \LOCK_EX) !== strlen($data) || !rename($file.'.tmp', $file)) {
        throw new RuntimeException('Could not retain the trial.');
    }
};
$save();
echo 'Capture: '.substr($file, strlen($root) + 1)."\n";
foreach ($attempt['protocol']['order'] as $arm) {
    $fixture = null;
    $http = new TraceableHttpClient(HttpClient::create(['timeout' => 45, 'max_duration' => 60, 'max_redirects' => 0]));
    try {
        $fixture = new Fixture($root, $sdk);
        $row = (new AgentStudy())->run('skill' === $arm, static fn (string $method, array $params) => $fixture->rpc('reader', $method, $params),
            Factory::createPlatform($key, provider: $provider, httpClient: $http));
    } catch (Throwable $error) {
        $row = ['arm' => $arm, 'status' => 'failed', 'errorType' => $error::class, 'answer' => null, 'score' => AgentStudy::score('', []),
            'hostCalls' => [], 'calls' => [], 'requests' => ['provider' => 0, 'hostMcp' => 0, 'agentMcp' => 0], 'tokens' => null];
    } finally {
        $fixture?->close();
    }
    $row['providerHttp'] = [];
    foreach ($http->getTracedRequests() as $request) {
        $body = $request['options']['body'] ?? null;
        $sent = is_string($body) ? json_decode($body, true) : null;
        $row['providerHttp'][] = ['status' => $request['info']['http_code'] ?? null, 'requestedModel' => $sent['model'] ?? null];
    }
    $attempt['arms'][] = $row;
    $save();
    echo $arm.' — '.$row['status'].', correct='.($row['score']['correct'] ? 'yes' : 'no').', requests='.$row['requests']['provider']."\n";
}
$attempt['status'] = [] === array_filter($attempt['arms'], static fn ($a) => 'completed' !== $a['status'] || null === $a['tokens']) ? 'complete' : 'incomplete';
$attempt['completedAt'] = gmdate(\DATE_ATOM);
$save();
exit('complete' === $attempt['status'] ? 0 : 1);
