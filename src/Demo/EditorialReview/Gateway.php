<?php

declare(strict_types=1);

namespace App\Demo\EditorialReview;

use App\Demo\AuditMatrix\Tools;
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
use Mcp\Schema\Result\CallToolResult;
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

/** Direct MCP tools. Every call uses Symfony AI, the application policy and the package broker. */
final class Gateway
{
    public const LIMIT = 20;

    public const NAMES = [PublicationFindings::NAME, 'cms_get_articles', 'cms_get_media'];
    private PublicationFindings $findings;
    private CatalogStateStore $states;
    private CatalogTrace $trace;
    private ToolCatalog $catalog;
    private ToolBroker $broker;

    public function __construct(private readonly string $directory, private readonly SecurityIdentityProvider $identities, Access $access)
    {
        $this->trace = new CatalogTrace();
        $events = new EventDispatcher();
        $events->addSubscriber(new ToolEventSubscriber($this->trace));
        $this->states = new CatalogStateStore(new FilesystemAdapter('editorial', 0, $directory.'/budgets'), new LockFactory(new FlockStore($directory)));
        $this->findings = new PublicationFindings($identities, $access, $this->states);
        $toolbox = new Toolbox([$this->findings, new Tools()], eventDispatcher: $events);
        $approved = (new ApprovedToolbox($toolbox, self::NAMES, $access))->tools();
        $this->catalog = new ToolCatalog(array_map(static fn ($tool) => $tool->definition(), $approved));
        $this->broker = new ToolBroker(array_map(static fn ($tool) => new Tool($tool->definition()->name, $tool->definition()->inputSchema, $tool->authorize(...), $tool->invoke(...)), $approved));
    }

    public function __invoke(Request $request): Response
    {
        $this->trace->reset();
        $this->findings->resetTrace();
        $builder = Server::builder()->setServerInfo('Symfony editorial review', '1.0.0')
            ->setSession(new Server\Session\FileSessionStore($this->directory.'/sessions'))
            ->setSessionLock(new Server\Session\FileSessionLock($this->directory.'/sessions'));
        foreach ($this->catalog->all() as $definition) {
            $name = $definition->name;
            $handler = PublicationFindings::NAME === $name ? fn () => $this->call($name, []) : fn (array $ids) => $this->call($name, ['ids' => $ids]);
            $builder->addTool($handler, name: $name, description: $definition->description, inputSchema: get_object_vars($definition->inputSchema));
        }
        $factory = new Psr17Factory();
        $controller = new McpController($builder->build(), new PsrHttpFactory($factory, $factory, $factory, $factory), new HttpFoundationFactory(), $factory, $factory, new MiddlewareFactory());

        return $controller->handle($request);
    }

    public function trace(): array
    {
        return $this->trace->snapshot() + ['sourceReads' => $this->findings->sourceReads()];
    }

    public static function limits(): ExecutionLimits
    {
        return new ExecutionLimits(wallTimeMs: 15000, toolTimeMs: 3000, maxToolCalls: self::LIMIT);
    }

    private function call(string $name, array $arguments): CallToolResult
    {
        $identity = $this->identities->identity();
        $this->trace->begin($name);
        $started = hrtime(true);
        try {
            $value = $this->states->withState($identity->subject, function (array $state, \Closure $save) use ($identity, $name, $arguments) {
                $this->trace->budget($state, 'before');
                $state = ['described' => self::NAMES, 'executions' => $state['executions'] ?? 0, 'toolCalls' => $state['toolCalls'] ?? 0];
                $session = new BatchSession($this->catalog, $this->broker, $identity, self::limits(), maxBatches: self::LIMIT,
                    maxSessionToolCalls: self::LIMIT, state: $state, checkpoint: function (array $next) use ($save): void {
                        $save($next);
                        $this->trace->budget($next, 'saved');
                    });
                $result = $session->respond([['name' => $name, 'arguments' => $arguments]],
                    fn (array $calls, array $trace) => $this->trace->ledger($trace));

                return (array) $result['results'][0]['value'];
            });
            $failed = false;
        } catch (\Throwable $error) {
            $value = BatchResult::failure($error);
            $failed = true;
        }
        $this->trace->finish($value, $failed, (hrtime(true) - $started) / 1e6);
        $json = json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return new CallToolResult([new TextContent($json)], isError: $failed, structuredContent: $value);
    }
}
