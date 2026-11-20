<?php

declare(strict_types=1);

namespace App\Demo\Refund;

use App\Demo\Groups\GetGroupRequest;
use App\Demo\Groups\GroupDiscoveryResult;
use App\Demo\Groups\ListGroupsRequest;
use App\Demo\Groups\PublishingGroups;
use CodeMode\ToolDefinition;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Extension\ExtensionIdentifier;
use Mcp\Schema\Extension\ExtensionInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListResourcesRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/** @implements RequestHandlerInterface<ResultInterface> */
final readonly class Group implements ExtensionInterface, RequestHandlerInterface
{
    public const ENTRY = ['name' => 'refund-review', 'title' => 'Refund review', 'description' => 'Review an order against its refund policy.'];

    public function __construct(private string $mode, private \Closure $visible, private \Closure $definitions, private \Closure $read)
    {
    }

    public function getId(): ExtensionIdentifier
    {
        return new ExtensionIdentifier(PublishingGroups::ID);
    }

    public function getCapabilities(): array
    {
        return [];
    }

    public function getMessages(): array
    {
        return [ListGroupsRequest::class, GetGroupRequest::class];
    }

    public function getRequestHandlers(): iterable
    {
        yield $this;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest || $request instanceof ListResourcesRequest || $request instanceof ListGroupsRequest || $request instanceof GetGroupRequest
            || ($request instanceof CallToolRequest && \in_array($request->name, Tools::NAMES, true));
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        $id = $request->getId();
        if ($request instanceof CallToolRequest) {
            return new Response($id, ($this->read)($request->name, $request->arguments));
        }
        if ($request instanceof ListResourcesRequest) {
            // The only resource on this demo server is scoped to its group. The Skills registry
            // still serves skills/list, skills/get and direct authorized resources/read calls.
            return null === $request->cursor ? new Response($id, new ListResourcesResult([])) : Error::forInvalidParams('Unknown cursor.', $id);
        }

        $extensions = (new RequestContext($session, $request))->getClientCapabilities()?->extensions;
        if ($request instanceof ListToolsRequest) {
            if (null !== $request->cursor) {
                return Error::forInvalidParams('Unknown cursor.', $id);
            }
            // Only clients that declare Groups on this request get scoped placement.
            $definitions = !isset($extensions[PublishingGroups::ID]) && ($this->visible)() ? ($this->definitions)() : [];
            $tools = array_map(static fn (ToolDefinition $tool): Tool => new Tool($tool->name, null, (array) $tool->inputSchema,
                $tool->description, null, outputSchema: null === $tool->outputSchema ? null : (array) $tool->outputSchema), $definitions);

            return new Response($id, new ListToolsResult($tools));
        }
        if (!isset($extensions[PublishingGroups::ID])) {
            return Error::forMissingRequiredClientCapability('Declare support for Groups.', new ClientCapabilities(extensions: [PublishingGroups::ID => []]), $id);
        }
        if ($request instanceof ListGroupsRequest) {
            if (null !== $request->cursor) {
                return Error::forInvalidParams('Unknown cursor.', $id);
            }

            return new Response($id, new GroupDiscoveryResult(['groups' => ($this->visible)() ? [self::ENTRY] : []]));
        }
        if (!$request instanceof GetGroupRequest || self::ENTRY['name'] !== $request->name || !($this->visible)()) {
            return Error::forResourceNotFound('Group not found.', $id);
        }

        $group = ['tools' => ($this->definitions)()];
        if ('skill' === $this->mode) {
            $group['resources'] = [new ResourceDefinition(Fixture::URI, 'Refund review checklist', mimeType: 'text/markdown')];
        } elseif ('inline' === $this->mode) {
            $group['instructions'] = Fixture::instructions();
        }

        return new Response($id, new GroupDiscoveryResult($group));
    }
}
