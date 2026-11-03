# Contributions grounded in this demo

These are **local, reviewable proposals**, not submitted or accepted upstream changes. The [hackathon brief](https://symfony.com/hackathon) explicitly values useful feedback from real projects. The generic isolation layer remains our package; Symfony and the PHP MCP SDK supply the application/agent integration and protocol.

## PHP MCP SDK: a structured-result factory

**Problem:** our successful executions and denied reads both carry structured JSON. We also supply equivalent `TextContent` for clients that only read `content`. The SDK's constructor supports this already, but `success()` and `error()` do not carry structured data; every integration writes the same assembly code. This is an ergonomics proposal, not a protocol defect or an assertion that structured errors are unsupported.

The [additive patch](php-mcp-sdk-structured-results.patch) proposes:

```php
CallToolResult::structured(['value' => $answer]);
CallToolResult::structured(['error' => ['reason' => 'tool_denied']], isError: true);
```

It creates equivalent JSON text and `structuredContent`, preserves metadata and `isError`, and throws on values that cannot be encoded. It leaves the existing constructor and factory signatures unchanged. The caller remains responsible for sanitizing error data and checking authorization. The helper does not establish that a generic upstream failure was an access denial.

Run the proposal against a temporary copy of the installed SDK:

```bash
php docs/contributing/verify-sdk-proposal.php
```

Verified: seven JSON shapes (including false, zero, empty object and null), structured denial round-trip, invalid JSON rejection, and existing factories. Vendor files are never patched. This project's production adapter continues to use the supported constructor. The [current upstream class](https://github.com/modelcontextprotocol/php-sdk/blob/main/src/Schema/Result/CallToolResult.php) was checked on 2 November 2026.

Related upstream work: [#521 / #605](https://github.com/modelcontextprotocol/php-sdk/issues/521) already documents returning structured-only results through the constructor, and #93 introduced structured results/errors. This proposal is only a convenience factory with a text fallback. It does not negotiate protocol versions; list/scalar structured values still require a compatible protocol revision. For older clients, use object-shaped data. A GitHub search found these related items; checking the exact current main commit was rate-limited, so refresh that check before submission.

Suggested PR title: **Add a structured tool-result factory with equivalent text content**. Before submission, agree the public method name with maintainers, recheck overlapping work, port these cases into the SDK's unit/inspector suites, and run its full `make ci` and applicable conformance checks. Those upstream suites have not been run here. Follow the [SDK contribution guide](https://github.com/modelcontextprotocol/php-sdk/blob/main/CONTRIBUTING.md), including its licensing requirements.

## Symfony: a working integration and an AI recipe

The new [Composer bundle](../../packages/symfony-code-mode/README.md) is the concrete contribution candidate: explicit service registration, host-owned identity, fresh conversation sessions, a doctor command, and STDIO exposure. No framework change is required to adopt it. The package is currently a repository path dependency, not published to Packagist or shipped by Symfony.

An optional [CodeModeToolbox](../../packages/symfony-code-mode/src/Ai/CodeModeToolbox.php) implements Symfony AI's existing `ToolboxInterface`. It uses the same three canonical schemas and `CodeModeSession`, validates arguments, returns sanitized model-visible failures, and retains host traces. It does not require an MCP subprocess for Symfony-owned tool services. Install `symfony/ai-agent` and `symfony/ai-platform` at the compatible `^1.0@beta` versions to use it; this repository already has both.

The proposed [Symfony AI recipe](symfony-ai-recipe.md) explains a security-sensitive detail absent from a simple chatbot example: the toolbox and session must live for the authenticated conversation, including its follow-up and failed attempts. Recreating a session on every model call would reset its budget. Sharing one across users would mix identity and discovery state.

`CodeModeToolboxTest` verifies model-visible denial, recovery, argument rejection and retained attempt limits with a test executor; it does **not** claim model generation or gVisor execution. The separate `tests/Sandbox/SymfonyCodeModeTest.php` runs real PHP through the new bundle's MCP server and gVisor. The Codex capture supplies actual generation and recovery. The [real Symfony AI + Hugging Face capture](../huggingface-comparison.md) now verifies generation through the original audit toolbox and the same core Code Mode package; it does not yet exercise this optional bundle adapter with a paid provider.

Suggested Symfony contribution: **Document a conversation-scoped custom toolbox with authorized code execution**. Offer the recipe and executable regression cases first; discuss whether an external bridge belongs in Symfony before proposing any core API. Symfony's [MCP error propagation issue #2534](https://github.com/symfony/ai/issues/2534) was already fixed through #2535 in September 2026. We should use our denial/recovery scenario as additional coverage, not file that known issue again.
