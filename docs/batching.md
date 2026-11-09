# Approved JSON tools

Use a batch when the agent already knows the arguments for several independent
tool calls. The host executes the calls in order and returns their results. No
generated code runs. Use Code Mode when PHP needs to paginate, branch or combine
intermediate results before the agent receives them.

Both paths reuse the same approved providers, host identity, broker authorization,
JSON Schema validation and supervised tool handlers. Batching does not add
permissions. The optional Drupal Data Surface proof exercises the same core
through MCP; it does not change the main Symfony demo.

The direction grew out of our working PHP sandbox proof and the separate
Drupal/Rosetta query experiment: an explicit JSON Schema contract suggested a
simpler way to invoke approved handlers. We then found **Pushpak Chhajed’s**
[A better way to build MCP servers with Laravel](https://laravel.com/blog/a-better-way-to-build-mcp-servers-with-laravel#version-one-i-handed-the-agent-tinker).
Credit to Pushpak for the account of that transition and the search-and-execute
design that informed this Symfony adaptation.

## Two-tool catalog iteration

Set `code_mode.mode: catalog` to expose a fixed pair of MCP tools:

- **`search_tools({"query":"…"})`** returns complete approved contracts, including
  input schemas. It searches names, descriptions and schema fields with weights
  of 4, 2 and 1. Any query term can match; an exact tool name selects that tool.
- **`execute_tools({"calls":[{"name":"…","arguments":{…}}]})`** invokes one or
  more approved calls with literal arguments. One read can use a one-item list;
  batching is optional. Dependent calls require another invocation.

There is no separate describe step, generated PHP or workflow language. Tool
names and entry schemas stay fixed throughout the conversation. Search returns
at most eight complete contracts within a 32 KiB disclosure budget, with match
counts and narrowing guidance. It never cuts a schema in half. Only contracts
actually returned become eligible for execution; search does not grant access.
Every invocation still checks host approval, authorization and arguments.

This is a separate opt-in iteration. The original `mode: batch` below and
default `mode: php` retain their interfaces. All JSON modes reuse `BatchSession`
and its existing limits, supervisor and failure behavior. In Symfony AI, use
`new BatchToolbox($session, catalog: true)`; for a standalone MCP server use
`BatchMcpServerFactory::create($session, catalog: true)`.

The [Laravel implementation](https://github.com/laravel/mcp/blob/main/src/Server/Tools/ToolSearch.php)
inspired the fixed search/execute surface and schema-field ranking. Its executor
also accepts a list of calls. Removing our describe round trip simplifies the
interface; it does not move joins or filtering out of the model. Our session
additionally requires disclosure before invocation and supervises each handler.

## Symfony configuration

Add `mode: batch` to your existing approved-tool configuration:

```yaml
code_mode:
    mode: batch
    identity_provider: App\CodeMode\DemoIdentityProvider
    tools:
        - App\CodeMode\ReadArticle
        - App\CodeMode\ListPublishedArticles
```

Existing `providers` or `ai_toolbox`/`ai_tools`/`ai_authorization` registration
works too. Run `php bin/console code-mode:doctor`, then configure your MCP client
to start `php bin/console code-mode:serve --no-debug`. Batch mode exposes
`tools_search`, `tools_describe` and `tools_execute`. It never resolves runner
configuration or exposes `php_execute`. The default `mode: php` still requires
gVisor. There is no unsafe local PHP executor or automatic fallback.

After describing `articles_read`, submit:

```json
{"calls":[
  {"name":"articles_read","arguments":{"id":1}},
  {"name":"articles_read","arguments":{"id":2}}
]}
```

Success returns `{"results":[{"name":"articles_read","value":{...}}, ...]}`
in call order. Values are not automatically joined, filtered or deduplicated.
Arguments are literal JSON. There are no expressions, references between calls,
account selectors, endpoint selectors or agent-adjustable limits.

For host code, `SessionFactory::createBatch($identity)` creates a conversation;
`CodeMode\Symfony\Ai\BatchToolbox` adapts that session to Symfony AI. The core
`BatchSession` also accepts a provider registry's catalog and broker directly.
Keep the same session for follow-ups. Detailed `BatchResult::trace()` records
stay on the host; the agent receives just the results. `respond($calls, $record)`
accepts an optional host observer for success and failure evidence.

## Limits and failure behavior

Defaults are two batch attempts, ten calls per batch, five seconds per batch,
one second per tool, twenty underlying calls per session, a 64 MiB broker PHP heap, 32 KiB aggregate request input,
16 KiB per tool request/result and 64 KiB aggregate result output. Host
`ExecutionLimits` supplies the configurable bounds; the Symfony bundle exposes
the same time and call settings as Code Mode. No guest/container memory limit
applies because there is no guest. PHP heap limits do not bound all native memory.
Host code can configure `BatchSession` with up to twenty submissions and a separate
`maxSessionToolCalls` ceiling (at most one hundred). Attempted calls, including
denials, consume that ceiling. An oversized next batch is rejected before any
of its calls run. The live audit experiment uses fourteen submissions and sixty
underlying calls; it does not change the package defaults.

Envelope validation happens before any invocation. Authorization and argument
validation happen again at every tool call. A batch stops at its first failure;
earlier calls are **not rolled back**. Failures return a sanitized reason, the
failed tool/index when known and a completed-call count. They do not return
partial data, raw exception messages or host traces. A completed count is not a
transaction or proof that an incomplete remote action had no effect. Approve
read-only handlers for these examples; JSON and annotations do not enforce that.

## Hosted Symfony applications

Installing gVisor requires control over the Linux runtime; it is not a Composer
dependency an ordinary managed PHP application can install. See the
[gVisor installation requirements](https://gvisor.dev/docs/user_guide/install/).

The current batch adapter needs **Unix PHP CLI, `pcntl`, `posix` and a supervised
worker process**. It needs no Docker socket, runsc or runner image. A managed
host that permits these workers is a candidate, but no hosted deployment has
been verified. Platforms such as Upsun document [background workers](https://fixed.docs.upsun.com/create-apps/workers.html);
provider-specific extension/process support still needs checking. This package
does not provide a PHP-FPM HTTP executor, remote authentication or a Messenger
transport adapter. Do not fork the broker inside a web request. Shared hosting
without worker support is not supported by this implementation.

### Could the Unix CLI worker be replaced?

Yes, but this is an adapter change, not a configuration switch today.
`BatchSession` currently creates `BrokerWorker` directly. The forked worker
inherits the approved handlers and identity, while its parent enforces deadlines
and terminates blocked calls. JSON batching itself does not require `pcntl`.

The next adoption step would be an injectable handler-supervisor interface, with
the existing worker as the default. A [Symfony Process](https://symfony.com/doc/current/components/process.html)
adapter could launch a fixed console command that boots the application's
container. It would exchange bounded JSON operation IDs and arguments, resolve
identity from trusted host context and recheck authorization at execution time.
It must never accept a model-supplied command, executable path or serialized PHP
closure. Process still requires PHP CLI and `proc_open`; termination of child
process trees and parent-death behavior would need explicit verification.

For hosts already running [Symfony Messenger](https://symfony.com/doc/current/messenger.html),
a queue adapter is another option. It needs result correlation, authenticated
identity propagation, expiry, cancellation and deliberate retry/idempotency
rules. A worker lifetime limit alone does not enforce a deadline on a blocked
handler. Running approved handlers inside a web request could retain application
access checks, but would not preserve the current supervision guarantees. There
is no automatic fallback to that behavior.

Neither adapter is implemented or a sandbox for generated PHP. Making batch-only
installation more portable also requires separating the core package's current
`ext-pcntl` and `ext-posix` requirements. Keep these deployment changes separate
from the token comparison so its execution conditions remain stable.

Generated PHP should run on a **separate dedicated sandbox host/service** when
the application platform cannot provide gVisor. A trusted Docker SSH context is
supported by the existing runner. A public authenticated sandbox-service client,
tenant quotas, callback authentication, cancellation and durable supervision are
future work. Do not expose the Docker daemon publicly, give it to the model, or
send application credentials into the guest. A normal app worker/container that
inherits application secrets is not a substitute for a hostile-code sandbox.

## What the comparison proves

The [separate Data Surface comparison](../examples/drupal-surface-query/README.md#json-batch-comparison)
runs operator-written JSON and PHP against the same real MCP query, data,
identity and limits. It checks accessible results, empty results, hidden fields,
publication, revocation after discovery, restored reads and broker denials.
The batch returns two lists; PHP returns the combined result. That distinction
is retained in the evidence.

That reference run measures actual calls, elapsed time and serialized payloads.
Its offline token counts measure one copy of those captured payloads, not model
usage or cost. They describe the recorded tool definitions, not subsequent edits
to the batch tool's budget description.

## Live three-way Symfony audit

Run `php bin/console code-mode:audit-three-way --protocol=audit-catalog-v10 --no-debug`.
It requires the configured Hugging Face token and gVisor for the PHP path.
Each invocation retains a new capture under `var/demo/audit-three-way/`. The
read-only workbench's **Code Mode vs PHP to JSON** chapter shows every attempt,
generated PHP, literal JSON, actual tool results, draft, review and provider usage.
Static builds read only explicitly reviewed evidence. Viewing them makes no model call.

### Correct answers on all three paths

Attempt 13, captured on 9 November 2026, uses the Laravel-style two-tool catalog.
All three paths read the complete fixture and return the correct ten findings,
including exact titles and issue sets. Each starts a fresh conversation.

| Path | Input tokens | Output tokens | Total | Requests | Tool calls | Elapsed |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Direct tools | 41,396 | 5,042 | 46,438 | 12 | 10 | 8.8 s |
| JSON catalog | 24,708 | 5,090 | 29,798 | 6 | 17 | 5.8 s |
| Sandboxed PHP | 10,207 | 2,599 | 12,806 | 5 | 10 | 4.3 s |

JSON used **35.8% fewer tokens than direct tools**, without a generated-code
sandbox. PHP used **72.4% fewer than direct tools** and **57.0% fewer than JSON**.
JSON made seven extra empty-page reads; these are included, not normalized away.
This is a selected successful development attempt after several corrections,
not a reliability estimate or a promise of lower costs on every task.

The direct draft added a false finding for article 47; JSON missed article 44.
The same review contract corrected both. PHP's draft was already correct.
Every path asks the model to return either `{"decision":"keep"}` or
`{"decision":"replace","answer":{...}}`. The host applies that recorded decision;
it does not select the answer that passes the oracle. `keep` preserves even an
incorrect draft unchanged. Invalid decisions fail. Both the raw decision and
final answer are retained and checked against provider response digests.
Review costs are included: **8,474 direct**, **8,733 JSON**, **2,925 PHP** tokens.

### What stayed fixed, and what changed

All 39 runs used `openai/gpt-oss-120b`, the original audit question, the same 48
published articles and 24 images, approved schemas and read-only editor identity.
Base generation settings stayed temperature 0, 8,192 maximum output tokens and
medium reasoning effort. Hugging Face used automatic provider routing. Within
each triplet, review instructions and tool-result encoding match across paths;
interface-specific execution instructions necessarily differ.

All paths have fourteen model requests and sixty underlying calls available.
Reviewed protocols reserve one request for review. Handlers have three seconds,
a 64 MiB PHP heap and the same frame/result bounds. JSON permits fourteen
submissions of thirty calls; PHP permits two executions of thirty calls. Both
have a fifteen-second submission deadline. Direct reads use per-call deadlines.
Literal batches cannot consume earlier results inside the same submission, so
the submission limits differ. Full conversation history is retained.

The versioned protocols record the changes rather than comparing fresh JSON
against selected historical direct/PHP runs:

- Attempts 1–2 used the original guidance without a separate review.
- Attempt 3 added staged collection and a shared answer review. It used the
  earlier three-tool JSON interface: search, describe, execute.
- Attempts 4–5 changed JSON to `search_tools` and `execute_tools`, returning full
  contracts in search. Direct and PHP draft instructions stayed unchanged.
- Attempts 6–13 kept those draft instructions. They corrected the review phase
  and, from attempt 9 onward, serialized tool data as literal UTF-8 on **all three
  paths**. The decoded records did not change. The final protocol uses an explicit
  keep-or-replace review, with tools disabled and no forced JSON output mode.

The host never supplies expected findings, failed-check feedback or repaired PHP.
Strict correctness checks run after model execution. Broker authorization,
argument validation, application access checks and sandbox controls are unchanged.

### Every development attempt remains visible

| Attempt | Protocol | Reported tokens, all three paths | Outcome and learning |
| --- | --- | ---: | --- |
| 1 | Original | 100,332 | Direct missed article 11; JSON and PHP were correct. |
| 2 | Original | 101,864 | Direct extraction errors; JSON exhausted its request budget; PHP parse errors. |
| 3 | Reviewed v2 | 108,113 | All three correct after review. Historical three-tool JSON interface. |
| 4 | Catalog v3 | 62,315 | Direct missed a finding; JSON/PHP reviews returned tool calls. None executed. |
| 5 | Catalog v3 | 102,379 | Direct correct; JSON missed 38 and 44; PHP's correct draft was followed by a rejected review tool call. |
| 6 | Catalog v4 | 72,836 known | All review requests rejected: the provider required a `tools` field with `tool_choice`. Three requests have no reported usage. |
| 7 | Catalog v5 | 101,148 | Explicit `tools: []` accepted. JSON/PHP titles contained incorrect Unicode escapes. |
| 8 | Catalog v6 | 93,276 | Literal-character guidance alone did not fix the title errors. |
| 9 | Catalog v7 | 87,800 | Literal UTF-8 tool results; JSON/PHP correct, direct review corrupted a title. |
| 10 | Catalog v8 | 92,584 | Removed forced JSON mode. JSON/PHP correct; direct review dropped article 38 from a correct draft. |
| 11 | Catalog v8 | 89,174 | Unchanged repeat: direct review again dropped 38; JSON/PHP correct. |
| 12 | Catalog v9 | 93,363 | Review checked existing findings first. Direct review introduced a false issue; JSON/PHP correct. |
| 13 | Catalog v10 | 89,042 | Explicit keep-or-replace review; all three final answers correct. |

The [complete evidence](evidence/audit-three-way.json) retains **13 triplets / 39
runs**, totaling **1,194,226 reported tokens**, with hashes of original captures.
Three rejected review requests have unknown usage, not zero cost. Three separate
bounded provider-option probes are also retained: two rejected without usage,
one accepted with **230 tokens**. These are outside the audit totals. Provider
input/output pricing determines actual charges; these totals are not an invoice.

The earlier successful attempt 3 remains available: direct **49,595** tokens,
three-tool JSON **44,866**, PHP **13,652**, with 12 / 11 / 5 requests. Its direct
and JSON drafts also needed review. Do not mix those measurements with the newer
catalog protocol or the original 2 November two-way demo.

### What the failures actually taught us

The catalog's missed findings were already present in its tool results. Article
38 shared an image with an earlier article; article 44 had an empty description
on a later page. Discovery and permissions were not hiding those records.
The model lost findings while assembling the answer.

The PHP review failure was separate from execution: the program and its draft
were correct, but the review requested another tool. The host refused it. The
review now has its own system instruction, an explicit user turn, `tool_choice:
none` and `tools: []`. The empty list is required by the observed provider route.
Later attempts exposed title-encoding errors and reviews that damaged correct
drafts. Literal UTF-8 and an explicit review decision improved this example;
we have not established a universal provider defect or a general reliability fix.

This is why we borrow Laravel's simple catalog interface for **adoption**.
A stable search/execute contract avoids hosting generated PHP. It does not make
model extraction deterministic or remove the value of local computation. This
small catalog also does not isolate discovery savings from computation savings.
Repeated fixed-protocol evaluation is still needed before claiming reliability.

The separate Rosetta example supplies a typed Data Surface query. Useful
projections can reduce the model's assembly work while retaining publication,
entity and field access checks. That is an experimental integration, not evidence
that Drupal Core has adopted a general JSON query or workflow language.

## BAML is deferred

BAML's [REST/OpenAPI route for PHP](https://docs.boundaryml.com/guide/installation-language/rest-api-other-languages)
would add another service and contract translation. This proof already uses
Symfony AI for model orchestration and schemas. The current work is the simpler
Laravel-style catalog and reliable use of its results; no BAML dependency or
fourth demo is planned for this iteration.
