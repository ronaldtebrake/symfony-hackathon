<?php

declare(strict_types=1);

namespace App\Demo\RefundRecording;

use App\Demo\Refund\Fixture;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class InteractiveChatCommand extends Command
{
    public static function chat(string $root, string $client, bool $revokeAfterOrder): int
    {
        if (!\in_array($client, ['claude', 'codex'], true)) {
            throw new \InvalidArgumentException('Choose your signed-in client: composer demo:chat -- claude or composer demo:chat -- codex');
        }
        $binary = (new ExecutableFinder())->find($client) ?? throw new \RuntimeException(\sprintf('Install %s, add it to PATH, and sign in before starting the demo.', $client));
        $terminal = [\STDIN, \STDOUT, \STDERR];
        if (!stream_isatty(\STDIN) || !stream_isatty(\STDOUT)) {
            throw new \RuntimeException('Run composer demo:chat in an interactive terminal.');
        }

        umask(0077);
        $fs = new Filesystem();
        $work = sys_get_temp_dir().'/symfony-agent-'.bin2hex(random_bytes(12));
        $fixture = new Fixture($root, null, 'catalog', 0);
        $child = null;
        $clean = static function () use (&$child, $fixture, $fs, $work): void {
            if (\is_resource($child)) {
                proc_terminate($child);
            }
            $fixture->close();
            $fs->remove($work);
        };
        register_shutdown_function($clean);
        if (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([\SIGTERM, \SIGHUP, \SIGINT] as $signal) {
                pcntl_signal($signal, static fn (int $signal) => exit(128 + $signal));
            }
        }
        $code = 1;
        $fs->mkdir($work, 0700);
        $fixture->serveRecording(static function (string $directory, string $url) use ($client, $binary, $root, $fs, $work, $terminal, $revokeAfterOrder, &$code, &$child): void {
            $fs->dumpFile($directory.'/interactive-chat', '');
            if ($revokeAfterOrder) {
                $fs->dumpFile($directory.'/revoke-after-order', '');
            }
            $helper = implode(' ', array_map(escapeshellarg(...), [\PHP_BINARY, $root.'/scripts/refund-recording.php', 'chat-headers', basename($directory)]));
            $environment = getenv();
            if ('claude' === $client) {
                $server = ['type' => 'http', 'url' => $url, 'headersHelper' => $helper, 'alwaysLoad' => true];
                $config = json_encode(['mcpServers' => ['symfony_code_mode' => $server]], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
                $arguments = [$binary, '--strict-mcp-config', '--mcp-config', $config, '--setting-sources', '',
                    '--tools', 'REPL,ToolSearch', '--allowedTools', 'mcp__symfony_code_mode__search_tools,mcp__symfony_code_mode__execute_tools'];
                $environment['CLAUDE_CODE_EXPERIMENTAL_CODE_MODE'] = '1';
                $environment['ENABLE_TOOL_SEARCH'] = 'false';
            } else {
                $arguments = self::codexArguments($binary, $work, $url, $helper);
            }
            fwrite($terminal[1], "Symfony Code Mode\nPlease find and follow our team’s refund procedure for order R-1042. What should I tell the customer?\n\n");
            $child = proc_open($arguments, $terminal, $pipes, $work, $environment, ['bypass_shell' => true]);
            if (!\is_resource($child)) {
                throw new \RuntimeException('Unable to start '.$client.' in this terminal.');
            }
            do {
                $status = proc_get_status($child);
                if ($status['running']) {
                    usleep(100000);
                }
            } while ($status['running']);
            proc_close($child);
            $child = null;
            $code = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
            $fs->dumpFile($directory.'/stop-recording', '');
        });

        return $code;
    }

    /** Both clients request the transient bearer through a pipe. */
    public static function headers(string $session): void
    {
        if (stream_isatty(\STDOUT) || !preg_match('/\Asymfony-refund-[a-f0-9]{24}\z/D', $session)) {
            throw new \InvalidArgumentException('The header helper requires a private chat session and a client pipe.');
        }
        $directory = sys_get_temp_dir().'/'.$session;
        if (!is_file($directory.'/interactive-chat') || !is_file($directory.'/reader.token')) {
            throw new \RuntimeException('This demo session has ended. Start a fresh chat.');
        }
        echo json_encode(['Authorization' => 'Bearer '.trim(file_get_contents($directory.'/reader.token'))], \JSON_THROW_ON_ERROR);
    }

    protected function configure(): void
    {
        $this->addArgument('client', InputArgument::REQUIRED, 'claude or codex')
            ->addOption('revoke-after-order', null, InputOption::VALUE_NONE, 'Revoke the demo grant between the order and policy reads');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Keep Composer's real terminal handles while loading the app's own locked SDK and Symfony versions.
        $root = \dirname(__DIR__, 3);
        $args = [\PHP_BINARY, $root.'/scripts/refund-recording.php', 'chat', $input->getArgument('client')];
        if ($input->getOption('revoke-after-order')) {
            $args[] = '--revoke-after-order';
        }
        $process = proc_open($args, [\STDIN, \STDOUT, \STDERR], $pipes, $root, options: ['bypass_shell' => true]);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Unable to start the local demo with PHP.');
        }

        return proc_close($process);
    }

    private static function codexArguments(string $binary, string $work, string $url, string $helper): array
    {
        $arguments = [$binary, '--disable', 'apps', '--disable', 'plugins', '--disable', 'hooks', '--disable', 'shell_tool'];
        // Codex merges per-run configuration with personal MCP connections. Disable those for this chat only.
        $list = new Process([...$arguments, 'mcp', 'list', '--json'], $work, timeout: 10);
        if (0 !== $list->run()) {
            throw new \RuntimeException('Codex could not list its MCP configuration. Check that codex mcp list works in your terminal.');
        }
        $json = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;
        $servers = ['symfony_code_mode={enabled=true,required=true,enabled_tools=["search_tools","execute_tools"],url='.json_encode($url, $json).',http_headers_helper='.json_encode($helper, $json).'}'];
        foreach (json_decode($list->getOutput(), true, flags: \JSON_THROW_ON_ERROR) as $server) {
            if (!$server['enabled'] || 'symfony_code_mode' === $server['name']) {
                continue;
            }
            // Some built-in servers need a transport even in a disabled override.
            $transport = 'stdio' === $server['transport']['type'] ? 'command="php"' : 'url="http://127.0.0.1"';
            $servers[] = json_encode($server['name'], $json).'={enabled=false,'.$transport.'}';
        }

        return [...$arguments, '--no-daemon', '--sandbox', 'read-only', '-c', 'history.persistence="none"', '-c', 'mcp_servers={'.implode(',', $servers).'}'];
    }
}
