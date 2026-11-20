<?php

declare(strict_types=1);

namespace App\Demo\Groups;

use App\Demo\AuditMatrix\Tools;
use CodeMode\ToolDefinition;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Extension\ExtensionIdentifier;
use Mcp\Schema\Extension\ExtensionInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * Opt-in implementation of progressive-disclosure-wg#19 at dbbb549.
 *
 * @implements RequestHandlerInterface<ResultInterface>
 */
final readonly class PublishingGroups implements ExtensionInterface, RequestHandlerInterface
{
    public const ID = 'io.modelcontextprotocol/groups';
    private const GROUP = ['name' => 'publishing', 'title' => 'Publishing',
        'description' => 'Check published articles and their images for editorial and accessibility issues.'];

    /**
     * @param \Closure(): list<ToolDefinition>        $visibleReaders
     * @param \Closure(string, array): CallToolResult $call
     */
    public function __construct(private \Closure $visibleReaders, private \Closure $call)
    {
    }

    public function getId(): ExtensionIdentifier
    {
        return new ExtensionIdentifier(self::ID);
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
        return $request instanceof ListGroupsRequest || $request instanceof GetGroupRequest
            || ($request instanceof CallToolRequest && \in_array($request->name, Tools::NAMES, true));
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        $id = $request->getId();
        if ($request instanceof CallToolRequest) {
            // tools/call is a core method. Knowing a scoped name never confers access.
            return new Response($id, ($this->call)($request->name, $request->arguments));
        }

        $declared = (new RequestContext($session, $request))->getClientCapabilities()?->extensions;
        if (!isset($declared[self::ID])) {
            return Error::forMissingRequiredClientCapability('Declare support for the Groups extension on this request.',
                new ClientCapabilities(extensions: [self::ID => []]), $id);
        }

        $tools = ($this->visibleReaders)();
        if ($request instanceof ListGroupsRequest) {
            if (null !== $request->cursor) {
                return Error::forInvalidParams('Unknown cursor.', $id);
            }

            return new Response($id, new GroupDiscoveryResult(['groups' => [] === $tools ? [] : [self::GROUP]]));
        }

        if (!$request instanceof GetGroupRequest || self::GROUP['name'] !== $request->name || [] === $tools) {
            return Error::forResourceNotFound('Group not found.', $id);
        }

        return new Response($id, new GroupDiscoveryResult(['tools' => $tools]));
    }
}
