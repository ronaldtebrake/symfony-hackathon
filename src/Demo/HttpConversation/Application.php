<?php

declare(strict_types=1);

namespace App\Demo\HttpConversation;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

/** Disposable independent application. The live recording account and its state are never touched. */
final class Application
{
    public const DISABLED = 'pcntl_fork,pcntl_waitpid,pcntl_alarm,pcntl_signal,posix_setsid,posix_kill,posix_getpid,proc_open,popen,exec,shell_exec,system,passthru';
    public readonly string $directory;
    public readonly string $token;
    public string $endpoint = '';
    public array $sourceSha256 = [];
    private array $environment;
    private ?Process $server = null;

    public function __construct(private readonly string $root, string $environment = 'dev')
    {
        $this->directory = sys_get_temp_dir().'/symfony-http-conversation-'.bin2hex(random_bytes(12));
        $this->token = bin2hex(random_bytes(32));
        $this->environment = array_replace(array_fill_keys(array_keys($_ENV + getenv()), false), [
            'PATH' => getenv('PATH') ?: '', 'SystemRoot' => getenv('SystemRoot') ?: false,
            'APP_ENV' => $environment, 'APP_DEBUG' => '1', 'ADOPTION_MODE' => 'catalog',
            'ADOPTION_MCP_TOKEN' => $this->token, 'ADOPTION_VIEWER_TOKEN' => bin2hex(random_bytes(32)),
            'ADOPTION_READER_ACCESS_FILE' => $this->directory.'/var/capture-reader-access',
        ]);
    }

    public function start(): void
    {
        $source = $this->root.'/examples/symfony';
        if (!is_file($source.'/vendor/autoload.php') || is_link($source.'/vendor/ronaldtebrake/symfony-code-mode')) {
            throw new \RuntimeException('Install the independent example with copied packages before capturing.');
        }
        $fs = new Filesystem();
        $fs->mkdir($this->directory, 0700);
        $files = (new Finder())->in($source)->ignoreDotFiles(false)->exclude(['var', '.git'])
            ->notName('.env.local')->notName('.env.*.local');
        $fs->mirror($source, $this->directory, $files);
        $sources = require $source.'/scripts/http-sources.php';
        $this->sourceSha256 = $sources($this->directory);
        if ($this->sourceSha256 !== $sources($source, $this->root.'/packages')) {
            throw new \RuntimeException('The copied application packages do not match current source. Reinstall the example path packages.');
        }
        $this->control(['demo:reader-access', 'grant']);
        $this->control(['code-mode:reset-budget', 'demo-reader']);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (false === $socket) {
            throw new \RuntimeException('Cannot allocate a local HTTP port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->endpoint = 'http://'.$address.'/mcp';
        $this->server = new Process([\PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'disable_functions='.self::DISABLED,
            '-S', $address, '-t', 'public', 'public/index.php'], $this->directory, $this->environment);
        $this->server->disableOutput();
        $this->server->start();
        $http = HttpClient::create(['timeout' => 1, 'max_duration' => 2, 'max_redirects' => 0]);
        $deadline = microtime(true) + 15;
        do {
            try {
                if (401 === $http->request('GET', $this->endpoint)->getStatusCode()) {
                    return;
                }
            } catch (\Throwable) {
            }
            usleep(20000);
        } while ($this->server->isRunning() && microtime(true) < $deadline);
        throw new \RuntimeException('The authenticated HTTP application did not start.');
    }

    public function revoke(): string
    {
        return trim($this->control(['demo:reader-access', 'revoke']));
    }

    public function stop(): void
    {
        $this->server?->stop();
        (new Filesystem())->remove($this->directory);
    }

    private function control(array $args): string
    {
        $process = new Process([\PHP_BINARY, 'bin/console', ...$args], $this->directory, $this->environment, timeout: 30);
        $process->mustRun();

        return $process->getOutput();
    }
}
