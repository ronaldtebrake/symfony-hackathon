# How the JSON catalog calls Symfony tools

The judge demo uses one HTTP MCP connection with the two default tools, `search_tools` and `execute_tools`. The Symfony integration authenticates the request and obtains the caller from Symfony Security. Applications can also advertise selected readers by name. A direct call skips the discovery round trip and shares the same authorization and budget as a catalog call. [Connect the demo](codex.md) · [Advertise a frequently used tool](../packages/symfony-code-mode/README.md#advertise-a-frequently-used-tool)

## Discovery and execution

Search with `{"query":"refund review"}`. The refund demo returns visible tool definitions and matching group names. Search again with `{"group":"refund-review"}` to read the group's instructions and member contracts. The reusable catalog package supports individual tools. Group discovery is implemented in the demo.

Each invocation of `execute_tools` takes an array of calls with literal JSON arguments. For example, after discovering the order reader, submit

```json
{"calls":[{"name":"refund_get_order","arguments":{"orderId":"R-1042"}}]}
```

The response contains a `results` array in call order. The agent uses the policy ID returned by the order to make its next read. It can submit several independent reads together when it already knows their arguments.

### How this fits Code Mode

The client can write and run a program that calls these same tools over MCP. In the film, Claude opens the refund Group with `search_tools`. Its JavaScript decodes the first `execute_tools` reply, reads the order at `results[0].value`, then passes its `policyId` into the policy reader. Both reads succeed. The order reader declares that field as a required string in its output schema. [See the exact code and four calls](evidence/claude-structured-refund.json)

Symfony enforces the user’s current permissions each time the program calls a reader. A separate run revoked access between reads. The program still had the order it had already received. Symfony denied the policy read before its handler ran, and Claude explained why the review was incomplete. [Revocation trace](evidence/programmatic-refund.json) · [MCP client guidance](https://modelcontextprotocol.io/docs/develop/clients/client-best-practices#combining-both-patterns)

## Access and limits

The broker validates the envelope, reserves the caller's budget and checks current application permissions before each handler. A failed read stops the batch. The error identifies how many earlier calls completed. Their effects persist.

Each caller has a finite budget. The refund fixture allows six submissions and six underlying reads, shared across discovery routes. Refetching the catalog does not reset these counters. The local Profiler shows the broker outcome and Symfony AI handler events. A denied read has no handler invocation.

The JSON path uses ordinary Symfony HTTP hosting. Handlers run in the application process, so hosting and downstream services enforce hard time and memory limits. [Bundle settings and limits](../packages/symfony-code-mode/README.md#http-state-and-limits)
