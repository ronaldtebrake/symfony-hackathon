<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\CodeMode\DemoIdentityProvider;
use App\CodeMode\PublishingAuthorization;
use App\CodeMode\PublishingTools;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\SandboxException;
use CodeMode\Symfony\Ai\ApprovedToolbox;
use CodeMode\Symfony\Ai\SelectToolFields;
use CodeMode\Symfony\CodeModeBundle;
use CodeMode\Symfony\Provider\SymfonyAiProvider;
use CodeMode\Symfony\ReadToolInterface;
use CodeMode\Symfony\SessionFactory;
use CodeMode\ToolDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class SelectToolFieldsTest extends TestCase
{
    public function testConfiguredProviderSelectsFieldsOnAnExistingSymfonyAiTool(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', \dirname(__DIR__, 2).'/var/cache/test');
        $container->register('native.toolbox', Toolbox::class)->setArguments([[new PublishingTools()]]);
        $container->register('native.policy', PublishingAuthorization::class);
        $container->register('native.identity', DemoIdentityProvider::class);
        $container->register('approved', ApprovedToolbox::class)->setArguments([new Reference('native.toolbox'), ['publishing_list'], new Reference('native.policy')]);
        $container->register('app.articles_provider', SymfonyAiProvider::class)->setArguments([
            '$namespace' => 'articles', '$toolbox' => new Reference('approved'), '$methods' => ['page' => 'publishing_list'],
            '$selectableFields' => ['publishing_list' => ['id', 'title', 'meta_description']], '$limits' => new Reference('code_mode.limits'),
        ]);
        (new CodeModeBundle())->getContainerExtension()->load([['identity_provider' => 'native.identity', 'providers' => ['app.articles_provider']]], $container);
        $container->getDefinition(SessionFactory::class)->setPublic(true);
        $container->compile();
        $session = $container->get(SessionFactory::class)->createBatch(new ExecutionIdentity('reader', ['articles.read_published']));
        $this->assertSame(['articles.page'], array_column($session->searchContracts('')['tools'], 'name'));
        $result = $session->respond([['name' => 'articles.page', 'arguments' => ['page' => 1, 'fields' => ['id']]]]);
        $this->assertSame(2, $result['results'][0]['value']->nextPage);
        $this->assertSame(range(1, 12), array_column($result['results'][0]['value']->items, 'id'));
        foreach ($result['results'][0]['value']->items as $row) {
            $this->assertSame(['id'], array_keys(get_object_vars($row)));
        }

        try {
            $session->respond([['name' => 'articles.page', 'arguments' => ['page' => 5, 'fields' => ['id']]]]);
            $this->fail('The original page permission must apply to a field-selected read.');
        } catch (SandboxException $e) {
            $this->assertSame('tool_denied', $e->reason);
        }
    }

    public function testSessionPreservesResourcePolicyRowShapeAndBudget(): void
    {
        $identity = new ExecutionIdentity('reader', ['read']);
        $reader = $this->createMock(ReadToolInterface::class);
        $reader->method('definition')->willReturn($this->definition());
        $reader->expects($this->exactly(2))->method('authorize')->with($identity, (object) ['offset' => 0])->willReturn(true, false);
        $reader->expects($this->once())->method('invoke')->with((object) ['offset' => 0])->willReturn([
            'next_offset' => 12, 'total' => 2, 'items' => [
                ['id' => 1, 'label' => null, 'extra' => 'omitted'],
                ['id' => 2, 'extra' => 'label withheld by reader'],
            ],
        ]);
        $tool = new SelectToolFields($reader, ['id', 'label']);
        $session = (new SessionFactory([$tool]))->createBatch($identity);
        $contracts = $session->searchContracts('');
        $this->assertSame(['id', 'label'], $contracts['tools'][0]['inputSchema']->properties->fields->items->enum);
        $call = [['name' => 'articles', 'arguments' => ['offset' => 0, 'fields' => ['label']]]];
        $value = $session->respond($call)['results'][0]['value'];
        $this->assertSame('{"next_offset":12,"total":2,"items":[{"label":null},{}]}', json_encode($value));

        try {
            $session->respond($call);
            $this->fail('Revoked access permitted a projected read.');
        } catch (SandboxException $e) {
            $this->assertSame('tool_denied', $e->reason);
        }
        $this->assertSame(2, $session->state()['executions']);
        $this->assertSame(2, $session->state()['toolCalls']);
    }

    public function testFullReadAndReaderSchemaArePreserved(): void
    {
        $definition = $this->definition();
        $reader = $this->createMock(ReadToolInterface::class);
        $reader->method('definition')->willReturn($definition);
        $reader->method('invoke')->willReturn(['items' => [['id' => 1, 'other' => 'value']]]);
        $tool = new SelectToolFields($reader, ['id']);
        $this->assertFalse(property_exists($definition->inputSchema->properties, 'fields'));
        $this->assertSame('{"items":[{"id":1,"other":"value"}]}', json_encode($tool->invoke((object) ['offset' => 0], new ExecutionIdentity('reader'))));
    }

    public static function invalidFields(): iterable
    {
        yield ['id'];
        yield [null];
        yield [[]];
        yield [['unknown']];
        yield [['id', 'id']];
        yield [['id.other']];
        yield [[['id']]];
    }

    #[DataProvider('invalidFields')]
    public function testUnsupportedSelectionsFailBeforeTheReaderRuns(mixed $selection): void
    {
        $reader = $this->createMock(ReadToolInterface::class);
        $reader->method('definition')->willReturn($this->definition());
        $reader->expects($this->never())->method('invoke');
        $tool = new SelectToolFields($reader, ['id']);
        $this->expectException(\InvalidArgumentException::class);
        $tool->invoke((object) ['offset' => 0, 'fields' => $selection], new ExecutionIdentity('reader'));
    }

    public static function invalidResults(): iterable
    {
        yield ['unstructured'];
        yield [['items' => (object) ['some' => 'value']]];
        yield [['items' => [['not an object']]]];
    }

    #[DataProvider('invalidResults')]
    public function testUnsupportedResultShapeFailsClosed(mixed $value): void
    {
        $reader = $this->createMock(ReadToolInterface::class);
        $reader->method('definition')->willReturn($this->definition());
        $reader->method('invoke')->willReturn($value);
        $tool = new SelectToolFields($reader, ['id']);
        $this->expectException(\UnexpectedValueException::class);
        $tool->invoke((object) ['offset' => 0, 'fields' => ['id']], new ExecutionIdentity('reader'));
    }

    public function testSelectionCannotHideAnOversizedRawResult(): void
    {
        $limits = new ExecutionLimits(maxToolResultBytes: 256);
        $reader = $this->createMock(ReadToolInterface::class);
        $reader->method('definition')->willReturn($this->definition());
        $reader->method('authorize')->willReturn(true);
        $reader->method('invoke')->willReturn(['items' => [['id' => 1, 'body' => str_repeat('a', 257)]]]);
        $tool = new SelectToolFields($reader, ['id'], $limits);
        $session = (new SessionFactory([$tool], limits: $limits))->createBatch(new ExecutionIdentity('reader'));
        $session->searchContracts('');
        $this->expectException(SandboxException::class);
        $session->respond([['name' => 'articles', 'arguments' => ['offset' => 0, 'fields' => ['id']]]]);
    }

    private function definition(): ToolDefinition
    {
        return new ToolDefinition('articles', 'Read articles by page.', json_decode('{"type":"object","properties":{"offset":{"type":"integer"}},"required":["offset"],"additionalProperties":false}'));
    }
}
