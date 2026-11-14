<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Maker;

use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\Exception\RuntimeCommandException;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Bundle\MakerBundle\Str;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

final class MakeCodeModeTool extends AbstractMaker
{
    public function __construct(private readonly ?string $registrationError = null)
    {
    }

    public static function getCommandName(): string
    {
        return 'make:code-mode-tool';
    }

    public static function getCommandDescription(): string
    {
        return 'Generate a native read tool, deny-by-default voter, explicit catalog registration and security test';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConfig): void
    {
        $command->addArgument('name', InputArgument::REQUIRED, 'Tool class name, for example InventoryLookup');
    }

    public function configureDependencies(DependencyBuilder $dependencies): void
    {
        $dependencies->addClassDependency(\Symfony\AI\Agent\Toolbox\Attribute\AsTool::class, 'symfony/ai-agent');
        $dependencies->addClassDependency(\Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool::class, 'symfony/ai-bundle');
        $dependencies->addClassDependency(\Symfony\Bundle\SecurityBundle\SecurityBundle::class, 'symfony/security-bundle');
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        if (null !== $this->registrationError) {
            throw new RuntimeCommandException($this->registrationError.' No files were written.');
        }
        $name = $input->getArgument('name');
        if (!\is_string($name) || !preg_match('/\A[A-Z][A-Za-z0-9]{0,47}\z/D', $name)) {
            throw new RuntimeCommandException('Use a simple PascalCase name of at most 48 characters.');
        }
        $base = preg_replace('/Tool$/', '', $name);
        if ('' === $base) {
            throw new RuntimeCommandException('Choose a descriptive name before Tool.');
        }
        $tool = $generator->createClassNameDetails($base, 'Tool\\', 'Tool');
        $voter = $generator->createClassNameDetails($base, 'Security\\', 'Voter');
        $toolName = Str::asSnakeCase($base);
        if (\strlen($toolName) > 64) {
            throw new RuntimeCommandException('The resulting tool name exceeds 64 characters.');
        }
        $vars = ['tool_name' => $toolName, 'tool_class' => $tool->getFullName(), 'voter_class' => $voter->getFullName(),
            'voter_short' => $voter->getShortName(), 'attribute' => strtoupper($toolName), 'root_namespace' => $generator->getRootNamespace()];
        $templates = __DIR__.'/templates/';
        $generator->generateClass($tool->getFullName(), $templates.'Tool.tpl.php', $vars);
        $generator->generateClass($voter->getFullName(), $templates.'Voter.tpl.php', $vars);
        $generator->generateFile('config/packages/code_mode_'.$toolName.'.php', $templates.'Config.tpl.php', $vars);
        $generator->generateFile('tests/Tool/'.$voter->getShortName().'Test.php', $templates.'Test.tpl.php', $vars);
        // Maker refuses existing targets; generation never replaces application policy or authentication.
        $generator->writeChanges();
        $this->writeSuccessMessage($io);
        $io->text('Implement the read and the two voter decisions. The generated tool is hidden and denied until then.');
        $io->text('Review the explicit registration. Existing custom authorization/visibility policies remain in force and must also admit this tool.');
        $io->text('Use ai_tool_services for the generated registration; a custom ai_toolbox needs manual wiring instead.');
        $io->text('Enable AiBundle and keep your existing HTTP authenticator. Run the generated test and code-mode:doctor.');
    }
}
