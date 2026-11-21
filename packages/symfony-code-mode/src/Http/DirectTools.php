<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Http;

use CodeMode\Symfony\SessionFactory;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\ListToolsHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/** @implements RequestHandlerInterface<CallToolResult|ListToolsResult> */
final class DirectTools implements LoaderInterface, RequestHandlerInterface
{
    /** @var array<string, ToolReference> */
    private array $entries = [];

    /** @param list<string> $names */
    public function __construct(private readonly array $names, private readonly SessionFactory $sessions, private readonly SecurityIdentityProvider $identities, private readonly CatalogTools $catalogTools, private readonly RegistryInterface $registry, private readonly int $pageSize, private readonly RequestStack $requests)
    {
    }

    public function load(RegistryInterface $registry): void
    {
        $approved = array_column($this->sessions->catalog()->all(), null, 'name');
        foreach ($this->names as $name) {
            $definition = $approved[$name] ?? throw new \LogicException(\sprintf('Direct tool "%s" is outside the approved catalog.', $name));
            if ($registry->hasTool($name) && ($this->entries[$name] ?? null) !== $registry->getTool($name)) {
                throw new \LogicException(\sprintf('Another MCP handler is registered as "%s".', $name));
            }
            $tool = Tool::fromArray(json_decode(json_encode($definition, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR));
            // Register the actual contract for SDK transport checks such as x-mcp-header.
            // Even a dispatch through the registry's callable must use the protected path.
            $this->entries[$name] = $registry->registerTool($tool, fn (...$arguments) => $this->catalogTools->direct($name, $arguments));
        }
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest || ($request instanceof CallToolRequest && \in_array($request->name, $this->names, true));
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        // Another loader must never replace an approved handler, including after server construction.
        foreach ($this->entries as $name => $entry) {
            if ($this->registry->getTool($name) !== $entry) {
                throw new \LogicException(\sprintf('Conflicting MCP registration for direct tool "%s".', $name));
            }
        }
        if ($request instanceof CallToolRequest) {
            if (null === $this->requests->getCurrentRequest()) {
                return Error::forInvalidParams('Unknown tool.', $request->getId());
            }
            // Reserve the shared attempt before checking tool arguments or calling the voter.
            $result = $this->catalogTools->direct($request->name, $request->arguments);
            if (!$result->isError) {
                $version = (new RequestContext($session, $request))->getProtocolVersion();
                $structured = $this->entries[$request->name]->extractStructuredContent($result->structuredContent, $version);
                $result = new CallToolResult($result->content, structuredContent: $structured);
            }

            return new Response($request->getId(), $result);
        }
        \assert($request instanceof ListToolsRequest);
        // The SDK registry can outlive a request. Only this local list belongs to the current user.
        $view = new Registry();
        foreach ($this->registry->getTools() as $tool) {
            if (!isset($this->entries[$tool->name])) {
                $view->registerTool($tool, $this->registry->getTool($tool->name)->handler);
            }
        }
        $visible = null === $this->requests->getCurrentRequest() ? [] : array_column($this->sessions->visibleDirectTools($this->identities->identity(), $this->names)->all(), null, 'name');
        foreach ($this->entries as $name => $entry) {
            if (isset($visible[$name])) {
                $view->registerTool($entry->tool, $entry->handler);
            }
        }

        return (new ListToolsHandler($view, $this->pageSize))->handle($request, $session);
    }
}
