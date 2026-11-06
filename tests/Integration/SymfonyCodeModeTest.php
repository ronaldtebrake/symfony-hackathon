<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\CodeMode\ListPublishedArticles;
use App\CodeMode\ReadArticle;
use CodeMode\DockerEngine;
use CodeMode\ExecutionIdentity;
use CodeMode\ExecutionLimits;
use CodeMode\SandboxException;
use CodeMode\Symfony\Command\DoctorCommand;
use CodeMode\Symfony\McpServerFactory;
use CodeMode\Symfony\SessionFactory;
use CodeMode\Tool;
use CodeMode\ToolBroker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SymfonyCodeModeTest extends KernelTestCase
{
    public function testBundleApprovesOnlyConfiguredServicesAndCreatesFreshDiscoveryState(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(SessionFactory::class);
        self::assertSame(['articles_read', 'articles_list_published'], array_column($factory->catalog()->all(), 'name'));
        $factory = new SessionFactory([new ReadArticle()], 'sha256:'.str_repeat('a', 64), new DockerEngine());
        $first = $factory->create(new ExecutionIdentity('reader'));
        $second = $factory->create(new ExecutionIdentity('reader'));
        $first->describe(['articles_read']);
        try {
            $second->execute('return 1;');
            self::fail('Discovery state must not cross conversations.');
        } catch (SandboxException $error) {
            self::assertSame('discovery_required', $error->reason);
        }
    }

    public function testDraftMissingAndMalformedReadsAreDeniedBeforeInvocation(): void
    {
        $service = new ReadArticle();
        $invocations = 0;
        $broker = new ToolBroker([new Tool($service->definition()->name, $service->definition()->inputSchema, $service->authorize(...), static function () use (&$invocations) { ++$invocations; return 'should not run'; })]);
        foreach ([(object) ['id' => 49], (object) ['id' => 999], (object) ['id' => []], (object) []] as $arguments) {
            try {
                $broker->call('articles_read', $arguments, new ExecutionIdentity('reader', ['articles.read_published']), ['articles_read']);
                self::fail('Forbidden read succeeded.');
            } catch (SandboxException $error) {
                self::assertSame('tool_denied', $error->reason);
            }
        }
        self::assertSame(0, $invocations);
        self::assertFalse($service->authorize(new ExecutionIdentity('unprivileged'), (object) ['id' => 1]));
        self::assertTrue($service->authorize(new ExecutionIdentity('reader', ['articles.read_published']), (object) ['id' => 1]));
    }

    public function testPublishedAlternativeCannotExpandScope(): void
    {
        $service = new ListPublishedArticles();
        $definition = $service->definition();
        $broker = new ToolBroker([new Tool($definition->name, $definition->inputSchema, $service->authorize(...), $service->invoke(...))]);
        $identity = new ExecutionIdentity('reader', ['articles.read_published']);
        $ids = [];
        $page = 1;
        do {
            $result = $broker->call($definition->name, (object) ['page' => $page], $identity, [$definition->name]);
            foreach ($result['items'] as $item) {
                self::assertSame(['id', 'title', 'meta_description'], array_keys($item));
                $ids[] = $item['id'];
            }
            $page = $result['nextPage'];
        } while (null !== $page);
        self::assertSame(range(1, 48), $ids);
        try {
            $broker->call($definition->name, (object) ['page' => 1, 'status' => 'draft'], $identity, [$definition->name]);
            self::fail('Caller expanded the schema.');
        } catch (SandboxException $error) {
            self::assertSame('invalid_arguments', $error->reason);
        }
    }

    public function testMcpDenialReturnsActionableReasonWithoutTraceOrExceptionSecrets(): void
    {
        $factory = new McpServerFactory();
        $calls = [['name' => 'articles_read', 'status' => 'denied', 'reason' => 'tool_denied']];
        $result = $factory->result(static fn () => throw new SandboxException('tool_denied', 'secret-path-and-token', $calls, runId: 'code-mode-'.str_repeat('a', 32)));
        self::assertTrue($result->isError);
        self::assertSame('tool_denied', $result->structuredContent['error']['reason']);
        self::assertSame(['error' => ['reason' => 'tool_denied', 'tool' => 'articles_read']], $result->structuredContent);
        self::assertSame($result->structuredContent, json_decode($result->content[0]->text, true));
        self::assertStringNotContainsString('secret-path-and-token', json_encode($result));
        $unexpected = $factory->result(static fn () => throw new \RuntimeException('private database error'));
        self::assertSame(['error' => ['reason' => 'tool_failed']], $unexpected->structuredContent);
    }

    public function testDoctorFailsClearlyWithoutRuntimeAndDoesNotInvokeTools(): void
    {
        $docker = new DockerEngine(['/nonexistent/code-mode-docker']);
        $sessions = new SessionFactory([new ReadArticle()], '', $docker);
        $tester = new CommandTester(new DoctorCommand('', $docker, $sessions, new ExecutionLimits()));
        self::assertSame(1, $tester->execute(['--json' => true]));
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($report['ready']);
        self::assertCount(6, $report['checks']);
        self::assertSame(['Runner image pin', 'gVisor runtime', 'Runner image available'], array_column(array_filter($report['checks'], static fn ($check) => !$check['passed']), 'name'));
        self::assertSame(2, $report['limits']['executionAttempts']);
        self::assertStringNotContainsString('/nonexistent', $tester->getDisplay());
    }
}
