<?php

declare(strict_types=1);

namespace App\Demo\Skills;

use App\Demo\Mcp\CaptureRequestFactory;
use Mcp\Schema\Enum\ProtocolVersion;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A private copy of the Symfony application. Only the host can issue credentials or revoke the reader. */
final class Fixture
{
    public const REVISION = '1ef0205d9b9daf93c44eea437b58da38a6b84883';
    public const VERSION = ProtocolVersion::V2026_07_28->value;
    public const EXTENSION = 'io.modelcontextprotocol/skills';
    public const URI = 'skill://read-published-article/SKILL.md';
    public const ARTICLE = 317;
    public const TITLE = 'A reading room above the station';

    private readonly string $directory;
    private readonly Filesystem $files;
    private readonly HttpClientInterface $http;
    private readonly CaptureRequestFactory $requests;
    private readonly array $environment;
    private readonly array $credentials;
    private ?Process $server = null;
    private ?string $url = null;
    private int $id = 0;

    public function __construct(private readonly string $root, string $sdk, bool $startHttp = true)
    {
        $sdk = realpath($sdk);
        if (false === $sdk || !is_dir($sdk.'/.git') || !is_file($sdk.'/vendor/symfony/yaml/Yaml.php')) {
            throw new \InvalidArgumentException('Pass a clean checkout of the pinned PHP MCP SDK draft.');
        }
        $cleanEnvironment = array_replace(array_fill_keys(array_keys(getenv() + $_ENV), false), [
            'PATH' => getenv('PATH') ?: '', 'SystemRoot' => getenv('SystemRoot') ?: false,
        ]);
        $git = static fn (array $command): string => trim((new Process(['git', ...$command], $sdk, $cleanEnvironment, timeout: 10))->mustRun()->getOutput());
        if (self::REVISION !== $git(['rev-parse', 'HEAD']) || '' !== $git(['status', '--porcelain', '--untracked-files=all', '--', 'src', 'composer.json'])) {
            throw new \InvalidArgumentException('Skills experiment requires the unmodified, pinned PR #372 SDK source.');
        }
        $this->directory = sys_get_temp_dir().'/symfony-skill-'.bin2hex(random_bytes(12));
        $this->files = new Filesystem();
        $this->environment = array_replace($cleanEnvironment, ['APP_ENV' => 'skill_eval', 'ADOPTION_MODE' => 'catalog',
            'ADOPTION_READER_ACCESS_FILE' => $this->directory.'/var/reader-access']);
        $this->credentials = ['reader' => bin2hex(random_bytes(32)), 'viewer' => bin2hex(random_bytes(32))];
        $this->http = HttpClient::create(['max_redirects' => 0, 'timeout' => 10, 'max_duration' => 15, 'no_proxy' => '*']);
        $this->requests = new CaptureRequestFactory('symfony-skills-experiment', [self::EXTENSION]);
        $this->files->mkdir($this->directory, 0700);
        try {
            $this->copyApplication($root.'/examples/symfony', $sdk);
            $this->access('grant');
            if (!$startHttp) {
                return;
            }
            $disabled = 'pcntl_fork,pcntl_waitpid,pcntl_alarm,pcntl_signal,posix_setsid,posix_kill,posix_getpid,proc_open,popen,exec,shell_exec,system,passthru';
            $this->server = new Process([\PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'disable_functions='.$disabled,
                '-S', '127.0.0.1:0', '-t', 'public', 'public/index.php'], $this->directory,
                array_replace($this->environment, ['ADOPTION_MCP_TOKEN' => $this->credentials['reader'], 'ADOPTION_VIEWER_TOKEN' => $this->credentials['viewer']]));
            $this->server->start();
            $deadline = microtime(true) + 10;
            while ($this->server->isRunning() && microtime(true) < $deadline) {
                if (preg_match('{Development Server \((http://127\.0\.0\.1:\d+)\) started}', $this->server->getErrorOutput(), $match)) {
                    $this->url = $match[1].'/mcp';

                    return;
                }
                usleep(20000);
            }
            throw new \RuntimeException('Could not start the disposable Symfony loopback server.');
        } catch (\Throwable $failure) {
            $this->close();
            throw $failure;
        }
    }

