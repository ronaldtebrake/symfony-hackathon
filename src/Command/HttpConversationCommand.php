<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\BackendFactory;
use App\Demo\HttpConversation\Runner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'code-mode:http-conversation', description: 'Capture live Symfony AI + Hugging Face lookup and revocation over authenticated MCP HTTP.')]
final class HttpConversationCommand extends Command
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $root)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('live', null, InputOption::VALUE_NONE, 'Spend inference credits: at most eight requests, with every attempt retained.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->getOption('live') || '' === trim(BackendFactory::env('HUGGINGFACE_API_KEY'))) {
            $io->error('Set the inference key in ignored .env.local and pass --live.');

            return self::INVALID;
        }
        umask(0077);
        $directory = $this->root.'/var/demo/http-conversation';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        $last = 0;
        $io->text('Disposable Symfony application; at most eight model requests. Existing recording state is untouched.');
        $report = (new Runner($this->root))->run(progress: static function (array $report) use ($path, $io, &$last): void {
            $bytes = json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
            if (\strlen($bytes) > 2 * 1048576 || file_put_contents($path.'.tmp', $bytes, \LOCK_EX) !== \strlen($bytes) || !rename($path.'.tmp', $path)) {
                throw new \RuntimeException('Cannot retain the HTTP conversation.');
            }
            $count = $report['trace']['modelRequests'] ?? 0;
            if ($count > $last) {
                $io->text('Observed model request '.$count);
                $last = $count;
            }
        });
        $io->text($report['status'].' · '.($report['trace']['providerTokens']['total'] ?? 'unreported').' tokens · '.str_replace($this->root.'/', '', $path));
        foreach ($report['verification']['checks'] as $check => $passed) {
            if (!$passed) {
                $io->text('Not verified: '.$check);
            }
        }

        return $report['verification']['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
