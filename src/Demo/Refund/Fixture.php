<?php

declare(strict_types=1);

namespace App\Demo\Refund;

use App\Demo\Groups\PublishingGroups;
use App\Demo\HttpConversation\Application;
use App\Demo\Mcp\CaptureRequestFactory;
use App\Demo\Skills\Fixture as SkillsFixture;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Fixture
{
    public const URI = 'skill://refund-review/SKILL.md';
    public const SKILL_FILE = 'src/Demo/Refund/skills/refund-review/SKILL.md';
    private string $directory;
    private array $tokens;
    private ?Process $server = null;
    private string $endpoint;
    private HttpClientInterface $http;
    private CaptureRequestFactory $requests;
    private int $id = 0;

    public function __construct(string $root, string $sdk, string $mode)
    {
        if (!\in_array($mode, ['bare', 'inline', 'skill'], true)) {
            throw new \InvalidArgumentException('Unknown refund review presentation.');
        }
        $sdk = realpath($sdk);
        if (!$sdk || !is_dir($sdk.'/.git')) {
            throw new \InvalidArgumentException('A clean checkout of the pinned PHP SDK Skills PR is required.');
        }
        $environment = array_replace(array_fill_keys(array_keys(getenv() + $_ENV), false), ['PATH' => getenv('PATH'), 'SystemRoot' => getenv('SystemRoot')]);
        $git = static fn (array $args): string => trim((new Process(['git', ...$args], $sdk, $environment))->mustRun()->getOutput());
        if (SkillsFixture::REVISION !== $git(['rev-parse', 'HEAD']) || '' !== $git(['status', '--porcelain', '--untracked-files=all', '--', 'src', 'composer.json'])) {
            throw new \InvalidArgumentException('Use the pinned and unmodified PHP MCP Skills draft.');
        }
        $this->directory = sys_get_temp_dir().'/symfony-refund-'.bin2hex(random_bytes(12));
        $fs = new Filesystem();
        $this->tokens = ['reader' => bin2hex(random_bytes(32)), 'viewer' => bin2hex(random_bytes(32))];
        $this->http = HttpClient::create(['max_redirects' => 0, 'no_proxy' => '*', 'timeout' => 5, 'max_duration' => 10]);
        $this->requests = new CaptureRequestFactory('refund-review-study', [PublishingGroups::ID, SkillsFixture::EXTENSION]);
        $fs->mkdir([$this->directory, $this->directory.'/empty-skills', $this->directory.'/sessions'], 0700);
        try {
            foreach ($this->tokens as $name => $token) {
                $fs->dumpFile($this->directory.'/'.$name.'.token', $token);
            }
            $fs->dumpFile($this->directory.'/permission', "granted\n");
            $this->server = new Process([\PHP_BINARY, '-d', 'disable_functions='.Application::DISABLED, '-S', '127.0.0.1:0', $root.'/scripts/refund-server.php'], $root,
                array_replace($environment, ['REFUND_DIRECTORY' => $this->directory, 'REFUND_SDK' => $sdk, 'REFUND_MODE' => $mode]), timeout: 10);
            $this->server->start();
            if (!$this->server->waitUntil(function (string $type, string $part): bool {
                if (preg_match('{Development Server \((http://127\.0\.0\.1:\d+)\) started}', $part, $match)) {
                    $this->endpoint = $match[1].'/mcp';

                    return true;
                }

                return false;
            })) {
                throw new \RuntimeException('The disposable refund server could not bind to loopback.');
            }
            $this->server->setTimeout(null);
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public static function instructions(): string
    {
        return self::guideBody(file_get_contents(__DIR__.'/skills/refund-review/SKILL.md'));
    }

    public static function guideBody(string $source): string
    {
        if (!preg_match('/\A---\n.*?\n---\n\s*(.+)\z/sD', $source, $parts)) {
            throw new \UnexpectedValueException('Invalid reviewed skill.');
        }

        return trim($parts[1]);
    }

    public function rpc(string $user, string $method, array $params = [], bool $retainWire = false): array
    {
        if ('anonymous' !== $user && !isset($this->tokens[$user])) {
            throw new \InvalidArgumentException('Unknown fixture account.');
        }
        $request = $this->requests->message(++$this->id, $method, $params);
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', ...$this->requests->headers($request)];
        if (isset($this->tokens[$user])) {
            $headers['Authorization'] = 'Bearer '.$this->tokens[$user];
        }
        $payload = json_encode($request, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $response = $this->http->request('POST', $this->endpoint, ['body' => $payload, 'headers' => $headers]);
        $code = $response->getStatusCode();
        $raw = $response->getContent(false);
        $trace = json_decode(file_get_contents($this->directory.'/trace.json'), true, flags: \JSON_THROW_ON_ERROR);
        foreach ($this->tokens as $token) {
            if (str_contains($raw.json_encode([$request, $trace], \JSON_THROW_ON_ERROR), $token)) {
                throw new \RuntimeException('Refusing to capture a private credential.');
            }
        }
        if (\strlen($raw) > 64000 || !\in_array($code, [200, 400, 401, 404], true)) {
            throw new \RuntimeException('Unexpected refund fixture HTTP status: '.$code);
        }
        $reply = 401 === $code ? null : json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
        if (null !== $reply && (($reply['id'] ?? null) !== $request['id'] || isset($reply['error']) === isset($reply['result']))) {
            throw new \RuntimeException('The refund fixture returned an invalid JSON-RPC envelope.');
        }

        $wire = $retainWire && null !== $reply ? ['requestJson' => $payload, 'responseJson' => $raw] : [];

        return $wire + ['user' => $user, 'request' => $request, 'response' => $reply, 'httpStatus' => $code, 'cacheControl' => $response->getHeaders(false)['cache-control'][0] ?? '',
            'responseSha256' => hash('sha256', $raw), 'trace' => $trace];
    }

    public function revoke(): void
    {
        (new Filesystem())->dumpFile($this->directory.'/permission', "revoked\n");
    }

    public function close(): void
    {
        $this->server?->stop();
        (new Filesystem())->remove($this->directory);
    }
}