    /** Raw modern MCP exchange. Tokens and host paths are omitted from the receipt. */
    public function rpc(string $user, string $method, array $params = []): array
    {
        if (!\array_key_exists($user, $this->credentials) && 'anonymous' !== $user) {
            throw new \InvalidArgumentException('Unknown experiment identity.');
        }
        if (null === $this->url) {
            throw new \LogicException('No HTTP listener was started for this fixture.');
        }
        $id = ++$this->id;
        $body = $this->requests->message($id, $method, $params);
        $encoded = json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', ...$this->requests->headers($body)];
        if ('anonymous' !== $user) {
            $headers['Authorization'] = 'Bearer '.$this->credentials[$user];
        }
        $response = $this->http->request('POST', $this->url, ['headers' => $headers, 'body' => $encoded]);
        $status = $response->getStatusCode();
        $fields = $response->getHeaders(false);
        $reply = null;
        $content = $response->getContent(false);
        if (401 !== $status) {
            if (\strlen($content) > 131072 || !str_starts_with($fields['content-type'][0] ?? '', 'application/json')) {
                throw new \RuntimeException('Unexpected HTTP response from the local fixture (status '.$status.').');
            }
            foreach ([...$this->credentials, 'PRIVATE_FIXTURE_DO_NOT_RETURN'] as $secret) {
                if (str_contains($content, $secret)) {
                    throw new \RuntimeException('Refusing to capture private fixture data.');
                }
            }
            $reply = json_decode($content, true, 64, \JSON_THROW_ON_ERROR);
            if ('2.0' !== ($reply['jsonrpc'] ?? null) || $id !== ($reply['id'] ?? null)
                || isset($reply['result']) === isset($reply['error'])) {
                throw new \RuntimeException('Unexpected JSON-RPC response envelope (HTTP '.$status.').');
            }
        }

        return ['status' => $status, 'cacheControl' => implode(', ', $fields['cache-control'] ?? []), 'vary' => implode(', ', $fields['vary'] ?? []),
            'requestSha256' => hash('sha256', $encoded), 'responseSha256' => hash('sha256', $content), 'reply' => $reply];
    }

    /** Exercise multiple Symfony requests in one real kernel using the same copied app and accounts. */
    public function checkKernelReuse(): array
    {
        $worker = new Process([\PHP_BINARY, $this->root.'/scripts/check-skill-kernel.php', '--in-private-app'], $this->directory,
            array_replace($this->environment, ['ADOPTION_MCP_TOKEN' => $this->credentials['reader'], 'ADOPTION_VIEWER_TOKEN' => $this->credentials['viewer']]), timeout: 20);
        if (0 !== $worker->run()) {
            throw new \RuntimeException('The isolated kernel-reuse verification failed.');
        }
        $output = $worker->getOutput();
        if (\strlen($output) > 131072 || str_contains($output, $this->credentials['reader']) || str_contains($output, $this->credentials['viewer'])
            || str_contains($output, 'PRIVATE_FIXTURE_DO_NOT_RETURN')) {
            throw new \RuntimeException('Refusing to retain private probe output.');
        }

        return json_decode($output, true, 64, \JSON_THROW_ON_ERROR);
    }

    public function revoke(): void
    {
        $this->access('revoke');
    }

    public function close(): void
    {
        try {
            $this->server?->stop();
        } finally {
            $this->files->remove($this->directory);
        }
    }

    private function copyApplication(string $source, string $sdk): void
    {
        $this->files->mkdir($this->directory.'/var/empty-skills', 0700);
        $this->files->mirror($source.'/src', $this->directory.'/src');
        foreach (['bin/console', 'public/index.php', 'composer.json', 'config/routes.php', 'config/services.php',
            'skills/read-published-article/SKILL.md'] as $file) {
            $this->files->copy($source.'/'.$file, $this->directory.'/'.$file);
        }
        // Register the AI bundle in this private environment and add one synthetic held-out row.
        // Keep the original article reader, its policy and its unpublished fixture.
        $bundle = str_replace("AiBundle::class => ['catalog' => true", "AiBundle::class => ['skill_eval' => true, 'catalog' => true", file_get_contents($source.'/config/bundles.php'), $bundles);
        $data = str_replace('private const ARTICLES = [', "private const ARTICLES = [\n        ".self::ARTICLE." => ['title' => ".var_export(self::TITLE, true).", 'published' => true],", file_get_contents($source.'/src/ArticleCatalog.php'), $tables);
        $bundle = str_replace('return [', "return [\n    Adoption\\SkillExperimentBundle::class => ['skill_eval' => true],", $bundle, $bundleLists);
        if (1 !== $bundles || 1 !== $tables || 1 !== $bundleLists) {
            throw new \RuntimeException('The disposable fixture cannot extend this version of the example application.');
        }
        $this->files->dumpFile($this->directory.'/config/bundles.php', $bundle);
        $this->files->dumpFile($this->directory.'/src/ArticleCatalog.php', $data);

        // Load the exact draft SDK sources ahead of the example's released SDK, in this process only.
        $autoload = var_export($source.'/vendor/autoload.php', true);
        $draft = var_export($sdk.'/src', true);
        $yaml = var_export($sdk.'/vendor/symfony/yaml', true);
        $this->files->dumpFile($this->directory.'/vendor/autoload.php', <<<PHPFILE
            <?php
            \$existing = require $autoload;
            \$local = new Composer\\Autoload\\ClassLoader();
            \$local->setPsr4('Mcp\\\\', $draft);
            \$local->setPsr4('Symfony\\\\Component\\\\Yaml\\\\', $yaml);
            \$local->setPsr4('Adoption\\\\', __DIR__.'/../src');
            \$local->register(true);
            if (realpath($draft.'/Server.php') !== (new ReflectionClass(Mcp\\Server::class))->getFileName()) {
                throw new RuntimeException('Unexpected MCP SDK loaded.');
            }
            return \$existing;
            PHPFILE
        );
    }

    private function access(string $action): void
    {
        (new Process([\PHP_BINARY, 'bin/console', 'demo:reader-access', $action], $this->directory, $this->environment, timeout: 20))->mustRun();
    }
}
