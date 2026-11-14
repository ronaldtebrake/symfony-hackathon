# Symfony Code Mode

A searchable catalog for approved Symfony AI and MCP tools, with JSON calls and optional sandboxed PHP.

Expose two stable tools: `search_tools` returns approved contracts and `execute_tools` invokes them with literal JSON arguments. Reuse Symfony AI schemas, Symfony/MCP providers and application access checks. There is no generated program in this path. The earlier sandboxed PHP implementation remains available as a historical reference and a computation option.

Your application owns the tool implementations, identity, permissions and execution limits. Conversations stay in your MCP client or Symfony AI agent. Generated PHP requires gVisor. The JSON catalog runs as normal Symfony services over authenticated HTTP, with no worker, Docker or process-control extensions.

![The same 48 articles and 10 findings, with 43,979 versus 12,597 provider tokens in one matched Symfony AI and Hugging Face pair.](docs/images/code-mode-value.svg)

*Illustrated flow of the reviewed 2 November 2026 pair: 71.4% fewer input + output tokens and 11 → 5 model requests. Same model, question, data and permissions. One verified pair, not a billing estimate.*

Built for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon), using **Symfony AI**, the **PHP MCP SDK** and **Hugging Face Inference Providers**. The runner and Symfony integration are reusable Composer packages.

