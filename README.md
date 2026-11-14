# Symfony Code Mode

A searchable catalog for approved Symfony AI and MCP tools, with JSON calls and optional sandboxed PHP.

Expose two stable tools: `search_tools` returns approved contracts and `execute_tools` invokes them with literal JSON arguments. Reuse Symfony AI schemas, Symfony/MCP providers and application access checks. There is no generated program in this path. The earlier sandboxed PHP implementation remains available as a historical reference and a computation option.

Your application owns the tool implementations, identity, permissions and execution limits. Conversations stay in your MCP client or Symfony AI agent. Generated PHP requires gVisor. The JSON catalog runs as normal Symfony services over authenticated HTTP, with no worker, Docker or process-control extensions.

Built for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon), using **Symfony AI**, the **PHP MCP SDK** and **Hugging Face Inference Providers**.

[Watch the silent 90-second demo](https://ronaldtebrake.github.io/symfony-hackathon/#demo-tour) · [Visual quickstart](https://ronaldtebrake.github.io/symfony-hackathon/#quickstart)

## Judge quickstart

Use **PHP 8.2+, Composer 2 and Git**. This path runs a real Symfony HTTP application with its own dependencies. The proof needs no model key, Docker or gVisor; Composer checks the required PHP extensions.

### 1. Clone, install and prove the boundary

```sh
git clone https://github.com/ronaldtebrake/symfony-hackathon.git
cd symfony-hackathon
composer install --working-dir=examples/symfony
php examples/symfony/bin/console code-mode:doctor
composer proof:http --working-dir=examples/symfony
```

Expected: the doctor reports ready; the proof returns `"status": "passed"` with **31 passing checks**. It reads published article **101**, denies restricted article **202**, revokes permission after discovery and denies the repeated read. It also checks authentication, cross-user isolation and persistent budgets. The harness starts and stops a temporary server with disposable credentials; it calls no model.

### 2. Connect your agent

Generate a local bearer token:

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Save that value in `examples/symfony/.env.local` (ignored by Git; preserve any other settings):

```dotenv
ADOPTION_MCP_TOKEN=<the generated value>
```

Prepare a new take, then leave this server running:

```sh
php examples/symfony/bin/console demo:reader-access grant
php examples/symfony/bin/console code-mode:reset-budget demo-reader
composer start --working-dir=examples/symfony
```

Connect your MCP client to **http://127.0.0.1:8001/mcp**, using `Authorization: Bearer <the same value>`. It should advertise only `search_tools` and `execute_tools`. The client supplies its own model access; no Hugging Face key is needed for this conversation. [Client configuration](docs/codex.md#connect-the-json-catalog).

Ask in a new conversation:

> Use only this Symfony MCP connection. Discover publication_read, read article 101 and tell me its title. Do not use workspace files or shell commands.

Expected title: **A Symfony application owns its tools**. The exact JSON call is shown [below](#discover-then-execute).

### 3. Revoke permission and repeat the read

In a second terminal, from the repository root:

```sh
php examples/symfony/bin/console demo:reader-access revoke
```

In the **same chat**, ask:

> The operator revoked my read permission. Repeat the exact article 101 read once using the contract already discovered. Do not search first or retry. Explain the result without presenting the previous title as a fresh read.

Expected: `tool_denied`, `completedCalls: 0`, and no new article data. Keep the same user and connection; **do not reset the budget between reads**. The two attempts include the denial. A subsequent search hides the contract. Before another take, grant access and reset the budget again.

The [independent app guide](examples/symfony/README.md) explains the shared voter, Maker command, profiler and hosting limits. Production apps use their own authentication and permission store; the example uses a local bearer token and permission fixture.

## The evidence behind the design

| What was measured | Result | Scope |
| --- | --- | --- |
| JSON catalog vs. direct tools | **35.8% fewer provider tokens**: 46,438 → 29,798 | One matched 9 November audit; same model, question, data and permissions; 10 correct findings; reviews included. Captured before the HTTP migration. |
| Historical sandboxed PHP vs. direct tools | **71.4% fewer provider tokens**: 43,979 → 12,597 | Separate 2 November pair illustrated below. This percentage is for PHP, not JSON. |

These are provider-reported input + output tokens, not invoices or universal savings. The current HTTP runtime has its own execution proof and has not been re-benchmarked. [Inspect the comparisons and all attempts](https://ronaldtebrake.github.io/symfony-hackathon/#three-way).

**The original sandboxed-PHP explainer**

![Historical sandboxed PHP audit: the same 48 articles and 10 findings, with 43,979 versus 12,597 provider tokens.](docs/images/code-mode-value.svg)

*Historical PHP pair, 2 November 2026: 71.4% fewer tokens and 11 → 5 model requests. The JSON result above comes from a separate matched audit.*

## How the goal evolved

The PHP audit worked: an agent could paginate, join and filter approved tool results inside gVisor. Making that practical for other Symfony applications meant asking developers to operate a separate Linux runtime and runner image alongside their app.

The content-query experiment showed where we could simplify. The application already knew how to execute a query and check access, and MCP exposed its JSON Schema contracts. An agent could call that existing handler with JSON arguments. We kept the approved providers and policies, and added `search_tools` and `execute_tools` over them.

PHP remains useful when computation can reduce what returns to the model. JSON makes ordinary tool calls easier to deploy. Both paths keep host-owned permissions and execution limits.

The [visual walkthrough](https://ronaldtebrake.github.io/symfony-hackathon/#version-two) follows those decisions.

The [current catalog evidence](https://ronaldtebrake.github.io/symfony-hackathon/#catalog-evidence) includes five real Symfony AI conversations, the follow-up and boundary recovery, operator control traces and a fresh Symfony consumer without Docker. All final answers match; four scenarios pass fully. In the injection scenario the agent attempted an unwanted restricted read, which the host denied. We retain that failure alongside the successful work. Earlier PHP captures remain unchanged.

**Catalog size and task cost are different measurements.** In an offline four-field-tool fixture, our advertised catalog stays at **287 tokens** from 3 to 100 tools; advertising 100 directly uses **8,605**. Search results and conversation history still consume tokens. Those counts exclude model framing and are separate from provider-reported audit usage. [Measurement and scope](docs/batching.md#catalog-overhead-and-conversation-cost).

## Explore the saved evidence

The [published workbench](https://ronaldtebrake.github.io/symfony-hackathon/) includes the video, actual calls, model usage and security proofs. To run the same read-only viewer locally, from the repository root:

```sh
composer install
composer start
```

Open **http://127.0.0.1:8000/demo**. This viewer needs no API key or Docker and serves the video with seekable chapters. The separate HTTP application above runs on port **8001**; conversations stay in your agent.

## Additional catalog checks

After the root `composer install`, these commands exercise the supporting catalog harness:

```sh
# Five fixed reference scenarios and actual MCP reads; no inference:
php bin/console code-mode:catalog-proof --no-debug
# Optional: the same scenarios with paid Symfony AI + Hugging Face inference.
# First set HUGGINGFACE_API_KEY in the root's ignored .env.local:
php bin/console code-mode:catalog-proof --live --no-debug
# Separate local-operator STDIO proof (15 checks), not the HTTP quickstart:
composer proof:catalog --working-dir=examples/symfony
```

Captures remain under ignored `var/demo/catalog-proof/`. Pages publishes reviewed evidence only. The [HTTP quickstart](#judge-quickstart) is the current judge-facing path. [Evidence coverage](docs/batching.md#evidence-coverage-and-next-captures).

## Use your Symfony tools

With `mode: catalog`, the Symfony bundle exposes two MCP tools: `search_tools` and `execute_tools`. Your approved application tools remain behind the broker.

The [judge quickstart](#judge-quickstart) runs the independent application. To bring the same integration to your own app, use the [bundle guide](packages/symfony-code-mode/README.md#enable-http).

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

For published article **101** in the quickstart application, the agent first calls `search_tools`:

```json
{"query":"publication_read"}
```

Search returns the full approved contract. It matches names, descriptions and
schema fields, with bounded results and explicit truncation. Search does not
confer permission. The agent then calls `execute_tools`:

```json
{"calls":[{"name":"publication_read","arguments":{"id":101}}]}
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

Run `composer check` for coding standards, configuration, application and package checks. `composer cs:check` checks PHP style without modifying files; `composer cs:fix` applies fixes. Review the diff and rerun the tests after fixing. CI runs the same checks on every push and pull request.

The locked PHP CS Fixer shim and [configuration](.php-cs-fixer.dist.php) follow [Symfony AI's rule sets](https://github.com/symfony/ai/blob/main/.php-cs-fixer.dist.php), preserving our strict typing, binary stream flags and standalone guest API name. Coverage includes both packages, both example apps, tests and PHP CLI commands. Dependencies, generated caches, captured evidence and Maker templates are excluded; rendered Maker output is checked separately. These are coding-standard checks, not a security audit or a substitute for static analysis.

With gVisor configured, run `composer test:sandbox` and `composer test:demo` sequentially.

[Evidence index](docs/evidence/README.md) · [90-second recording guide](docs/demo.md)

The finished [90-second video](https://ronaldtebrake.github.io/symfony-hackathon/#demo-tour) and [draft submission description](docs/demo.md#submission-description) are ready. Attach the video and submit through SymfonyConnect before **22 November 2026, 18:00 CET**.

## Publish the visual documentation

`composer docs:build` exports the read-only proof viewers to `var/pages` for static hosting. Preview the same viewer locally with `composer start` at http://127.0.0.1:8000/demo, including video seeking. The build reads reviewed evidence in `docs/evidence` and explicitly selected media in `public/media`; it does not load `.env.local`, local reports, Docker or a model provider. Historical client names remain in original capture provenance; the documentation works with any MCP client or Symfony AI agent.

The [Pages workflow](.github/workflows/pages.yaml) builds pull requests and deploys `main` to GitHub Pages. Select **GitHub Actions** as the repository’s Pages source. Only the generated HTML, CSS, JavaScript, animation, reviewed JSON exports and curated video assets are published. Raw recordings and private profiler storage stay out of the repository. New executions still run through a local agent or the operator CLI.
