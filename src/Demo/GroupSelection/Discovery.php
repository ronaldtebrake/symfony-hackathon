<?php

declare(strict_types=1);

namespace App\Demo\GroupSelection;

use App\Demo\Groups\GetGroupRequest;
use App\Demo\Groups\GroupDiscoveryResult;
use App\Demo\Groups\ListGroupsRequest;
use App\Demo\Groups\PublishingGroups;
use App\Demo\Refund\Fixture;
use App\Demo\Refund\Gateway;
use App\Demo\Refund\Tools;
use CodeMode\BatchResult;
use CodeMode\BatchSession;
use CodeMode\ExecutionIdentity;
use CodeMode\SandboxException;
use CodeMode\Symfony\Ai\Search\SymfonyCatalogSearch;
use CodeMode\Symfony\Http\CatalogStateStore;
use CodeMode\Symfony\Http\SecurityIdentityProvider;
use CodeMode\Symfony\Profiler\CatalogTrace;
use CodeMode\ToolBroker;
use CodeMode\ToolCatalog;
use CodeMode\ToolDefinition;
use CodeMode\ToolVisibilityInterface;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Extension\ExtensionIdentifier;
use Mcp\Schema\Extension\ExtensionInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/** A single fixture offers two discovery routes into the same authenticated refund handlers.
 * @implements RequestHandlerInterface<ResultInterface>
 */