[Watch the silent 90-second product demo](https://ronaldtebrake.github.io/symfony-hackathon/#demo-tour): real agent footage and permission revocation, with Maker, a shared Symfony voter and profiler traces.

## How the goal evolved

The PHP audit worked: an agent could paginate, join and filter approved tool results inside gVisor. Making that practical for other Symfony applications meant asking developers to operate a separate Linux runtime and runner image alongside their app.

The content-query experiment showed where we could simplify. The application already knew how to execute a query and check access, and MCP exposed its JSON Schema contracts. An agent could call that existing handler with JSON arguments. We kept the approved providers and policies, and added `search_tools` and `execute_tools` over them.

PHP remains useful when computation can reduce what returns to the model. JSON makes ordinary tool calls easier to deploy. Both paths keep host-owned permissions and execution limits.

The [visual walkthrough](https://ronaldtebrake.github.io/symfony-hackathon/#version-two) follows those decisions.

The [current catalog evidence](https://ronaldtebrake.github.io/symfony-hackathon/#catalog-evidence) includes five real Symfony AI conversations, the follow-up and boundary recovery, operator control traces and a fresh Symfony consumer without Docker. All final answers match; four scenarios pass fully. In the injection scenario the agent attempted an unwanted restricted read, which the host denied. We retain that failure alongside the successful work. Earlier PHP captures remain unchanged.

**Catalog size and task cost are different measurements.** In an offline four-field-tool fixture, our advertised catalog stays at **287 tokens** from 3 to 100 tools; advertising 100 directly uses **8,605**. Search results and conversation history still consume tokens. Those counts exclude model framing and are separate from provider-reported audit usage. [Measurement and scope](docs/batching.md#catalog-overhead-and-conversation-cost).

## Getting started

You can inspect the recorded executions with PHP 8.2+ and Composer 2. Viewing the evidence requires no Docker or API key:

```sh
git clone https://github.com/ronaldtebrake/symfony-hackathon.git
cd symfony-hackathon
composer install
composer start
```

Browse the [visual documentation](https://ronaldtebrake.github.io/symfony-hackathon/) or open [the local workbench](http://127.0.0.1:8000/demo). It shows current JSON conversations and follow-ups, actual tool calls, measured model usage and security proofs, with generated PHP preserved in the historical chapters. It is a read-only viewer of saved runs. To run new work, choose an [execution path](#execution-paths) and connect an agent as described below.

## Reproduce the current proofs

```sh
# Actual MCP reads, control probes and fixed reference algorithms; no inference:
php bin/console code-mode:catalog-proof --no-debug
# Same scenarios with real Symfony AI + Hugging Face (uses inference credits):
php bin/console code-mode:catalog-proof --live --no-debug
# Independent consumer, own dependencies and policy; no Docker:
composer install --working-dir=examples/symfony
composer proof:catalog --working-dir=examples/symfony
```

Every catalog capture is retained under ignored `var/demo/catalog-proof/`. Pages publishes only reviewed evidence. [Coverage, outcomes and remaining gaps](docs/batching.md#evidence-coverage-and-next-captures).

## Use your Symfony tools

With `mode: catalog`, the Symfony bundle exposes two MCP tools: `search_tools` and `execute_tools`. Your approved application tools remain behind the broker.

Start with the [independent Symfony HTTP application](examples/symfony/README.md). It has its own dependencies, native Symfony AI tool and access policy:

```sh
composer install --working-dir=examples/symfony
php examples/symfony/bin/console code-mode:doctor
composer proof:http --working-dir=examples/symfony
```

Register approved native tools and reuse your Symfony voters for discovery and reads:

```yaml
code_mode:
    mode: catalog
    http:
        enabled: true
        permissions:
            publication.read_published: ROLE_READER
    ai_tool_services: [App\ArticleTool]
    ai_tools: [publication_read]
    voter_rules:
        publication_read:
            discover: PUBLICATION_DISCOVER
            execute: PUBLICATION_READ
            subject: id
```

Expose `/mcp` through Symfony's MCP bundle and protect it with your normal authenticator. The [integration guide](packages/symfony-code-mode/README.md#enable-http) supplies the routing and registry configuration. For the local example, set its ignored bearer token and run `composer start --working-dir=examples/symfony`; connect your agent to `http://127.0.0.1:8001/mcp`. [Client setup](docs/codex.md).

The application controls identity and access. Symfony AI's existing argument resolver and toolbox execute each approved handler; annotations alone do not grant permission. Budgets persist across HTTP requests and reconnects. No worker is involved. Both packages use Composer path repositories and are **not yet published to Packagist**.

With MakerBundle installed, `php bin/console make:code-mode-tool InventoryLookup` generates a native tool, a deny-by-default voter, explicit registration and a security test. The optional **Code Mode profiler panel** shows discovery counts, budget reservations, broker decisions and native Symfony AI events without adding traces to agent responses. [Scaffolding, shared voters and local profiling](packages/symfony-code-mode/README.md#native-symfony-development).

The [provider composition example](examples/composition/README.md) runs beside this demo with its own Composer install. It joins native Symfony AI and MCP tools in one isolated PHP program, with schema-derived PHP interfaces and a separate read-only proof viewer.

## Discover, then execute

For a read of published article 7, the agent first calls `search_tools`:

```json
{"query":"articles_read"}
```

Search returns the full approved contract. It matches names, descriptions and
schema fields, with bounded results and explicit truncation. Search does not
confer permission. The agent then calls `execute_tools`:

```json
{"calls":[{"name":"articles_read","arguments":{"id":7}}]}
```

The broker checks the caller, tool and arguments before invoking the handler.
A call list may contain one read or several independent reads. Results remain
separate; dependent calls need a later submission. There are no expressions,
loops or result-reference syntax. Keep the same session and remaining budget for
follow-ups. [Contracts, limits and live comparisons](docs/batching.md).

<details>
<summary>Version one: the sandboxed PHP walkthrough</summary>

### Historical PHP walkthrough

With `mode: php` and gVisor configured, here is the original composition walkthrough:

> Find published articles with an empty meta description. Return their IDs and the number of articles checked.

### 1. Find the relevant tools

The agent calls `tools_search` with:

```json
{"query":"published articles"}
```

Search returns matching names and descriptions. It does not enable tool execution. The response wraps those tools in `tools` and reports `matched`, `returned`, `limit: 8` and `truncated`. All query terms must match. Refine a truncated search with an action or field, or use an exact tool name; zero matches suggest fewer terms or a synonym.

### 2. Inspect the full schema

The agent calls `tools_describe` for the selected tools before using them. For example:

```json
{"names":["articles_list_published"]}
```

The response includes the input schema and a PHP invocation guide. This tool accepts a `page` integer, returns twelve published articles per page, and provides `nextPage` until the list is exhausted. JSON objects become PHP `stdClass` values; JSON lists become arrays.

Only tools whose contracts were disclosed in this session can be called by the program. Every call must also pass the host's authorization and argument validation.

### 3. Compose the reads in PHP

The agent submits this function body as the `code` argument to `php_execute`, without a `<?php` tag:

```php
$page = 1;
$audited = 0;
$missing = [];

do {
    $batch = $tools->call('articles_list_published', ['page' => $page]);

    foreach ($batch->items as $article) {
        ++$audited;
        if (trim($article->meta_description) === '') {
            $missing[] = $article->id;
        }
    }

    $page = $batch->nextPage;
} while ($page !== null);

return ['audited' => $audited, 'missingDescriptions' => $missing];
```

Against the supplied fixture, the returned `value` is:

```json
{"audited":48,"missingDescriptions":[11,22,33,44]}
```

That is one PHP execution with **four broker calls**. The agent receives `{"value": ...}`. The host retains the detailed call ledger, duration and run ID for optional diagnostics; they are not repeated in the model response. The PHP return value remains untrusted program output. [Capture host evidence](packages/symfony-code-mode/README.md#agent-responses-and-host-evidence).

A follow-up uses the same conversation, identity and remaining execution budget. If the agent attempts a restricted read, the broker returns `tool_denied`. The agent can explain the boundary and propose a permitted alternative. [See the recorded denial and recovery](https://ronaldtebrake.github.io/symfony-hackathon/#recovery).

Code Mode is useful when a task needs pagination, branching or several related reads. A simple lookup may need less work as a direct tool call. Discovery and sandbox startup have costs too.


</details>

## Connect a Symfony application over HTTP

The [independent application](examples/symfony/README.md) exposes `search_tools` and `execute_tools` at `/mcp`. Register native Symfony AI tools, approve their names, and map Symfony roles to application permissions. The agent connects using the URL and its application bearer token.

The [fresh HTTP proof](docs/evidence/http-catalog-proof.json) passes **31 checks** with process creation disabled: published article 101 returned, restricted article 202 denied, authentication and origin checks, and budgets preserved after reconnecting and restarting the server. Discovery also filters contracts by the authenticated user’s permissions; execution rechecks visibility and resource access. Its own Composer install had no root autoloader or package sources. This is an operator proof; retained model-token comparisons are separate historical measurements.

```sh
composer install --working-dir=examples/symfony
composer proof:http --working-dir=examples/symfony
```

No worker option exists for the JSON path. A third execution is refused after the example's two attempts, including failures. Only the operator can reset the authenticated user's budget. [Setup, authentication and recording](examples/symfony/README.md).

## Execution paths

| Path | What you need | What executes |
| --- | --- | --- |
| Historical PHP — explicit `mode: php` | Dedicated Linux Docker endpoint with `runsc` and a pinned runner image | PHP in gVisor, with no guest network, host mounts or forwarded credentials |
| JSON catalog — `mode: catalog` (default) | Normal PHP/Symfony hosting; Symfony Security and persistent budget storage | Approved application services; no generated code, worker or Docker |

For gVisor, follow the [runtime setup](packages/php-code-mode/README.md#sandboxed-php), put the image ID and Docker command in ignored `.env.local`, then run `code-mode:doctor`. macOS can use a Linux VM. Ordinary Docker alone is insufficient. There is no unsafe local PHP executor or automatic fallback.

For the JSON catalog, add `mode: catalog` alongside your approved tools and identity provider. `code-mode:serve` exposes `search_tools` and `execute_tools`. Each call uses the same authorization and schema checks. Results stay separate; the model combines them. The earlier `mode: batch` interface with a separate describe step remains available for reproducing its captures. [Batch format, limits and hosted Symfony requirements](docs/batching.md).

The JSON HTTP endpoint uses Symfony MCP and Security. It enforces permissions, argument validation and per-user call budgets across requests and restarts. Deadlines are cooperative; hosting and downstream clients must enforce hard timeouts. The default persistent state/lock works on one application host. Generated PHP remains a separate historical gVisor path.

The bundle defaults to **5 seconds per execution/batch, 1 second per tool, 10 tool calls and two attempts**, including failures. gVisor adds a 128 MiB container limit. The separate editorial-audit harness uses a larger bounded profile, documented in its [demo guide](docs/demo.md).

The security proof checks an authorized read, a denied tool call and an infinite loop terminated after a completed read, with container removal verified. Tool descriptions, CMS content and generated PHP remain untrusted. This is a tested development proof, not an audited public execution service. [Security model](packages/php-code-mode/docs/security.md) · [Verification scope](packages/php-code-mode/docs/verification.md).

## Measured results

The latest Symfony AI + Hugging Face comparison keeps **model, question, data and permissions identical** across all three paths. Each reads all **48 articles** and returns the correct **10 findings**, including exact titles and issues:

| Matched live run · 9 November 2026 · `audit-catalog-v10` | Direct tools | JSON catalog | Sandboxed PHP |
| --- | ---: | ---: | ---: |
| Model requests, including review | 12 | 6 | 5 |
| Provider input + output tokens | 46,438 | 29,798 | 12,806 |
| Actual underlying tool calls | 10 | 17 | 10 |
| Elapsed time | 8.8 s | 5.8 s | 4.3 s |
| Generated-code sandbox | None | None | gVisor |

All totals include model review, which corrected the direct and JSON drafts and kept PHP's correct draft. JSON made seven extra empty-page reads; those costs count. This is one matched example, not a reliability estimate or a promise of lower bills. [Methodology and complete capture archive](docs/batching.md#live-three-way-symfony-audit).

The animated PHP explainer uses the separate **2 November** pair: 43,979 → 12,597 tokens. Its 71.4% reduction must not be attributed to JSON. [Inspect that original comparison](https://ronaldtebrake.github.io/symfony-hackathon/#live-comparison).

A smaller [performance investigation](docs/performance.md) gives the counterexample: both paths answer correctly, but PHP uses **14,694 tokens** versus **8,217** for direct tools. Choosing the execution path depends on the work. JSON has not been measured on that task.

### When could PHP become easier to adopt?

A managed isolated executor could keep computation close to tools without asking each application to operate gVisor. [Cloudflare's isolated-worker approach](https://github.com/cloudflare/agents/tree/main/packages/codemode) illustrates that deployment model for JavaScript. It is not a PHP runtime or an integration we ship. A PHP equivalent needs verified isolation, authenticated access, deadlines and cleanup, followed by matched cost and latency measurements. [Explore the next steps](https://ronaldtebrake.github.io/symfony-hackathon/#explore).

## Additional proofs

These audit, performance and evaluation commands use the PHP runner and require gVisor. They are separate from the JSON catalog quickstart.

| Command | What it demonstrates |
| --- | --- |
| `composer demo` | Reference PHP: matching audit findings and measured tool-result bytes; no model call |
| `composer demo:security` | Authorized read, denied tool and terminated loop with real execution traces |
| `php bin/console code-mode:adoption-proof --no-debug` | Existing Symfony AI tools, denied access and a permitted follow-up; add `--live` for Hugging Face generation |
| `php bin/console code-mode:performance-proof --no-debug` | Approved Symfony profiler/log reads: 13 queries → 1 with identical output; fixed PHP, simulated latency disclosed |
| `php bin/console code-mode:evaluate --no-debug` | Five repeatable scenarios with correctness checks and an execution timeline; add `--live` for Hugging Face |
| `php bin/console code-mode:audit-three-way --protocol=audit-catalog-v10 --no-debug` | Live direct/JSON/PHP audit with the same model, data and permissions; shared review and all attempt costs retained |

For the live matched comparison, set `HUGGINGFACE_API_KEY` in ignored `.env.local`, then run `php bin/console code-mode:huggingface-compare --no-debug`. Live commands consume inference credits. The saved evidence can be viewed without a token.

For the recorded agent audit and its follow-up, connect the separate `code-mode:mcp --no-debug` server using the [MCP client connection example](docs/codex.md). Its tool catalog differs from the small `code-mode:serve` walkthrough above. The [performance guide](docs/performance.md) supplies another agent connection and explains the scope of its synthetic profiler data. Conversations stay in the agent; the workbench does not start executions.

## Packages and contributions

- [PHP core package](packages/php-code-mode/README.md): runner, broker, bounded discovery and MCP gateway; independent of Symfony and model providers.
- [Symfony integration](packages/symfony-code-mode/README.md): approved services and native AI tools, session-scoped toolbox, MCP server and prerequisite checks.
- [Contribution proposals](docs/contributing/README.md): executable PHP MCP SDK and Symfony AI examples for future upstream discussions.

Run `composer check` for configuration, application and package checks. With gVisor configured, run `composer test:sandbox` and `composer test:demo` sequentially.

[Evidence index](docs/evidence/README.md) · [90-second recording guide](docs/demo.md)

The hackathon deadline is **22 November 2026, 18:00 CET**. The final manual recording and formal submission remain pending.

## Publish the visual documentation

`composer docs:build` exports the same read-only proof viewers to `var/pages`. Preview with `php -S 127.0.0.1:8080 -t var/pages`. The build reads only the reviewed evidence in `docs/evidence`; it does not load `.env.local`, local reports, Docker or a model provider. Historical client names remain in original capture provenance; the documentation works with any MCP client or Symfony AI agent.

The [Pages workflow](.github/workflows/pages.yaml) builds pull requests and deploys `main` to GitHub Pages. Select **GitHub Actions** as the repository’s Pages source. Only the generated HTML, CSS, JavaScript, animation and fixed JSON exports are published. New executions still run through a local agent or the operator CLI.
