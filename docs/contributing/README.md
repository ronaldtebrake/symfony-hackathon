# Upstream contributions

These are prepared proposals, **not submitted issues or pull requests**. No upstream acceptance or Packagist publication is implied. The existing Symfony demo and its matched Hugging Face comparison remain unchanged.

## 1. Symfony AI: bring a working Code Mode example to #2508

[Code Mode discussion #2508](https://github.com/symfony/ai/issues/2508) is open, checked on 3 November 2026. Contributors ask how to sandbox execution, discover tools, preserve structured results and establish a useful problem space. Our publishing audit supplies a concrete example for that discussion.

**Suggested comment:**

> We built Symfony Code Mode for the hackathon: a reusable Composer integration that lets an agent compose explicitly approved Symfony AI tools in isolated PHP. Existing `#[AsTool]` services are adapted through their original toolbox, retaining argument resolution and lifecycle hooks. A separate host policy authorizes each call; the runner requires gVisor and bounded execution. The publishing audit has real Hugging Face and Codex traces, including failed attempts and a matched direct-tool baseline. We would like feedback on an external integration recipe before proposing a framework API.

Attach these reviewable pieces:

- [Native-tool adapter](../../packages/symfony-code-mode/src/Ai/ApprovedToolbox.php), [minimal configuration](../../packages/symfony-code-mode/README.md#reuse-existing-symfony-ai-tools) and [conversation recipe](symfony-ai-recipe.md).
- [Independent Symfony consumer](../../examples/symfony/README.md) and [fresh-install proof](../evidence/fresh-symfony-app.json): own dependency graph, native tool and policy; real MCP allowed/denied reads and session exhaustion, with no main-demo autoloader or package source directories present at execution. This is a local-package adoption proof, not a Packagist release.
- [Adoption capture](../evidence/adoption-proof.json): real Symfony AI + Hugging Face through the reusable adapter; generated PHP, underlying Symfony calls, two user turns, measured usage and cleanup checks. This capture invokes native Symfony services; the earlier audit supplies the separate MCP evidence.
- [Original matched comparison](../huggingface-comparison.md): the same model, question, data and permissions, both answers correct, all 21 earlier development invocations retained. A single successful pair is not a benchmark.
- `ApprovedToolboxTest`, `CodeModeToolboxTest` and `tests/Sandbox/AdoptionProofTest.php`: authorization, validation, lifecycle hooks, conversation limits and real sandbox execution.

The new live conversation uses `openai/gpt-oss-120b` via Hugging Face auto-routing: **7 requests, 8,614 reported tokens, 3,685 tool-result bytes, two PHP executions and five underlying calls**. It sees the injected article text and performs the permitted follow-up, checking 48 articles and finding IDs 11, 22, 33 and 44. The independent fixed probe attempts the restricted read, records `tool_denied`, recovers, and cannot make a third execution attempt.

The first live attempt is also retained: 7 requests and 8,763 tokens. Its PHP filtered the malicious paragraph out before the model saw it. The audit was correct, but the exposure check correctly failed. The next attempt explicitly requested the complete body; this is a changed question, not a comparable performance rerun. Together the two live attempts used 14 requests and 17,377 reported tokens.

**Proposed first contribution:** an executable cookbook example of a conversation-scoped custom toolbox. Keep the gVisor runner and deployment requirements in the external package. Discuss framework integration with maintainers before adding core abstractions.

**Evaluation material to offer:** `code-mode:evaluate` now runs five fixed Symfony MCP scenarios through direct tools and Code Mode, with predeclared repetitions, the same model/data/permissions and independent answer/coverage checks. The [single report](../evidence/evaluation.json) retains failed attempts and protocol revisions. Host-observed model, runner, broker and cleanup spans are inspectable in the workbench. Offer the fixtures, result contract and evaluation methodology as a small Symfony AI cookbook/testing contribution; do not propose the demo viewer as a framework API. The approach follows [trace-driven tool evaluation](https://x.com/sydneyrunkle/status/2117675460884934784) and [consistent structured answer contracts](https://github.com/browserbase/stagehand/pull/3219). No upstream submission has been made.

The [v3/v4 investigation](../demo.md#repeatable-evaluation) adds a useful regression case: an extra model review can repair omissions but also corrupt a correct tool-derived answer. Offer a bounded review recipe with no tool executor, a shared request budget, syntax validation before replacing a draft, retained rejected outputs and all review costs counted. Syntax acceptance cannot establish semantic correctness; the independent fixture checks remain essential.

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

## Research follow-up — 4 November 2026

The [hackathon brief](https://symfony.com/hackathon) explicitly values runnable clones, a short real demo and feedback before 1.0. The strongest next steps are a fresh-application installation proof and small, reviewable upstream contributions. The 90-second manual recording and formal submission remain pending. Research is not submission; nothing below has been posted upstream.

| Opportunity | Concrete contribution or acceptance check |
| --- | --- |
| [Symfony AI Code Mode #2508](https://github.com/symfony/ai/issues/2508) | Offer the existing native-tool recipe and the complete v4 evaluation: all five tasks twice, correct answers on both paths, review costs and failed predecessors retained. Ask for feedback on the extension boundary, not adoption of the entire runner into core. |
| [Symfony AI execution traces #2342](https://github.com/symfony/ai/issues/2342) | A new fit for our timeline: contribute regression cases for deferred model completion, failed calls, review costs, nested broker time and cleanup. Align with the existing RFC instead of proposing another trace API. The current evaluation is non-streaming; it does not prove streamed completion handling. |
| [PHP MCP SDK library listing](https://github.com/modelcontextprotocol/php-sdk#php-libraries-using-the-mcp-sdk) | The SDK explicitly invites library entries. First make the core and bundle independently installable from tagged packages, then propose an entry. The structured-result factory above remains a small separate proposal; the fetched [upstream class](https://github.com/modelcontextprotocol/php-sdk/blob/main/src/Schema/Result/CallToolResult.php) still has only `success()` and `error()` convenience factories. Recheck open PRs before submitting. |
| [Tool API schema/validation matrix #3583061](https://git.drupalcode.org/project/tool/-/work_items/3583061) | Supply MCP-invoker cases for nested arrays/maps, nullable outputs, invalid values and advertised-schema agreement, using real discovery and invocation. The issue specifically asks for invoker and output dimensions. |

The first adoption milestone is now captured in the [separate Symfony app](../../examples/symfony/README.md): an independent locked Composer install, copied packages, its own `#[AsTool]` service and policy, prerequisite checks, and real authorized/denied MCP executions through gVisor. The capture passed 24 checks after the package source directories were detached; no root app or autoloader was present. Eleven local policy tests also pass, including checks that denied calls never invoke the handler. Packages remain unpublished, and the local operator identity does not establish remote authentication.

After this milestone, prioritize:

1. **Distribution and runtime setup.** Prepare independently tagged core/bundle releases and test a consumer that installs those releases without path repositories. Verify one documented dedicated-Linux/VM bootstrap from an empty machine, pin the runner image, and fail clearly when runsc is absent. The fresh-app result proves the PHP integration; runtime provisioning and public package distribution remain adoption gaps.
2. **One small upstream contribution.** Offer the fresh consumer and complete v4 results to [Code Mode #2508](https://github.com/symfony/ai/issues/2508), whose discussion asks for an example, concrete use case, discovery and structured results. Keep the SDK structured-result helper separate. For [tracing #2342](https://github.com/symfony/ai/issues/2342), contribute focused failure/cleanup and deferred-completion regression cases; do not claim our non-streaming captures validate streaming. These pages were consulted again on 4 November; proposals remain unsubmitted.
3. **Finish the real 90-second recording.** Use the existing Codex conversation, PHP/call traces and denied-read recovery; show the four-setting consumer configuration briefly to demonstrate reuse. The [brief](https://symfony.com/hackathon) emphasizes runnable code, craft and prerelease feedback. Another large demo would delay the required recording and submission.
4. **Expand evidence selectively.** Keep the holdout/discovery experiment separate from v4. On Drupal, prioritize the two-user, revocation and field-access checks below before adding more tools or HTTP access. These improve confidence more than another themed UI.

The [Symfony performance investigation](../performance.md) now provides a second concrete use case for #2508: compose three approved native readers to find repeated work, correlate log events and verify a prepared alternative. A real synthetic request uses 13 queries; the joined version uses one and returns identical content. Both restricted readers are denied in the passing capture. The earlier inconclusive denial is retained. This is operator-written reference PHP over the PHP MCP SDK and gVisor; it is not a live model benchmark or a production profiler integration.

A follow-up could adapt existing [Mate profiler/Monolog extensions](https://symfony.com/doc/current/ai/components/mate.html) behind the same bounded projections. The current example uses Symfony's Profiler and its own PSR-3 collector, not Mate. Verify installed extension contracts and scope/redact every read before using real application data; do not expose raw profiler files, unrestricted logs or shell execution.

Keep any new evaluation separate from v4. A seeded holdout dataset and catalog sizes of 16/64/128 would test generalization and discovery; the existing five scenarios use one small fixture and do not establish either. [Cloudflare's guidance](https://developers.cloudflare.com/agents/tools/codemode/) also recommends direct calls for simple tasks, so include a single-read case rather than claiming Code Mode always wins.

### Drupal checks and contribution targets

Read-only checks on 4 November found Drupal **11.4.11 / PHP 8.3.19** bootstrapping successfully, a connected database, the reader limited to `access content` and `read code mode articles`, the published fixture allowed, the unpublished fixture denied, and anonymous MCP permission absent. `ddev composer audit --locked --format=json` reported no known advisories or abandoned packages. This does not establish security coverage for prereleases; no new guest execution or HTTP authentication test was performed in this research pass.

- Keep **Tool beta12 + Tool Bridge beta5** paired. The [bridge release notes](https://www.drupal.org/project/mcp_server_tool_bridge/releases/1.0.0-beta5) explain the ToolRunner change and why beta4 loses input names with beta12. Pin actual `tools/list` names and result shapes in tests; project-page examples are not a substitute for wire captures.
- Add two-user isolation, permission revocation between calls, field access, translation and unpublished-revision cases before expanding beyond scalar reads. [MCP Server beta7](https://www.drupal.org/project/mcp_server) includes a fix for cross-user resource-cache leakage; our lockfile already includes it. The current title-read proof does not exercise resources, handles or cross-account reuse.
- **Concrete error-contract proposal:** installed Tool Bridge beta5 has `FailureCategory` internally, but its `failure()` method returns text with MCP `isError` and does not transmit the category. Our gateway safely collapses it to `tool_failed`. Propose documented, machine-readable input/access/runtime classification with sanitized values, plus tests that preserve the distinction through adapters. Never parse denial prose or use upstream metadata to grant access.
- [Tool beta12](https://www.drupal.org/project/tool/releases/1.0.0-beta12) supports `#[ToolMethod]` on ordinary service methods with explicit access checks. This offers a smaller future Drupal adoption example, parallel to Symfony's `#[AsTool]`, without changing the generic Code Mode runner.
- Evaluate [Agent Access](https://www.drupal.org/project/agent_access) for a later authenticated HTTP integration instead of implementing OAuth ourselves. It is an experimental recipe, not covered by Drupal's security advisory policy. Keep the current local STDIO proof until per-user identity, scopes, denial and revocation are tested. Drupal remains a portability example; the main judge demonstration stays Symfony-focused.

Some GitHub issue/PR pages could not be refreshed and the authenticated API was rate-limited. Existing notes about #2623 and #605 retain their earlier check date; their current status and duplicate PRs need rechecking before submission. The public hackathon project gallery required SymfonyConnect, so no competitive ranking was established.

## Reproduce and share

```sh
composer check
php bin/console code-mode:doctor
php bin/console code-mode:adoption-proof --no-debug
# Optional paid capture; reads the token from ignored .env.local:
php bin/console code-mode:adoption-proof --live --no-debug
```

Keep the same session for follow-ups. Treat tool metadata and returned article text as untrusted data. Never substitute annotations, generated PHP or successful model behaviour for host authorization. See the [security model](../../packages/php-code-mode/docs/security.md) for deployment limits. Share sanitized reviewed evidence, not credentials, local logs or private content. The [hackathon brief](https://symfony.com/hackathon) values concrete prerelease feedback; none of these proposals has been sent on your behalf.