final readonly class Discovery implements ExtensionInterface, RequestHandlerInterface, ToolVisibilityInterface
{
    public const GROUPS = [
        'order-lookup' => ['description' => 'Look up delivery, item condition and payment details for one order.', 'members' => ['refund_get_order']],
        'policy-lookup' => ['description' => 'Look up return terms and thresholds for a named policy.', 'members' => ['refund_get_policy']],
        'refund-review' => ['description' => 'Review an order together with its applicable refund policy.', 'members' => Tools::NAMES],
    ];
    public const CATALOG = ['search_tools', 'execute_tools'];

    private ToolCatalog $catalog;
    private ToolCatalog $groupIndex;

    public function __construct(private \Closure $visible, ToolCatalog $catalog, private \Closure $read, private ToolBroker $broker, private SecurityIdentityProvider $identities, private CatalogStateStore $states, private CatalogTrace $trace, private bool $recordingCatalog)
    {
        $search = new SymfonyCatalogSearch();
        $this->catalog = new ToolCatalog($catalog->all(), $search);
        // Search the public labels using the same ranker. Group entries never enter the tool broker.
        $labels = [];
        foreach (self::GROUPS as $name => $group) {
            $labels[] = new ToolDefinition($name, $group['description'], (object) ['type' => 'object']);
        }
        $this->groupIndex = new ToolCatalog($labels, $search);
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
        return $request instanceof ListGroupsRequest || $request instanceof GetGroupRequest || $request instanceof ListToolsRequest
            || ($request instanceof CallToolRequest && \in_array($request->name, [...self::CATALOG, ...Tools::NAMES], true));
    }

    public function isVisible(ExecutionIdentity $identity, string $tool): bool
    {
        return \in_array($tool, Tools::NAMES, true) && ($this->visible)();
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        $id = $request->getId();
        $groups = isset((new RequestContext($session, $request))->getClientCapabilities()?->extensions[PublishingGroups::ID]);
        if ($request instanceof ListToolsRequest) {
            $definitions = $this->session($this->identities->identity())->catalogToolDefinitions();
            if ($this->recordingCatalog) {
                $definitions[0] = $this->groupSearchDefinition($definitions[0]);
            }
            foreach ($definitions as $definition) {
                $this->trace->registerLocalContract($definition);
            }
            if (!$groups && !$this->recordingCatalog && ($this->visible)()) {
                array_push($definitions, ...$this->catalog->all());
            }

            return null === $request->cursor ? new Response($id, new GroupDiscoveryResult(['tools' => $definitions])) : Error::forInvalidParams('Unknown cursor.', $id);
        }
        if ($request instanceof CallToolRequest) {
            $result = \in_array($request->name, self::CATALOG, true) ? $this->catalogCall($request->name, $request->arguments) : ($this->read)($request->name, $request->arguments);

            return new Response($id, $result);
        }
        if (!$groups) {
            return Error::forMissingRequiredClientCapability('Declare support for Groups.', new ClientCapabilities(extensions: [PublishingGroups::ID => []]), $id);
        }
        if ($request instanceof ListGroupsRequest) {
            return null === $request->cursor ? new Response($id, new GroupDiscoveryResult(['groups' => $this->groupSummaries('')])) : Error::forInvalidParams('Unknown cursor.', $id);
        }
        $group = $request instanceof GetGroupRequest ? $this->visibleGroup($request->name, $this->catalog->describe(...)) : null;
        if (null === $group) {
            return Error::forResourceNotFound('Group not found.', $id);
        }

        return new Response($id, new GroupDiscoveryResult($group));
    }

    private function groupSearchDefinition(ToolDefinition $search): ToolDefinition
    {
        return new ToolDefinition($search->name, 'Search with query to find individual tool contracts and named group summaries. An empty query browses both. To open a group, send its name in the group field alone. This returns its member contracts and any procedure. Run the tools with execute_tools. For dependent reads, pass the ID from the earlier result.', (object) [
            'type' => 'object',
            'properties' => (object) ['query' => $search->inputSchema->properties->query, 'group' => (object) ['type' => 'string', 'minLength' => 1, 'maxLength' => 64]],
            'minProperties' => 1, 'maxProperties' => 1, 'additionalProperties' => false,
        ]);
    }

    private function groupSummaries(string $query): array
    {
        if (!($this->visible)()) {
            return [];
        }
        $matches = $this->groupIndex->searchContracts($query)['tools'];

        return array_map(static fn (array $match): array => ['name' => $match['name'], 'description' => $match['description']], $matches);
    }

    private function visibleGroup(string $name, \Closure $describe): ?array
    {
        if (!isset(self::GROUPS[$name]) || !($this->visible)()) {
            return null;
        }
        $guide = [];
        if ($this->recordingCatalog && 'refund-review' === $name) {
            $guide['instructions'] = Fixture::instructions();
        }

        return ['tools' => $describe(self::GROUPS[$name]['members'])] + $guide;
    }

    private function session(ExecutionIdentity $identity, array $state = [], ?\Closure $save = null): BatchSession
    {
        return new BatchSession($this->catalog, $this->broker, $identity, maxBatches: Gateway::LIMIT, maxSessionToolCalls: Gateway::LIMIT, state: $state, checkpoint: $save, visibility: $this);
    }

    private function search(BatchSession $batch, array $args): array
    {
        if (['query'] === array_keys($args) && \is_string($args['query'])) {
            if (!$this->recordingCatalog) {
                return $batch->searchContracts($args['query']);
            }
            $groups = $this->groupSummaries($args['query']);
            $result = $batch->searchContracts($args['query']);
            $this->trace->catalogGroups($groups);

            return $result + ['groups' => $groups];
        }
        if ($this->recordingCatalog && ['group'] === array_keys($args) && \is_string($args['group'])
            && '' !== $args['group'] && mb_strlen($args['group'], 'UTF-8') <= 64) {
            $group = $this->visibleGroup($args['group'], $batch->describe(...));
            if (null === $group) {
                throw new SandboxException('tool_denied', 'Group unavailable.');
            }
            $this->trace->catalogGroups([], $args['group'], $group['instructions'] ?? null);

            return ['group' => $args['group']] + $group;
        }
        throw new \InvalidArgumentException('Invalid catalog search arguments.');
    }

    private function catalogCall(string $name, array $args): CallToolResult
    {
        $identity = $this->identities->identity();
        $this->trace->begin($name);
        $start = hrtime(true);
        try {
            $result = $this->states->withState($identity->subject, function (array $state, \Closure $save) use ($identity, $name, $args): array {
                $this->trace->budget($state, 'before');
                $batch = $this->session($identity, $state, function (array $next) use ($save): void {
                    $save($next);
                    $this->trace->budget($next, 'saved');
                });
                if ('search_tools' === $name) {
                    return $this->search($batch, $args);
                }
                if ('execute_tools' === $name && ['calls'] === array_keys($args) && \is_array($args['calls'])) {
                    return $batch->respond($args['calls'], fn (array $calls, array $trace) => $this->trace->ledger($trace));
                }
                throw new \InvalidArgumentException('Invalid catalog arguments.');
            });
            $failed = false;
        } catch (\Throwable $error) {
            $result = BatchResult::failure($error);
            $failed = true;
        }
        $this->trace->finish($result, $failed, (hrtime(true) - $start) / 1e6);

        return new CallToolResult([new TextContent(json_encode($result, \JSON_THROW_ON_ERROR))], isError: $failed, structuredContent: $result);
    }
}
