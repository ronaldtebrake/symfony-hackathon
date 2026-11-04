# Upstream contributions to make after the hackathon work

These are prepared proposals, **not submitted issues or pull requests**. No upstream acceptance or Packagist publication is implied. The existing Symfony demo and its matched Hugging Face comparison remain unchanged.

## 1. Symfony AI: bring a working Code Mode example to #2508

[Code Mode discussion #2508](https://github.com/symfony/ai/issues/2508) is open, checked on 3 November 2026. Contributors ask how to sandbox execution, discover tools, preserve structured results and establish a useful problem space. Our publishing audit supplies a concrete example for that discussion.

**Suggested comment:**

> We built Symfony Code Mode for the hackathon: a reusable Composer integration that lets an agent compose explicitly approved Symfony AI tools in isolated PHP. Existing `#[AsTool]` services are adapted through their original toolbox, retaining argument resolution and lifecycle hooks. A separate host policy authorizes each call; the runner requires gVisor and bounded execution. The publishing audit has real Hugging Face and Codex traces, including failed attempts and a matched direct-tool baseline. We would like feedback on an external integration recipe before proposing a framework API.

Attach these reviewable pieces:

- [Native-tool adapter](../../packages/symfony-code-mode/src/Ai/ApprovedToolbox.php), [minimal configuration](../../packages/symfony-code-mode/README.md#reuse-existing-symfony-ai-tools) and [conversation recipe](symfony-ai-recipe.md).
- [Adoption capture](../evidence/adoption-proof.json): real Symfony AI + Hugging Face through the reusable adapter; generated PHP, underlying Symfony calls, two user turns, measured usage and cleanup checks. This capture invokes native Symfony services; the earlier audit supplies the separate MCP evidence.
- [Original matched comparison](../huggingface-comparison.md): the same model, question, data and permissions, both answers correct, all 21 earlier development invocations retained. A single successful pair is not a benchmark.
- `ApprovedToolboxTest`, `CodeModeToolboxTest` and `tests/Sandbox/AdoptionProofTest.php`: authorization, validation, lifecycle hooks, conversation limits and real sandbox execution.

The new live conversation uses `openai/gpt-oss-120b` via Hugging Face auto-routing: **7 requests, 8,614 reported tokens, 3,685 tool-result bytes, two PHP executions and five underlying calls**. It sees the injected article text and performs the permitted follow-up, checking 48 articles and finding IDs 11, 22, 33 and 44. The independent fixed probe attempts the restricted read, records `tool_denied`, recovers, and cannot make a third execution attempt.

The first live attempt is also retained: 7 requests and 8,763 tokens. Its PHP filtered the malicious paragraph out before the model saw it. The audit was correct, but the exposure check correctly failed. The next attempt explicitly requested the complete body; this is a changed question, not a comparable performance rerun. Together the two live attempts used 14 requests and 17,377 reported tokens.

**Proposed first contribution:** an executable cookbook example of a conversation-scoped custom toolbox. Keep the gVisor runner and deployment requirements in the external package. Discuss framework integration with maintainers before adding core abstractions.

**Evaluation material to offer:** `code-mode:evaluate` now runs five fixed Symfony MCP scenarios through direct tools and Code Mode, with predeclared repetitions, the same model/data/permissions and independent answer/coverage checks. The [single report](../evidence/evaluation.json) retains failed attempts and protocol revisions. Host-observed model, runner, broker and cleanup spans are inspectable in the workbench. Offer the fixtures, result contract and evaluation methodology as a small Symfony AI cookbook/testing contribution; do not propose the demo viewer as a framework API. The approach follows [trace-driven tool evaluation](https://x.com/sydneyrunkle/status/2117675460884934784) and [consistent structured answer contracts](https://github.com/browserbase/stagehand/pull/3219). No upstream submission has been made.

**A concrete API limitation to discuss:** the installed Symfony AI prerelease returns a denied `ToolCallRequested` as an ordinary string-valued `ToolResult`, without a typed denial flag. A wrapper cannot reliably distinguish that from a valid string response. Our object output schemas reject such strings and the broker returns sanitized `tool_failed`; our own authorization policy produces `tool_denied`. Consider preserving a typed outcome for decorators. Reproduce against current upstream before proposing a change. This is separate from MCP error propagation issue #2534, already fixed through #2535.

## 2. Symfony AI: contribute discovery evidence to #2623

[Dynamic tool discovery #2623](https://github.com/symfony/ai/issues/2623) already links a searchable-toolbox/BM25 prototype. Join that discussion instead of opening a duplicate or building a competing search API.

Our selected-tool descriptions now include a bounded [PHP usage guide](../../packages/php-code-mode/src/PhpToolGuide.php): an invocation example, argument/result shapes and explicit `stdClass` versus list guidance. Undeclared and referenced output shapes remain `mixed`. Guides are data, never evaluated or used as authorization. The original schemas remain available.

**Suggested contribution:** offer this PHP-specific experience and a focused discovery evaluation. Use a clearly labelled synthetic catalog of 16, 64 and 128 approved tools; compare initial schema bytes, discovery bytes, retrieval accuracy, complete-answer accuracy and request counts. Include ambiguous names and unrelated tools. Keep the model, task, relevant data and permissions fixed. Compare our simple keyword search with the existing prototype before proposing ranking changes. This larger-catalog experiment is a contribution follow-up, not evidence already captured here.

[Cloudflare's typed Code Mode interfaces](https://developers.cloudflare.com/agents/model-context-protocol/guides/build-codemode-mcp-server/) informed the guide. The PHP guide has not yet been evaluated in a controlled with/without experiment; do not claim it reduces model errors or tokens.

## 3. PHP MCP SDK: a structured-result convenience factory

**Suggested PR title:** Add a structured tool-result factory with equivalent text content.

Our successful results and sanitized denials carry JSON for programs plus text for clients that only consume `content`. The SDK constructor already supports this. Its current `success()` and `error()` factories do not take structured content; this is an ergonomics proposal, not missing protocol support.

The [prepared patch](php-mcp-sdk-structured-results.patch) proposes:

```php
CallToolResult::structured(['value' => $answer]);
CallToolResult::structured(['error' => ['reason' => 'tool_denied']], isError: true);
```

It creates equivalent JSON text and `structuredContent`, preserves metadata and `isError`, rejects non-encodable values, and leaves existing signatures unchanged. The caller must sanitize errors and enforce authorization. It does not negotiate protocol versions: use object-shaped structured values for older clients.

```sh
php docs/contributing/verify-sdk-proposal.php
```

This checks seven JSON shapes, structured denial round-tripping, invalid JSON rejection and unchanged existing factories in a **temporary copy**. It does not patch vendor code or substitute for upstream CI. Structured-only responses are already documented by [#605](https://github.com/modelcontextprotocol/php-sdk/pull/605), which closed [#521](https://github.com/modelcontextprotocol/php-sdk/issues/521). The current class and related issue search were checked on 3 November 2026; no equivalent factory was found.

Before submitting, recheck current main and open PRs, agree the helper's scope/name with maintainers, port the cases into SDK tests and run its `make ci` and applicable conformance checks. Follow the [contribution and licensing guide](https://github.com/modelcontextprotocol/php-sdk/blob/main/CONTRIBUTING.md). The production adapter continues to use supported SDK APIs.

## Reproduce and share

```sh
composer check
php bin/console code-mode:doctor
php bin/console code-mode:adoption-proof --no-debug
# Optional paid capture; reads the token from ignored .env.local:
php bin/console code-mode:adoption-proof --live --no-debug
```

Keep the same session for follow-ups. Treat tool metadata and returned article text as untrusted data. Never substitute annotations, generated PHP or successful model behaviour for host authorization. See the [security model](../../packages/php-code-mode/docs/security.md) for deployment limits. Share sanitized reviewed evidence, not credentials, local logs or private content. The [hackathon brief](https://symfony.com/hackathon) values concrete prerelease feedback; none of these proposals has been sent on your behalf.
