<?php

declare(strict_types=1);

namespace App\Demo\Refund;

use App\Demo\Groups\PublishingGroups;
use App\Demo\Mcp\CaptureRequestFactory;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Enum\ProtocolVersion;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Fixture
{
    public const SKILLS_REVISION = '1ef0205d9b9daf93c44eea437b58da38a6b84883';
    public const PROTOCOL_VERSION = ProtocolVersion::V2026_07_28->value;
    public const SKILLS_EXTENSION = 'io.modelcontextprotocol/skills';
    public const URI = 'skill://refund-review/SKILL.md';
    public const SKILL_FILE = 'src/Demo/Refund/skills/refund-review/SKILL.md';
    private const DISABLED_FUNCTIONS = 'pcntl_fork,pcntl_waitpid,pcntl_alarm,pcntl_signal,posix_setsid,posix_kill,posix_getpid,proc_open,popen,exec,shell_exec,system,passthru';
    private string $directory;
    private array $tokens;
    private ?Process $server = null;
    private string $endpoint;
    private HttpClientInterface $http;
    private CaptureRequestFactory $requests;
    private int $id = 0;

    public function __construct(string $root, ?string $sdk, string $mode, ?int $recordingPort = null)
    {
        if (!\in_array($mode, ['bare', 'inline', 'skill', 'selection', 'catalog'], true)) {
            throw new \InvalidArgumentException('Unknown refund review presentation.');
        }
        $environment = array_replace(array_fill_keys(array_keys(getenv() + $_ENV), false), ['PATH' => getenv('PATH'), 'SystemRoot' => getenv('SystemRoot')]);
        if (null !== $sdk) {
            $sdk = realpath($sdk);
            if (!$sdk || !is_dir($sdk.'/.git')) {
                throw new \InvalidArgumentException('A clean checkout of the pinned PHP SDK Skills PR is required.');
            }
            $git = static fn (array $args): string => trim((new Process(['git', ...$args], $sdk, $environment))->mustRun()->getOutput());
            if (self::SKILLS_REVISION !== $git(['rev-parse', 'HEAD']) || '' !== $git(['status', '--porcelain', '--untracked-files=all', '--', 'src', 'composer.json'])) {
                throw new \InvalidArgumentException('Use the pinned and unmodified PHP MCP Skills draft.');
            }
        } elseif (!\in_array($mode, ['selection', 'catalog'], true) && ('inline' !== $mode || null === $recordingPort)) {
            throw new \InvalidArgumentException('This presentation requires the pinned Skills SDK.');
        }
        $this->directory = sys_get_temp_dir().'/symfony-refund-'.bin2hex(random_bytes(12));
        $fs = new Filesystem();
        $this->tokens = ['reader' => bin2hex(random_bytes(32)), 'viewer' => bin2hex(random_bytes(32))];
        $this->http = HttpClient::create(['max_redirects' => 0, 'no_proxy' => '*', 'timeout' => 5, 'max_duration' => 10]);
        $this->requests = new CaptureRequestFactory('refund-review-study', null === $sdk ? [PublishingGroups::ID] : [PublishingGroups::ID, self::SKILLS_EXTENSION]);
        $fs->mkdir([$this->directory, $this->directory.'/empty-skills', $this->directory.'/sessions'], 0700);
        try {
            foreach ($this->tokens as $name => $token) {
                $fs->dumpFile($this->directory.'/'.$name.'.token', $token);
            }
            $fs->dumpFile($this->directory.'/permission', "granted\n");
            $router = null === $recordingPort ? 'refund-server.php' : 'refund-recording.php';
            $this->server = new Process([\PHP_BINARY, '-d', 'disable_functions='.self::DISABLED_FUNCTIONS, '-S', '127.0.0.1:'.($recordingPort ?? 0), $root.'/scripts/'.$router], $root,
                array_replace($environment, ['REFUND_DIRECTORY' => $this->directory, 'REFUND_SDK' => $sdk ?? false, 'REFUND_MODE' => $mode]), timeout: 10);
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

    public function enableProfiler(bool $shareWithDevApp = false): string
    {
        $fs = new Filesystem();
        if ($shareWithDevApp) {
            $fs->dumpFile($this->directory.'/share-dev-profiles', '');
        }
        $fs->dumpFile($this->directory.'/collect-profiles', '');

        return $this->endpoint;
    }

    public function connectReader(): Client
    {
        $client = Client::builder()->setClientInfo('Symfony demo verifier', '1')->setMaxRetries(0)->setInitTimeout(10)->setRequestTimeout(10)->build();
        $client->connect(new HttpTransport($this->endpoint, ['Authorization' => 'Bearer '.$this->tokens['reader']], new Psr18Client($this->http)));

        return $client;
    }

    public function lastOperation(): array
    {
        $trace = json_decode(file_get_contents($this->directory.'/trace.json'), true, flags: \JSON_THROW_ON_ERROR);
        if (1 !== \count($trace['operations'])) {
            throw new \UnexpectedValueException('Expected one recorded operation for this request.');
        }

        return $trace['operations'][0];
    }

    /** @return list<string> Self-contained, operator-only HTML panels for this fixture. */
    public function profilerPanels(array $expectedOperations): array
    {
        return RecordingProfiler::panels($this->directory, $expectedOperations);
    }

    public function revoke(): void
    {
        (new Filesystem())->dumpFile($this->directory.'/permission', "revoked\n");
    }

    /** Publish the private fixture location to the local operator and keep the recording endpoint alive. */
    public function serveRecording(\Closure $ready): void
    {
        $ready($this->directory, $this->endpoint);
        while ($this->server?->isRunning() && !is_file($this->directory.'/stop-recording')) {
            usleep(250000);
        }
    }

    public function close(): void
    {
        $this->server?->stop();
        (new Filesystem())->remove($this->directory);
    }
}
