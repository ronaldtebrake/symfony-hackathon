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
    public function __construct(private SessionFactory $sessions, private SecurityIdentityProvider $identities, private CatalogStateStore $states) {}

    #[McpTool(name: 'search_tools', description: 'Search approved Symfony tools and return their complete contracts. Search never grants permission. Use these names and literal JSON arguments in execute_tools.')]
    #[Schema(type: 'object', properties: ['query' => ['type' => 'string', 'maxLength' => 256]], required: ['query'], additionalProperties: false)]
    public function search(string $query): CallToolResult
    {
        return $this->invoke(static fn ($session) => $session->searchContracts($query));
    }

    #[McpTool(name: 'execute_tools', description: 'Call approved tools with literal JSON arguments. No code or expressions. Two execution attempts per authenticated user, including failures, until the operator resets the budget. Calls stop on first error; earlier calls are not rolled back. Submitted calls reserve the call budget before execution. A new MCP connection does not reset it.')]
    #[Schema(type: 'object', properties: ['calls' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100,
        'items' => ['type' => 'object', 'required' => ['name', 'arguments'], 'additionalProperties' => false,
            'properties' => ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-zA-Z][a-zA-Z0-9_.-]*$'], 'arguments' => ['type' => 'object']]]]],
        required: ['calls'], additionalProperties: false)]
    public function execute(array $calls): CallToolResult
    {
        return $this->invoke(static fn ($session) => $session->respond($calls));
    }

    private function invoke(\Closure $operation): CallToolResult
    {
        // Authentication failures stay HTTP security failures, not successful tool results.
        $identity = $this->identities->identity();
        try {
            $value = $this->states->withState($identity->subject,
                fn (array $state, \Closure $save) => $operation($this->sessions->createBatch($identity, $state, $save)));
            $failed = false;
        } catch (\Throwable $error) {
            $value = BatchResult::failure($error); $failed = true;
        }
        return new CallToolResult([new TextContent(json_encode($value, JSON_THROW_ON_ERROR))], isError: $failed, structuredContent: $value);
    }
}
