# Code Mode demo

The working slice is an editorial audit: check every published article for missing meta descriptions or hero-image alt text, with pagination, batched reads and a join between articles and media. It demonstrates orchestration in real PHP and authorization that stays outside generated code.

The first judge-facing demo is **Symfony-only**: the Symfony application runs the agent and coordinates the PHP MCP fixture and isolated runner. Drupal is an optional follow-on demo using the same Composer package, not a prerequisite for this one.

## Run and inspect

1. Install root Composer dependencies and set up the [dedicated gVisor host](../packages/php-code-mode/README.md#run-the-proof). Build the runner image, then configure its immutable `CODE_MODE_IMAGE`. For an alternate CLI, set `CODE_MODE_DOCKER_COMMAND` to a trusted JSON argv array. Symfony commands read these from `.env.local`; PHPUnit sandbox suites and standalone package examples need exported environment variables.
2. Run `composer demo`, then `composer start`. Open [the workbench](http://127.0.0.1:8000/demo). It shows the exact PHP, host-recorded calls, findings and comparison. Reference mode makes no provider calls. Reports stay in ignored `var/demo/latest.json` with owner-only file permissions; the newest run replaces the previous one.
3. For live generation, set `HUGGINGFACE_API_KEY` in ignored `.env.local` to a token with Inference Providers permission. Run `composer demo -- --mode=live`. Optionally use `--approach=code` or `--approach=direct`. Each path runs its own Symfony AI agent against `HUGGINGFACE_MODEL` (default `openai/gpt-oss-120b`). A missing key fails explicitly; there is no reference fallback.
4. Run `composer check`, then `composer test:sandbox`, then `composer test:demo`. The latter two require the pinned image/runtime and fail if unavailable. Use a dedicated endpoint and run them sequentially.

The browser cannot start executions. A fresh CLI invocation creates fresh Code Mode sessions. The MCP-facing server allows only two execution attempts over its process lifetime; restart it for a new operator-authorized task.

## Change the question

The workbench offers the original audit, a grouped impact question and a custom question. Select a question and copy the generated command into your terminal. Selecting a question does not change the previous recorded results: their exact question remains visible above the comparison. The browser has no execution or question-submission endpoint.

```bash
composer demo -- --mode=live --scenario=impact
composer demo -- --mode=live --question='Count published articles by language. Return counts.'
```

Both approaches receive exactly the same question. Symfony AI supplies the PHP for Code Mode; no application-specific orchestration tool is added when the question changes. The new questions use only the Symfony fixture backend. Each run creates fresh sessions with the existing host identity, three allowed read tools, gVisor profile and execution limits. Question text cannot select an endpoint, credential, policy or runtime profile. System prompts guide the agent; the broker and sandbox enforce access even if the agent follows a hostile question.

The impact scenario has its own independent fixture oracle: 48 articles, two groups of article IDs and three shared images without alt text. Custom questions return a JSON object and need successful MCP reads, but have no general correctness oracle. They are marked **review required** and never display a payload-savings badge. An error, invalid JSON or answer without supporting reads fails verification.

Custom input is limited to 2000 UTF-8 bytes. Terminal controls and invisible formatting characters are rejected. The page renders questions and results as text and quotes custom questions as one shell argument. Avoid including secrets: live questions are sent to the configured model provider and saved in the local report.

Changed questions require live inference; reference mode supports only the original audit and fails explicitly for other questions. Local tests supply mocked provider responses to verify question propagation, the alternate PHP program through real MCP/gVisor, and rejection of an attempted delete. This verifies the integration, **not a model's ability to generate the new program**. Live Hugging Face generation remains unverified.

Run the command-quoting checks with `node --test tests/Browser/demo-questions.test.mjs` (Node 18+). The PHP checks are included in `composer check`; the real-runtime checks are included in `composer test:demo` with the sandbox configuration exported as described above.

## What is measured

The fixture contains 48 published articles, 4 inaccessible drafts and 24 reused images. An independent expected result checks all 10 findings, titles, issue types and the audited count. Successful reads of all three data tools must support the answer. Both approaches get the same task, dataset, permissions and batch-capable MCP tools; the direct baseline is allowed to batch.

The reference algorithms make the same 10 MCP calls: 4 list pages, 4 detail batches, 2 media batches. They return the same findings. The direct path returns content through the agent tool interface; PHP filters it within gVisor and returns compact findings. Reference mode supplies its program from the repository and makes zero inference requests.

`toolPayloadBytes` counts the exact UTF-8 JSON strings returned by the application toolbox. Code Mode search/describe results and errors are included. Prompts, initial schemas, generated PHP and protocol framing are excluded. These are **payload bytes, not token estimates or billing totals**. Provider token usage is shown only when Symfony AI receives usage metadata; missing usage stays blank. Compare provider totals only for successful live runs of both paths. A single run is not a reliable performance benchmark.

The host ledger includes calls completed before failed attempts, so retries do not erase their work. `incomplete` means a result was not successfully received; upstream effects may already have happened. Failed final runs never display a payload-savings badge. Duration includes setup/model work and is not a pure sandbox speed comparison.

## External MCP clients

Configure a local MCP client with an absolute project path:

```json
{
  "mcpServers": {
    "php-code-mode": {
      "command": "php",
      "args": ["/absolute/path/symfony-hackathon/bin/console", "code-mode:mcp", "--no-debug"]
    }
  }
}
```

The server exposes `tools_search`, `tools_describe` and `php_execute`. Schemas are loaded progressively; execution can call only described tools. The server owns identity and policies. It is meant for a trusted local client, not anonymous network access. Run the audit prompt shown in the workbench, then inspect the returned host ledger. This external-client path does not write the comparison report.

## Drupal Tool API adapter

This is a later integration; the first demo and its reference dataset run without Drupal.

The integration uses [Tool API](https://www.drupal.org/project/tool), [MCP Server Tool Bridge](https://www.drupal.org/project/mcp_server_tool_bridge) and [Drupal MCP Server](https://www.drupal.org/project/mcp_server). The bridge exposes configured Tool API plugins and checks access against the MCP account. It does not itself provide the editorial audit tools: install or write read tools for published article metadata and media, with bounded pagination/batching and object access checks.

Set up a dedicated read-only Drupal account and MCP authentication, configure the Tool Bridge mappings, and inspect the actual names and schemas returned by that site's `tools/list`. Copy only approved read tools into `.env.local`; the names below are placeholders, not guaranteed Drupal tool names:

```dotenv
DRUPAL_MCP_URL=https://your-drupal.example/mcp
DRUPAL_MCP_TOKEN=your-read-only-account-token
DRUPAL_MCP_ALLOWED_TOOLS='["actual_list_tool","actual_article_tool","actual_media_tool"]'
```

Run `composer demo -- --mode=live --backend=drupal`, or add `--backend=drupal` to the STDIO server command. Redirects are disabled. HTTPS is required except for loopback development. Tokens and transport configuration stay in the host broker. Do not register broad admin, SQL, filesystem or arbitrary URL-fetching tools for this audit.

The gateway preserves structured results, including Tool API envelopes such as `success`, `message`, `data`; generated PHP must interpret the discovered schema and check success. The fixture oracle never marks Drupal findings correct: the UI asks for review against the site. The HTTP adapter has been tested against a real local MCP server, **not yet against an installed Drupal site**.

## 90-second recording plan

| Time | Show |
| --- | --- |
| 0–15 s | State the audit task: content passes repeatedly through an agent when PHP can batch, join and filter it. |
| 15–40 s | Run live Code Mode. Show discovery, actual model-generated PHP, and the verified comparison with the same batch-capable tools. |
| 40–60 s | Switch to the impact question. Show a different generated program and its grouped result using the same tools and limits. Rehearse inference timing before recording. |
| 60–80 s | Show real denied-tool and timeout checks, then explain the host policy and gVisor boundary. |
| 80–90 s | Show the reusable Composer package and Symfony integration. Mention Drupal briefly as a later demo built on the same foundation. |

For a short guardrail demonstration, export the sandbox variables and run:

```bash
php bin/phpunit -c packages/php-code-mode/phpunit.dist.xml --testsuite Sandbox --filter 'testDeniedCallNeverReachesHostHandler|CPU loop' --testdox
```

Before recording, verify a real Hugging Face run, retain its report, rehearse both paths, and keep the explicitly labelled reference run available if inference is unavailable. A reference recording cannot be presented as model generation. No submission video has been recorded or published. The [hackathon brief](https://symfony.com/hackathon) asks for working, runnable code and a 90-second demo by 22 November 2026, 18:00 CET.
