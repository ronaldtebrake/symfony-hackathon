# Symfony Code Mode

Let an agent write PHP to compose approved tools, then return the result.

An article audit might need to read several pages, join image metadata and collect missing descriptions. With direct tool calls, the model receives the intermediate results and coordinates each step. Code Mode lets PHP do the pagination, joins and filtering in one execution. The agent discovers the tools it needs and receives a compact answer.

The default runner executes generated PHP in gVisor. Your Symfony application owns the tool implementations, permissions and execution limits. Conversations stay in your MCP client or Symfony AI agent.

![Code Mode returns the same findings with 85% less tool-result payload in the model-free reference run.](docs/images/code-mode-value.svg)

*The animation illustrates a measured reference run: 20,154 → 3,014 tool-result bytes. It excludes prompts, initial schemas and generated code; no model was called.*

Built for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon), using **Symfony AI**, the **PHP MCP SDK** and **Hugging Face Inference Providers**. The runner and Symfony integration are reusable Composer packages.

## Getting started

You can inspect the recorded executions with Unix PHP 8.2+ CLI, `pcntl`, `posix` and Composer 2. Viewing the evidence requires no Docker or API key:

```sh
git clone https://github.com/ronaldtebrake/symfony-hackathon.git
cd symfony-hackathon
composer install
composer start
```

