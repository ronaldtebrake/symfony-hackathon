<?php

declare(strict_types=1);

namespace CodeMode\Symfony\Http;

use CodeMode\BatchResult;
use CodeMode\Symfony\SessionFactory;
use Mcp\Capability\Attribute\{McpTool, Schema};
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/** Normal Symfony services exposed by symfony/mcp-bundle. No subprocess or generated code. */
final readonly class CatalogTools
{
    public function __construct(private SessionFactory $sessions, private SecurityIdentityProvider $identities, private CatalogStateStore $states, private ?\CodeMode\Symfony\Profiler\CatalogTrace $trace = null) {}

    #[McpTool(name: 'search_tools', description: 'Search approved Symfony tools and return their complete contracts. Search never grants permission. Use these names and literal JSON arguments in execute_tools.')]
    #[Schema(type: 'object', properties: ['query' => ['type' => 'string', 'maxLength' => 256]], required: ['query'], additionalProperties: false)]
    public function search(string $query): CallToolResult
    {
        return $this->invoke('search_tools', static fn ($session) => $session->searchContracts($query));
    }

    #[McpTool(name: 'execute_tools', description: 'Call approved tools with literal JSON arguments. No code or expressions. Two execution attempts per authenticated user, including failures, until the operator resets the budget. Calls stop on first error; earlier calls are not rolled back. Submitted calls reserve the call budget before execution. A new MCP connection does not reset it.')]
    #[Schema(type: 'object', properties: ['calls' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100,
        'items' => ['type' => 'object', 'required' => ['name', 'arguments'], 'additionalProperties' => false,
            'properties' => ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-zA-Z][a-zA-Z0-9_.-]*$'], 'arguments' => ['type' => 'object']]]]],
        required: ['calls'], additionalProperties: false)]
    public function execute(array $calls): CallToolResult
    {
        return $this->invoke('execute_tools', fn ($session) => $session->respond($calls, fn (array $_calls, array $trace) => $this->trace?->ledger($trace)));
    }

    private function invoke(string $name, \Closure $operation): CallToolResult
    {
        // Authentication failures stay HTTP security failures, not successful tool results.
        $identity = $this->identities->identity();
        $started = hrtime(true);
        $this->trace?->begin($name);
        try {
            $value = $this->states->withState($identity->subject,
                function (array $state, \Closure $save) use ($operation, $identity) {
                    $this->trace?->budget($state, 'before');
                    $checkpoint = function (array $state) use ($save): void {
                        $save($state);
                        $this->trace?->budget($state, 'saved');
                    };
                    return $operation($this->sessions->createBatch($identity, $state, $checkpoint));
                });
            $failed = false;
        } catch (\Throwable $error) {
            $value = BatchResult::failure($error); $failed = true;
        }
        $this->trace?->finish($value, $failed, (hrtime(true) - $started) / 1_000_000);
        return new CallToolResult([new TextContent(json_encode($value, JSON_THROW_ON_ERROR))], isError: $failed, structuredContent: $value);
    }
}
