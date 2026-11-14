<?php

declare(strict_types=1);

namespace Composition;

use CodeMode\CodeModeSession;
use CodeMode\DockerEngine;
use CodeMode\ExecutionLimits;
use CodeMode\ExecutionProfile;
use CodeMode\Provider\ProviderRegistry;
use CodeMode\SandboxException;
use CodeMode\Symfony\SessionFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'composition:proof', description: 'Run the independent provider audit and security regression proof in gVisor.')]
final class ProofCommand extends Command
{
    public function __construct(private SessionFactory $factory, private ProviderRegistry $providers, private LocalReader $reader, private DockerEngine $docker, private string $projectDir, private ExecutionLimits $limits, private string $image)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (ExecutionProfile::Gvisor !== $this->factory->profile()) {
            throw new \RuntimeException('This proof requires gVisor.');
        }
        $this->docker->requireGvisor();
        $names = array_column($this->providers->catalog()->all(), 'name');
        $session = $this->factory->create($this->reader->identity());
        if (json_encode($this->providers->catalog()->all()) !== json_encode($this->factory->catalog()->all())) {
            throw new \RuntimeException('Provider catalog changed during setup.');
        }
        $report = ['version' => 1, 'kind' => 'provider-composition', 'createdAt' => gmdate(\DATE_ATOM), 'modelCalled' => false,
            'programOrigin' => 'Operator-written reference PHP; no model request or token comparison.',
            'runtime' => $session->profile()->metadata(), 'image' => $this->image, 'limits' => get_object_vars($this->limits), 'fixtureSha256' => Verifier::fixtureHash(),
            'origins' => $this->providers->origins(), 'contracts' => $session->describe($names),
            'interfaces' => $this->providers->php()->stubs(), 'executions' => []];
        $cases = [
            ['audit', trim(file_get_contents($this->projectDir.'/proof/audit.php.txt'))],
            ['restricted-article', 'return $tools->catalog->getArticles(["ids" => [49]]);'],
            ['attempt-budget', 'return 1;'],
            ['restricted-media', 'return $tools->media->getMedia(["ids" => [25]]);'],
            ['raw-upstream-name', 'return $tools->call("cms_get_media", ["ids" => [1]]);'],
            ['undiscovered-tool', 'return $tools->media->getMedia(["ids" => [1]]);'],
            ['infinite-loop', 'while (true) {}'],
        ];
        foreach ($cases as $index => [$label, $code]) {
            // The first three attempts intentionally share a session. Later cases are independent checks.
            if ($index >= 3) {
                $session = $this->factory->create($this->reader->identity());
                $session->describe('undiscovered-tool' === $label ? ['catalog.listArticles'] : $names);
            }
            $output->writeln('Running '.$label.'…');
            $report['executions'][$label] = $this->capture($session, $code);
        }
        $report['verification'] = (new Verifier())->check($report);
        $passed = !\in_array(false, $report['verification'], true);
        $report['status'] = $passed ? 'complete' : 'failed';
        $dir = $this->projectDir.'/var';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        // Retain captures locally; the small reviewed reference is promoted explicitly after verification.
        $path = $dir.'/proof-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
        $json = json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
        file_put_contents($path, $json, \LOCK_EX);
        file_put_contents($dir.'/latest-proof.json', $json, \LOCK_EX);
        file_put_contents($dir.'/interfaces.php', $report['interfaces'], \LOCK_EX);
        foreach ($report['verification'] as $name => $ok) {
            $output->writeln(($ok ? 'PASS ' : 'FAIL ').$name);
        }
        $output->writeln('Captured '.$path);

        return $passed ? Command::SUCCESS : Command::FAILURE;
    }

    private function capture(CodeModeSession $session, string $code): array
    {
        $start = hrtime(true);
        $trace = ['php' => $code, 'executedPhp' => $session->executionCode($code)];
        $trace['sourceSha256'] = hash('sha256', $trace['executedPhp']);
        try {
            $result = $session->execute($code);
            $trace += ['status' => 'succeeded', 'reason' => null, 'value' => $result->value, 'toolCalls' => $result->toolCalls, 'runId' => $result->runId, 'runtime' => $result->profile->value, 'executionMs' => $result->durationMs, 'outputBytes' => $result->outputBytes];
        } catch (SandboxException $error) {
            $trace += ['status' => 'failed', 'reason' => $error->reason, 'value' => null, 'toolCalls' => $error->toolCalls, 'runId' => $error->runId, 'runtime' => $error->profile->value];
        }
        $trace['elapsedMs'] = round((hrtime(true) - $start) / 1e6, 2);
        $trace['containerRemoved'] = null === $trace['runId'] ? null : '' === $this->docker->control(['ps', '-aq', '--filter', 'name=^/'.$trace['runId'].'$']);

        return json_decode(json_encode($trace, \JSON_THROW_ON_ERROR), true, 64, \JSON_THROW_ON_ERROR);
    }
}
