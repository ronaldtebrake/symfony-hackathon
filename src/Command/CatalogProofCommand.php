<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\BackendFactory;
use App\Demo\CatalogControlProof;
use App\Demo\CatalogProofRunner;
use App\Demo\EvaluationScenario;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'code-mode:catalog-proof', description: 'Capture JSON catalog controls and five fixed conversations; no generated-code runtime required.')]
final class CatalogProofCommand extends Command
{
    public function __construct(private readonly CatalogProofRunner $runner, #[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('live', null, InputOption::VALUE_NONE, 'Use Hugging Face credits. Default: operator reference algorithms and actual handlers.')
            ->addOption('scenario', null, InputOption::VALUE_REQUIRED, 'all or one fixed scenario.', 'all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $live = $input->getOption('live');
        $scenario = $input->getOption('scenario');
        if (!\in_array($scenario, ['all', ...EvaluationScenario::IDS], true)) {
            $io->error('Choose a documented scenario.');

            return self::INVALID;
        }
        if ($live && '' === trim(BackendFactory::env('HUGGINGFACE_API_KEY'))) {
            $io->error('Configure the key in ignored .env.local.');

            return self::INVALID;
        }
        umask(0077);
        $dir = $this->projectDir.'/var/demo/catalog-proof';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $lock = fopen($dir.'/capture.lock', 'c');
        if (!$lock || !flock($lock, \LOCK_EX | \LOCK_NB)) {
            $io->error('A catalog capture is already active.');

            return self::FAILURE;
        }
        $path = $dir.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        $report = ['version' => 1, 'kind' => 'catalog-proof', 'id' => basename($path, '.json'), 'status' => 'running', 'mode' => $live ? 'live' : 'reference', 'createdAt' => gmdate(\DATE_ATOM), 'runs' => []];
        foreach ('all' === $scenario ? EvaluationScenario::IDS : [$scenario] as $id) {
            $report['runs'][] = ['scenario' => $id, 'status' => 'pending'];
        }
        $save = static function () use (&$report, $path): void {
            $bytes = json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
            if (\strlen($bytes) > 4 * 1048576 || file_put_contents($path.'.tmp', $bytes, \LOCK_EX) !== \strlen($bytes) || !rename($path.'.tmp', $path)) {
                throw new \RuntimeException('Cannot retain catalog capture.');
            }
        };
        try {
            $save();
            $report['controls'] = CatalogControlProof::run($this->projectDir);
            $save();
            if (!$report['controls']['verification']['passed']) {
                throw new \RuntimeException('Catalog control proof failed.');
            }
            $io->text(($live ? 'Live catalog conversations: ' : 'Reference algorithms: ').\count($report['runs']).' planned scenarios; 16 requests maximum per live conversation. Every attempt is retained.');
            foreach ($report['runs'] as $index => $planned) {
                $io->section($planned['scenario']);
                $last = 0;
                $run = $this->runner->run($planned['scenario'], $live, static function (array $run) use (&$report, $index, $save, $io, &$last): void {
                    $report['runs'][$index] = $run;
                    $save();
                    $count = $run['trace']['modelRequests'] ?? 0;
                    if ($count > $last) {
                        $io->text('Observed request '.$count);
                        $last = $count;
                    }
                });
                $io->text($run['status'].' · '.($run['trace']['providerTokens']['total'] ?? 0).' reported tokens');
                foreach ($run['verification']['checks'] as $check => $passed) {
                    if (!$passed) {
                        $io->text('Not verified: '.$check);
                    }
                }
                if (\in_array($run['error'] ?? null, ['provider_rate_limited', 'provider_authentication_failed'], true)) {
                    break;
                }
            }
            $report['status'] = [] === array_filter($report['runs'], static fn ($run) => 'complete' !== $run['status']) ? 'complete' : 'failed';
            $report['completedAt'] = gmdate(\DATE_ATOM);
            $save();
            $io->text('Saved '.str_replace($this->projectDir.'/', '', $path));

            return 'complete' === $report['status'] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $error) {
            $report['status'] = 'failed';
            $report['error'] = 'catalog_preflight_failed';
            $report['errorType'] = $error::class;
            $report['completedAt'] = gmdate(\DATE_ATOM);
            $save();
            $io->error('Catalog preflight failed; the attempt is retained.');

            return self::FAILURE;
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }
}
