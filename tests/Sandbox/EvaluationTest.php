<?php

declare(strict_types=1);

namespace App\Tests\Sandbox;

use App\Demo\{EvaluationRunner, EvaluationScenario, EvaluationVerifier};
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EvaluationTest extends KernelTestCase
{
    public static function scenarios(): iterable { foreach (EvaluationScenario::IDS as $id) { yield $id => [$id]; } }

    #[DataProvider('scenarios')]
    public function testRealPhpSharesBoundariesAndProducesLinkedTimeline(string $id): void
    {
        self::bootKernel();
        $run = self::getContainer()->get(EvaluationRunner::class)->run($id, 'code', false);
        self::assertSame('passed', $run['status'], json_encode($run['verification']));
        self::assertNotEmpty($run['trace']['executions']);
        $run['trace']['programs'][0] = 'return "invented evidence";';
        self::assertFalse((new EvaluationVerifier())->verify($run)['passed']);
    }
}
