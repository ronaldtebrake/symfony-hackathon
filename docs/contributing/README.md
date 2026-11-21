# What we can contribute

Three small pieces can stand on their own. They are candidates for upstream discussion.

- **Symfony AI.** An example of searching an application's visible tool definitions, opening a team procedure, and dispatching its readers through the Symfony AI Toolbox. The application checks access again when the agent invokes a reader. [Demo implementation](../../src/Demo/GroupSelection/Discovery.php) · [Symfony AI discovery discussion](https://github.com/symfony/ai/issues/2623)
- **PHP MCP SDK.** An interoperability test in which a standard client and a client using the draft Groups methods get the same tool definitions on the same authenticated endpoint. Both share one execution budget and respect a revoked grant. [Two-client test](../../tests/Integration/RefundFallbackTest.php)
- **MCP working group.** A working example of a group returning a procedure alongside its readers. It distinguishes the group description used for discovery from the instructions used after opening. Our separate Skills experiment tests what happens to a linked guide when access changes. [Groups proposal](https://github.com/modelcontextprotocol/progressive-disclosure-wg/pull/19) · [Skills discussion](https://github.com/modelcontextprotocol/progressive-disclosure-wg/pull/22) · [Protocol evidence](../evidence/refund-protocol.json)

The Claude runs suggest a concrete test for tool authors. Can a client take a declared result field and pass it straight into the next tool? The first two scripts used a regular expression. The third used the field after the result path moved into the short discovery preview. [Compare the previews and programs](../evidence/claude-structured-refund.json)

Next we would test how well an agent chooses between similarly named groups in a larger application. The startup measurement counts advertised definitions. Measuring the cost of a full task would need matched conversations, correct answers and provider-reported usage.
