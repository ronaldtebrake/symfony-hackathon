<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\BackendFactory;
use App\Demo\DemoFactory;
use App\Demo\EditorialReplay;
use App\Demo\ReportStore;
use CodeMode\DockerEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'code-mode:replay-conversation', description: 'Execute the two recorded PHP submissions in gVisor and verify both answers against the Symfony fixture.')]
final class ReplayConversationCommand extends Command
{
    public function __construct(private readonly DemoFactory $factory, private readonly ReportStore $store)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Symfony Code Mode · replay the conversation');
        $io->text('Two recorded agent programs. Fresh gVisor executions against the Symfony MCP fixture. No model call.');
        $replay = new EditorialReplay();
        $programs = $replay->programs($this->store);
        $expected = $replay->expectedAnswers();
        $session = $this->factory->create()->session;
        $tools = array_column($session->searchResult('cms')['tools'], 'name');
        if (\count(BackendFactory::FIXTURE_TOOLS) !== \count($tools) || array_diff(BackendFactory::FIXTURE_TOOLS, $tools)) {
            throw new \RuntimeException('Expected the three approved fixture readers.');
        }
        $session->describe($tools);
        $io->text('Discovered and described '.implode(', ', $tools).'.');

        $docker = new DockerEngine(json_decode(BackendFactory::env('CODE_MODE_DOCKER_COMMAND', '["docker"]'), true, flags: \JSON_THROW_ON_ERROR));
        foreach ($programs as $turn => $code) {
            $io->section(0 === $turn ? 'Which published articles need attention?' : 'Which edits would fix those findings?');
            if ($output->isVerbose()) {
                $output->writeln($code, OutputInterface::OUTPUT_RAW);
            }
            $result = $session->execute($code);
            if (!preg_match('/\Acode-mode-[a-f0-9]{32}\z/D', $result->runId) || '' !== $docker->control(['ps', '-aq', '--filter', 'name=^/'.$result->runId.'$'])) {
                throw new \RuntimeException('Could not confirm that the execution container was removed.');
            }
            $answer = json_decode(json_encode($result->value, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
            $reads = array_count_values(array_column($result->toolCalls, 'name'));
            if ($answer !== $expected[$turn]
                || array_filter($result->toolCalls, static fn ($call) => 'succeeded' !== $call['status'])
                || ['cms_list_articles' => 4, 'cms_get_articles' => 4, 'cms_get_media' => 2] !== $reads) {
                throw new \RuntimeException('The returned answer or the host-recorded reads differ from the fixture.');
            }
            if ($output->isVerbose()) {
                $output->writeln(json_encode(['answer' => $answer, 'toolCalls' => $result->toolCalls], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            }
            $io->text(0 === $turn
                ? \sprintf('%d published articles checked. %d have findings.', $answer['total_audited'], $answer['affected_count'])
                : \sprintf('%d description edits and %d image edits. The image edits cover %d articles.', $answer['meta_description_fix_count'], $answer['hero_image_alt_fix_count'], $answer['hero_image_alt_affected_article_count']));
            $io->text(\sprintf('%d successful broker reads. Container %s removed.', \count($result->toolCalls), $result->runId));
        }
        $io->success('Both answers matched the independent fixture. Use -v to inspect the submitted PHP, JSON answers and broker calls.');

        return Command::SUCCESS;
    }
}
