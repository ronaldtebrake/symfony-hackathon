<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Command\ReplayConversationCommand;
use App\Demo\DemoFactory;
use App\Demo\ReportStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class ReplayConversationTest extends TestCase
{
    public function testBothRetainedProgramsRunInOneSessionAndProduceVerifiedResults(): void
    {
        $project = \dirname(__DIR__, 2);
        $tester = new CommandTester(new ReplayConversationCommand(new DemoFactory($project), new ReportStore($project)));
        $this->assertSame(0, $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
        $text = $tester->getDisplay();
        $this->assertStringContainsString('48 published articles checked. 10 have findings.', $text);
        $this->assertStringContainsString('4 description edits and 3 image edits.', $text);
        $this->assertSame(2, substr_count($text, '"toolCalls":'));
        preg_match_all('/10 successful broker reads\. Container (code-mode-[a-f0-9]{32}) removed\./', $text, $matches);
        $this->assertCount(2, array_unique($matches[1]));
    }
}
