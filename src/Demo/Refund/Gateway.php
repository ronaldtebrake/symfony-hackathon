<?php

declare(strict_types=1);

namespace App\Demo\Refund;

use App\Demo\GroupSelection\Discovery;
use CodeMode\BatchResult;
use CodeMode\BatchSession;
use CodeMode\ExecutionLimits;
use CodeMode\Symfony\Ai\ApprovedToolbox;
use CodeMode\Symfony\Http\CatalogStateStore;
use CodeMode\Symfony\Http\SecurityIdentityProvider;
use CodeMode\Symfony\Profiler\CatalogTrace;
use CodeMode\Symfony\Profiler\ToolEventSubscriber;
use CodeMode\Tool;
use CodeMode\ToolBroker;
use CodeMode\ToolCatalog;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\Wire\McpHeader;
use Mcp\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\McpBundle\Controller\McpController;
use Symfony\AI\McpBundle\Http\MiddlewareFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final readonly class Gateway
{
    public const LIMIT = 6;
    private CatalogStateStore $states;
    private ToolCatalog $catalog;
    private ToolBroker $broker;

    public function __construct(private string $directory, private string $mode, private SecurityIdentityProvider $identities, private Access $access, private CatalogTrace $trace)
    {
        $events = new EventDispatcher();
        $events->addSubscriber(new ToolEventSubscriber($this->trace));
        $approved = (new ApprovedToolbox(new Toolbox([new Tools()], eventDispatcher: $events), Tools::NAMES, $access))->tools();
        $this->catalog = new ToolCatalog(array_map(static fn ($tool) => $tool->definition(), $approved));
        $this->broker = new ToolBroker(array_map(static fn ($tool) => new Tool($tool->definition()->name, $tool->definition()->inputSchema, $tool->authorize(...), $tool->invoke(...)), $approved));
        $this->states = new CatalogStateStore(new FilesystemAdapter('refund', 0, $directory.'/budgets'), new LockFactory(new FlockStore($directory)));
    }

    public function __invoke(Request $request): Response
    {
        $this->trace->reset();
        foreach ($this->catalog->all() as $definition) {
            $this->trace->registerLocalContract($definition);
        }
        $builder = Server::builder()->setServerInfo('symfony_code_mode', '1.0.0', title: 'Symfony Code Mode')->setInstructions('Read-only refund review of synthetic orders.')
            ->setSession(new Server\Session\FileSessionStore($this->directory.'/sessions'))->setSessionLock(new Server\Session\FileSessionLock($this->directory.'/sessions'))
            ->setCapabilities(new ServerCapabilities(tools: true));
        $visible = fn () => $this->access->canReview($this->identities->identity());
        $discovery = \in_array($this->mode, ['selection', 'catalog'], true)
            ? new Discovery($visible, $this->catalog, $this->read(...), $this->broker, $this->identities, $this->states, $this->trace, recordingCatalog: 'catalog' === $this->mode)
            : new Group($this->mode, $visible, $this->catalog->all(...), $this->read(...));
        if (ProtocolVersion::V2026_07_28->value === $request->headers->get(McpHeader::PROTOCOL_VERSION)) {
            $builder->setCapabilities(new ServerCapabilities(tools: true, resources: true))->enableExtension($discovery);
            // Build a new SDK file registry on every request, including direct reads of a known URI.
            $this->registerSkills($builder, 'skill' === $this->mode && $visible() ? __DIR__.'/skills' : $this->directory.'/empty-skills');
        } else {
            // Handshake clients use core tools/list and tools/call on the same endpoint.
            $builder->addRequestHandler($discovery);
        }
        $factory = new Psr17Factory();
        $controller = new McpController($builder->build(), new PsrHttpFactory($factory, $factory, $factory, $factory), new HttpFoundationFactory(), $factory, $factory, new MiddlewareFactory());
        $response = $controller->handle($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setVary('Authorization', false);

        return $response;
    }

    public function trace(): array
    {
        return $this->trace->snapshot();
    }

    /** The disposable HTTP process loads the Skills draft, the application keeps the released builder. */
    private function registerSkills(object $builder, string $directory): void
    {
        if (!method_exists($builder, 'addSkillsFromDirectory')) {
            if ('skill' === $this->mode) {
                throw new \RuntimeException('The linked skill requires the pinned Skills SDK.');
            }

            return;
        }
        $builder->addSkillsFromDirectory($directory);
    }

    private function read(string $name, array $arguments): CallToolResult
    {
        $this->trace->begin($name);
        $start = hrtime(true);
        $identity = $this->identities->identity();
        try {
            $result = $this->states->withState($identity->subject, function (array $state, \Closure $save) use ($identity, $name, $arguments): array {
                // Native tools/call has no discovery prerequisite. Authorization still runs inside the broker.
                $this->trace->budget($state, 'before');
                $catalogDiscovery = $state['described'] ?? [];
                $state = ['described' => [$name], 'executions' => $state['executions'] ?? 0, 'toolCalls' => $state['toolCalls'] ?? 0];
                $session = new BatchSession($this->catalog, $this->broker, $identity, new ExecutionLimits(), maxBatches: self::LIMIT, maxSessionToolCalls: self::LIMIT, state: $state, checkpoint: function (array $next) use ($save, $catalogDiscovery): void {
                    // Share the reservation without changing what search_tools has disclosed.
                    $next['described'] = $catalogDiscovery;
                    $save($next);
                    $this->trace->budget($next, 'saved');
                });
                $result = $session->respond([['name' => $name, 'arguments' => $arguments]], fn (array $calls, array $trace) => $this->trace->ledger($trace));

                return (array) $result['results'][0]['value'];
            });
            $failed = false;
        } catch (\Throwable $failure) {
            $result = BatchResult::failure($failure);
            $failed = true;
        }
        $this->trace->finish($result, $failed, (hrtime(true) - $start) / 1e6);

        return new CallToolResult([new TextContent(json_encode($result, \JSON_THROW_ON_ERROR))], isError: $failed, structuredContent: $result);
    }
}
