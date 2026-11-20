# Upstream contributions

## What we can offer now

We can offer three reproducible cases from the hackathon. These are prepared locally for discussion with maintainers.

| Who | What we can share |
| --- | --- |
| Symfony AI | A two-tool catalog recipe using existing read-only `#[AsTool]` services. Tests exercise filtering by caller **before** the proposed BM25 ranking runs, then rechecking the actual read after the grant changes. [Catalog integration](../../packages/symfony-code-mode/README.md) · [Search tests](../../tests/Integration/SymfonyCatalogSearchTest.php) · [Discovery discussion](https://github.com/symfony/ai/issues/2623) |
| PHP MCP SDK | A PHP interoperability fixture for named groups. A client negotiates Groups, opens a group and calls a member. A client using the older handshake can use the catalog on the same host. Our fixture runs on SDK 0.9. The next port would exercise the extension API in 0.10. [Groups tests](../../tests/Integration/PublishingGroupsTest.php) · [Legacy client test](../../tests/Integration/AuditMatrixTest.php) · [SDK 0.10 release](https://github.com/modelcontextprotocol/php-sdk/releases/tag/v0.10.0) |
| MCP working group | A refund group that links a skill alongside two readers. Revoking access after group discovery denied both the skill manifest and its file. In a separate conversation, the model could still explain why it could no longer verify the policy. [Access traces](../evidence/refund-protocol.json) · [Groups proposal](https://github.com/modelcontextprotocol/progressive-disclosure-wg/pull/19) · [Skills positioning discussion](https://github.com/modelcontextprotocol/progressive-disclosure-wg/pull/22) |

## What to test next

Give the agent several plausible groups and let it choose from the user’s question. In the current demo the host made that choice. Compare task correctness, discovery requests and total provider tokens against ordinary catalog search. A later trial could add a policy exception to see when reading the linked checklist changes the decision. [Current findings and study design](../reusable-workflows.md#what-to-test-next)

<details>
<summary>Earlier work on sandboxed PHP, catalog search and structured results</summary>

These earlier notes explain the proposals in the context of their original captures and dependency versions.

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

The [v3/v4 investigation](../demo.md#historical-repeated-directphp-evaluation) adds a useful regression case: an extra model review can repair omissions but also corrupt a correct tool-derived answer. Offer a bounded review recipe with no tool executor, a shared request budget, syntax validation before replacing a draft, retained rejected outputs and all review costs counted. Syntax acceptance cannot establish semantic correctness; the independent fixture checks remain essential.

**A concrete API limitation to discuss:** the installed Symfony AI prerelease returns a denied `ToolCallRequested` as an ordinary string-valued `ToolResult`, without a typed denial flag. A wrapper cannot reliably distinguish that from a valid string response. Our object output schemas reject such strings and the broker returns sanitized `tool_failed`; our own authorization policy produces `tool_denied`. Consider preserving a typed outcome for decorators. Reproduce against current upstream before proposing a change. This is separate from MCP error propagation issue #2534, already fixed through #2535.

## 2. Build on Symfony AI’s discovery work

Reviewed on 14 November 2026: [dynamic discovery #2623](https://github.com/symfony/ai/issues/2623), [Johannes Wachter’s PR #87](https://github.com/wachterjohannes/symfony-ai/pull/87) at `c9b2fc128e50524e6ca7585d2a7771925962ffad`, and its prerequisite [native deferred-tools PR #2878](https://github.com/symfony/ai/pull/2878) at `99536fd46171885bba705f4e1b0df6b86c419b60`. Both PRs are open. No dependency was changed and no feedback has been posted.

The designs solve related problems at different layers:

| Surface | How discovery works | What grows |
| --- | --- | --- |
| Our HTTP MCP server | Two stable tools; search returns full contracts; execute accepts literal JSON | Retrieved contracts and conversation history; the two advertised schemas stay fixed |
| Proposed `SearchableToolbox` | An agent toolbox decorator; common tools stay exposed, searched tools become directly callable | Hidden names appear in the search description; discovered schemas join the tool list. The PR documents possible prompt-cache invalidation |
| Proposed native provider path | `deferred` tools are handled through the provider’s search capability | Provider-managed behavior; evaluate its usage and cache reporting separately |

**Implemented in the existing HTTP catalog:** `SessionFactory` now uses [SymfonyCatalogSearch](../../packages/symfony-code-mode/src/Ai/Search/SymfonyCatalogSearch.php), adapting the proposed search signature to our approved contracts. The default is BM25. A compatible installed Symfony component takes precedence; until the API ships, [three attributed, MIT-licensed classes](../../packages/symfony-code-mode/src/Ai/Search/Upstream/README.md) are pinned at the reviewed revision with only their namespace changed. Composer manifests and lock files are unchanged. We did not copy `SearchableToolbox`, the agent loop or a provider bridge.

The current user's visibility policy filters the catalog **before** ranking. The adapter projects only those definitions into Symfony AI `Tool` objects with inert execution references. It receives no handlers. It returns names; the catalog verifies membership and uniqueness, then discloses the original contracts. Exact-name lookup, empty-query browsing, counts, eight-result truncation and the disclosure budget remain in the catalog. Authorization, argument/result validation and persistent budget reservations remain in the existing session and broker. The PHP discovery protocol and standalone core fallback are unchanged.

**Reproducible checks:** [adapter tests](../../tests/Integration/SymfonyCatalogSearchTest.php) cover native-component selection, schema-field-only and nested-enum queries, camel-case and plural normalization, browse/exact-name behavior, truncation, oversized contracts, two-user filtering, invalid ranker results, schema mutation, revocation and exhausted budgets. The existing [fresh HTTP capture](../evidence/http-catalog-proof.json) now runs the adapter using `publications` to find `publication_read`; all 31 checks pass with child-process functions disabled inside the HTTP application. Earlier captures remain in `previousAttempts`. A separate [live Symfony AI + Hugging Face conversation](../evidence/http-conversation.json) now covers the same HTTP/BM25 route: article lookup, revocation, denied identical read and rediscovery. Its successful attempt uses six model requests and 5,220 reported tokens. The first model attempt skipped rediscovery and incorrectly claimed visibility; it remains in the report, with the denial and no-handler trace intact. This provides an end-to-end integration case, not evidence that BM25 reduces total model cost.

The [scaling fixture](../evidence/catalog-scaling.json) was rerun against this implementation: the two entry contracts remain 239 offline tokens and one exact-name result remains 107. No model was called. This establishes compatibility and unchanged advertised schemas, not improved model accuracy or lower end-to-end usage. Historical live comparisons are retained as captured.

**Concrete upstream feedback:**

- Schema-field discovery is useful here: `external_reference` returns a `publication_read` contract with BM25, but not with `KeywordToolSearch` when that field is absent from its name/description. The adapter keeps exact names and bounded empty-query browsing outside the ranker. These are application choices, not defects in either algorithm.
- Keep permission filtering before names enter a search description or scoring corpus, and recheck authorization at execution. Our discover → revoke → denied-call fixture can help test this. A search component itself is not an authorization layer.
- The reviewed `SearchableToolbox::reset()` clears its own state without forwarding to a resettable inner toolbox, whereas `DeferredToolbox` forwards. Clarify whether ownership belongs to the decorator or the container, and add a nested-reset test for long-lived agents.
- The search interface returns ranked tools without match counts. Our bounded catalog asks for all visible matches before applying disclosure limits. A future truncation signal could help applications with larger catalogs without coupling the ranker to MCP.

**Draft comment for #87 — prepared, not posted:**

> Nice to see this taking shape! We reached the same discovery problem in our Symfony hackathon project and have now adapted your BM25 component behind our existing HTTP MCP catalog. It works with our installed Symfony AI Tool objects, without extra dependencies. Our endpoint keeps search/execute fixed; your toolbox activates tools inside the agent, so these seem complementary.
>
> Happy to contribute a few small cases: parameter-only lookup (`external_reference` matches with BM25 but not Keyword), per-user filtering before ranking, and discover → revoke → denied execution. One question from reading the patch: should `SearchableToolbox::reset()` forward to a resettable inner toolbox, as `DeferredToolbox` does, or is the container expected to handle that? We can share the adapter and reproducible tests if useful.

For agents running *inside* Symfony AI, `SearchableToolbox` and native deferred tools still need a separate integration assessment. Directly exposed tools must use the same authorization and budget guard; the ordinary application toolbox alone would bypass our session controls. Avoid wrapping our two entry tools in another discovery layer by default. Compare single reads and audits, correct answers, total usage and cached input before making native-provider cost claims.

Keep upstream feedback focused on the agent integration, not adoption of the whole runner or workbench. The earlier [PHP usage guide](../../packages/php-code-mode/src/PhpToolGuide.php) and [conversation recipe](symfony-ai-recipe.md) remain separate sandbox experiments.

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

The [hackathon brief](https://symfony.com/hackathon) explicitly values runnable clones, a short real demo and feedback before 1.0. The strongest next steps are a fresh-application installation proof and small, reviewable upstream contributions. The original research priorities are retained below; the 90-second real-agent film and fresh HTTP installation proof have since been completed. Formal submission remains pending. Research is not submission; nothing below has been posted upstream.

| Opportunity | Concrete contribution or acceptance check |
| --- | --- |
| [Symfony AI Code Mode #2508](https://github.com/symfony/ai/issues/2508) | Offer the existing native-tool recipe and the complete v4 evaluation: all five tasks twice, correct answers on both paths, review costs and failed predecessors retained. Ask for feedback on the extension boundary, not adoption of the entire runner into core. |
| [Symfony AI execution traces #2342](https://github.com/symfony/ai/issues/2342) | A new fit for our timeline: contribute regression cases for deferred model completion, failed calls, review costs, nested broker time and cleanup. Align with the existing RFC instead of proposing another trace API. The current evaluation is non-streaming; it does not prove streamed completion handling. |
| [PHP MCP SDK library listing](https://github.com/modelcontextprotocol/php-sdk#php-libraries-using-the-mcp-sdk) | The SDK explicitly invites library entries. First make the core and bundle independently installable from tagged packages, then propose an entry. The structured-result factory above remains a small separate proposal; the fetched [upstream class](https://github.com/modelcontextprotocol/php-sdk/blob/main/src/Schema/Result/CallToolResult.php) still has only `success()` and `error()` convenience factories. Recheck open PRs before submitting. |

The earlier PHP adoption milestone is captured in the [separate Symfony app](../../examples/symfony/README.md): an independent locked Composer install, copied packages, its own `#[AsTool]` service and policy, prerequisite checks, and real authorized/denied MCP executions through gVisor. The capture passed 24 checks after the package source directories were detached; no root app or autoloader was present. Eleven local policy tests also pass, including checks that denied calls never invoke the handler. Packages remain unpublished, and the local operator identity does not establish remote authentication.

After this milestone, prioritize:

1. **Distribution and runtime setup.** Prepare independently tagged core/bundle releases and test a consumer that installs those releases without path repositories. Verify one documented dedicated-Linux/VM bootstrap from an empty machine, pin the runner image, and fail clearly when runsc is absent. The fresh-app result proves the PHP integration; runtime provisioning and public package distribution remain adoption gaps.
2. **One small upstream contribution.** Offer the fresh consumer and complete v4 results to [Code Mode #2508](https://github.com/symfony/ai/issues/2508), whose discussion asks for an example, concrete use case, discovery and structured results. Keep the SDK structured-result helper separate. For [tracing #2342](https://github.com/symfony/ai/issues/2342), contribute focused failure/cleanup and deferred-completion regression cases; do not claim our non-streaming captures validate streaming. These pages were consulted again on 4 November; proposals remain unsubmitted.
3. **Completed: the 90-second recording.** The [article lookup film](https://ronaldtebrake.github.io/symfony-hackathon/#article-tour) shows real agent footage against authenticated Symfony HTTP, JSON calls, revocation, Maker, a shared voter and the profiler. The [submission description](../demo.md#submission-description) describes the two-tool catalog and the earlier PHP design.
4. **Share the permission-aware Symfony integration.** Use the fresh HTTP proof and shared policy tests to demonstrate user-specific discovery, permission revocation and persistent call budgets. Keep future holdout/discovery experiments separate from v4.

The [Symfony performance investigation](../performance.md) now provides a second concrete use case for #2508: compose three approved native readers to find repeated work, correlate log events and verify a prepared alternative. A real synthetic request uses 13 queries; the joined version uses one and returns identical content. Both restricted readers are denied in the passing capture. The earlier inconclusive denial is retained. This reference uses operator-written PHP over the PHP MCP SDK and gVisor. A separate live conversation and matched direct-tool baseline are also captured in the guide; neither is a production profiler integration.

A follow-up could adapt existing [Mate profiler/Monolog extensions](https://symfony.com/doc/current/ai/components/mate.html) behind the same bounded projections. The current example uses Symfony's Profiler and its own PSR-3 collector, not Mate. Verify installed extension contracts and scope/redact every read before using real application data; do not expose raw profiler files, unrestricted logs or shell execution.

Keep any new evaluation separate from v4. The five existing scenarios use one small fixture. A seeded holdout dataset and catalogs of 16, 64 and 128 tools would test whether discovery holds up as the catalog grows. Include a single read alongside the multi-step audit so maintainers can see what discovery and orchestration cost on a simple task.

Some GitHub issue/PR pages could not be refreshed and the authenticated API was rate-limited. The #2623/#87/#2878 review above supersedes the earlier discovery notes; #605 and duplicate SDK proposals still need a fresh check before submission. The public hackathon project gallery required SymfonyConnect, so no competitive ranking was established.

</details>

## Reproduce and share

```sh
composer check
php bin/console code-mode:doctor
php bin/console code-mode:adoption-proof --no-debug
# Optional paid capture; reads the token from ignored .env.local:
php bin/console code-mode:adoption-proof --live --no-debug
```

Keep the same session for follow-ups. Treat tool metadata and returned article text as untrusted data. Never substitute annotations, generated PHP or successful model behaviour for host authorization. See the [security model](../../packages/php-code-mode/docs/security.md) for deployment limits. Share sanitized reviewed evidence, not credentials, local logs or private content. The [hackathon brief](https://symfony.com/hackathon) values concrete prerelease feedback; none of these proposals has been sent on your behalf.