Browse the [visual documentation](https://ronaldtebrake.github.io/symfony-hackathon/) or open [the local workbench](http://127.0.0.1:8000/demo). It shows the agent conversation and follow-up, exact PHP, actual tool calls, measured model usage and security proofs. It is a read-only viewer of saved runs. To execute new PHP, choose an [execution profile](#execution-profiles) and connect an agent as described below.

The [Symfony performance conversation](docs/performance.md) shows a real agent correlating profiler records and logs, then verifying a prepared optimization: **13 queries → 1, identical output**. Inspect generated PHP, actual calls and a matched direct-tool baseline in [the local viewer](http://127.0.0.1:8000/demo#performance). Both approaches answer correctly; this small three-reader case uses fewer model requests with Code Mode, but more tokens.

## Use your Symfony tools

The Symfony bundle exposes three MCP tools: `tools_search`, `tools_describe` and `php_execute`. Your approved application tools remain behind the broker.

This checkout already registers two read services and a local demonstration identity:

```yaml
# config/packages/code_mode.yaml
code_mode:
    identity_provider: App\CodeMode\DemoIdentityProvider
    tools:
        - App\CodeMode\ReadArticle
        - App\CodeMode\ListPublishedArticles
```

Each service defines its schema, checks access and performs the read. Start from [ReadArticle](src/CodeMode/ReadArticle.php), [ListPublishedArticles](src/CodeMode/ListPublishedArticles.php) and the [identity provider](src/CodeMode/DemoIdentityProvider.php). Only explicitly registered tools enter the catalog. The demonstration identity grants published-content reads; it is not remote authentication.

Already using Symfony AI's `#[AsTool]` services? Connect their existing toolbox and an authorization policy instead:

```yaml
code_mode:
    identity_provider: App\CodeMode\DemoIdentityProvider
    ai_toolbox: app.publishing_toolbox
    ai_tools: [publishing_read, publishing_list]
    ai_authorization: App\CodeMode\PublishingAuthorization
```

This is an alternative configuration. The [adapter guide](packages/symfony-code-mode/README.md#reuse-existing-symfony-ai-tools) covers service wiring and policy contracts. Execution goes through Symfony AI's existing argument resolver and toolbox lifecycle. Tool annotations alone do not grant permission.

After configuring a runtime, check prerequisites:

```sh
php bin/console code-mode:doctor
```

Configure your MCP client to launch the following command with this checkout as its working directory:

```sh
php bin/console code-mode:serve --no-debug
```

It speaks MCP over STDIO; no web server is needed. For another Symfony application, register `CodeMode\Symfony\CodeModeBundle` and follow the [integration guide](packages/symfony-code-mode/README.md). The [independent Symfony example](examples/symfony/README.md) has its own Composer install, native AI tool and policy. Both packages currently use Composer path repositories and are **not yet published to Packagist**.

The [provider composition example](examples/composition/README.md) runs beside this demo with its own Composer install. It joins native Symfony AI and MCP tools in one isolated PHP program, with schema-derived PHP interfaces and a separate read-only proof viewer. Drupal can reuse the same core provider contract.

## Discover, then execute

Here is a small task using the checkout's two read services:

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

A follow-up uses the same conversation, identity and remaining execution budget. If the agent attempts a restricted read, the broker returns `tool_denied`. The agent can explain the boundary and propose a permitted alternative. [See the recorded denial and recovery](docs/demo.md#optional-boundary-recovery-opening).

Code Mode is useful when a task needs pagination, branching or several related reads. A simple lookup may need less work as a direct tool call. Discovery and sandbox startup have costs too.

## Execution profiles

Runtime selection belongs to the operator. An MCP argument cannot select an executor, grant permissions or increase limits.

| Profile | What you need | Execution boundary |
| --- | --- | --- |
| `gvisor` — default | Dedicated Linux Docker endpoint with `runsc`, plus a pinned PHP 8.4 runner image | Isolated execution with no guest network, host mounts or forwarded credentials |
| `local-unsafe` — explicit opt-in | Unix PHP CLI and the Composer dependencies | Same OS user; **no host filesystem or network isolation** |

For gVisor, follow the [runtime setup](packages/php-code-mode/README.md#run-the-proof), put the image ID and Docker command in ignored `.env.local`, then run `code-mode:doctor`. macOS can use a Linux VM. Ordinary Docker alone is insufficient; an unavailable gVisor runtime never triggers a local fallback.

For reviewed snippets in a disposable development environment, add this alongside the existing bundle configuration:

```yaml
when@dev:
    code_mode:
        executor: local-unsafe
```

Use `code-mode:doctor --env=dev`, then connect to `code-mode:serve --env=dev --no-debug`. The doctor and results explicitly label this **Unsandboxed local execution**. Broker checks still apply, but generated PHP can access host files and services directly. Timeouts and cleanup are best effort; this profile is unsuitable for hostile generated code. [Local-mode setup and limitations](packages/symfony-code-mode/README.md#php-only-development-opt-in).

The bundle defaults to **5 seconds per execution, 1 second per tool, 10 tool calls and two execution attempts**, including failures. gVisor adds a 128 MiB container limit; local mode has only the 32 MiB PHP heap limit. The separate editorial-audit harness uses a larger bounded profile, documented in its [demo guide](docs/demo.md).

The default security proof checks an authorized read, a denied tool call and an infinite loop terminated after a completed read, with container removal verified. Tool descriptions, CMS content and generated PHP remain untrusted. This is a tested development proof, not an audited public execution service. [Security model](packages/php-code-mode/docs/security.md) · [Verification scope](packages/php-code-mode/docs/verification.md).

## Measured results

The recorded editorial audit checks missing article descriptions and hero-image alt text. Symfony AI and Hugging Face ran both direct tools and Code Mode with the **same model, question, data and permissions**. Both returned all **10 findings across 48 articles**, with ten successful underlying reads per path:

| Matched live run | Direct tools | Code Mode |
| --- | ---: | ---: |
| Model requests | 11 | 5 |
| Provider tokens | 43,979 | 12,597 |

Code Mode used **71.4% fewer provider tokens in this successful pair**. All 21 development invocations, including failures, remain in the [comparison evidence and methodology](docs/huggingface-comparison.md). This is a measured example, not a general performance guarantee.

![Actual workbench showing both verified answers and measured provider usage.](docs/images/huggingface-matched-comparison.jpg)

A separate evaluation repeats five scenarios twice per approach. Its latest live batch passes **10/10 conversations on both sides**, with **63.7% fewer reported tokens** for Code Mode, including shared answer-review costs. Earlier failed batches remain available. [Evaluation protocol and results](docs/demo.md#repeatable-evaluation).

The current lean response format also reduces context overhead. Offline replay of retained responses measures **46.9% fewer response-body tokens for the recorded MCP conversation**, **69.7% for the Symfony AI adoption pair**, and **9.5% for the already compact Hugging Face audit**. All use the gpt-oss tokenizer; these are payload counts, not new provider usage or total conversation savings. Historical live captures remain unchanged. [Inspect and reproduce the measurement](docs/demo.md#lean-response-measurement).

## Run the demos

The commands below use gVisor, including when the bundle's `code-mode:serve` is configured for local development.

| Command | What it demonstrates |
| --- | --- |
| `composer demo` | Reference PHP: matching audit findings and measured tool-result bytes; no model call |
| `composer demo:security` | Authorized read, denied tool and terminated loop with real execution traces |
| `php bin/console code-mode:adoption-proof --no-debug` | Existing Symfony AI tools, denied access and a permitted follow-up; add `--live` for Hugging Face generation |
| `php bin/console code-mode:performance-proof --no-debug` | Approved Symfony profiler/log reads: 13 queries → 1 with identical output; fixed PHP, simulated latency disclosed |
| `php bin/console code-mode:evaluate --no-debug` | Five repeatable scenarios with correctness checks and an execution timeline; add `--live` for Hugging Face |

For the live matched comparison, set `HUGGINGFACE_API_KEY` in ignored `.env.local`, then run `php bin/console code-mode:huggingface-compare --no-debug`. Live commands consume inference credits. The saved evidence can be viewed without a token.

For the recorded agent audit and its follow-up, connect the separate `code-mode:mcp --no-debug` server using the [MCP client connection example](docs/codex.md). Its tool catalog differs from the small `code-mode:serve` walkthrough above. The [performance guide](docs/performance.md) supplies another agent connection and explains the scope of its synthetic profiler data. Conversations stay in the agent; the workbench does not start executions.

## Packages and contributions

- [PHP core package](packages/php-code-mode/README.md): runner, broker, bounded discovery and MCP gateway; independent of Symfony and model providers.
- [Symfony integration](packages/symfony-code-mode/README.md): approved services and native AI tools, session-scoped toolbox, MCP server and prerequisite checks.
- [Contribution proposals](docs/contributing/README.md): executable PHP MCP SDK and Symfony AI examples for future upstream discussions.
- [Optional Drupal example](examples/drupal/README.md): reuse through Tool API and MCP Server. The main hackathon demonstration is Symfony-focused.
- [Separate Data Surface query proof](examples/drupal-surface-query/README.md): Rosetta search through MCP and the same Code Mode provider, with field access, empty results and permission revocation verified.

Run `composer check` for configuration, application and package checks. With gVisor configured, run `composer test:sandbox` and `composer test:demo` sequentially.

[Evidence index](docs/evidence/README.md) · [90-second recording guide](docs/demo.md)

The hackathon deadline is **22 November 2026, 18:00 CET**. The final manual recording and formal submission remain pending.

## Publish the visual documentation

`composer docs:build` exports the same read-only proof viewers to `var/pages`. Preview with `php -S 127.0.0.1:8080 -t var/pages`. The build reads only the reviewed evidence in `docs/evidence`; it does not load `.env.local`, local reports, Docker or a model provider. Historical client names remain in original capture provenance; the documentation works with any MCP client or Symfony AI agent.

The [Pages workflow](.github/workflows/pages.yaml) builds pull requests and deploys `main` to GitHub Pages. Select **GitHub Actions** as the repository’s Pages source. Only the generated HTML, CSS, JavaScript, animation and fixed JSON exports are published. New executions still run through a local agent or the operator CLI.
