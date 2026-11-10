# Use the Symfony JSON catalog from Codex

The current product demo connects Codex to the [independent Symfony application](../examples/symfony/README.md). Codex manages the conversation; Symfony AI supplies the registered tool schema and handler execution. The agent sends literal JSON to `search_tools` and `execute_tools`. This connection does not execute generated PHP or use gVisor.

## Connect the JSON catalog

Install and check the example from the repository root:

```sh
composer install --working-dir=examples/symfony
ADOPTION_MODE=catalog php examples/symfony/bin/console code-mode:doctor
composer proof:http --working-dir=examples/symfony
```

Start the example as described in its [HTTP quickstart](../examples/symfony/README.md#start-the-application). Make its bearer token available to the Codex process as `SYMFONY_CATALOG_TOKEN`. The example's `.env.local` is loaded by Symfony, **not by Codex**. Keep the token out of tracked configuration and screenshots.

Add this to the project's ignored `.codex/config.toml`:

```toml
[mcp_servers.symfony_catalog]
url = "http://127.0.0.1:8001/mcp"
bearer_token_env_var = "SYMFONY_CATALOG_TOKEN"
enabled_tools = ["search_tools", "execute_tools"]
startup_timeout_sec = 20
tool_timeout_sec = 15
required = true
```

The Codex process must inherit that environment variable; restart the app from an environment that supplies it if needed. Run `codex mcp get symfony_catalog`, reconnect in MCP settings, and inspect `/mcp`. Project settings require a trusted project. The desktop app and CLI share configuration; the `url` and `bearer_token_env_var` settings are documented in [OpenAI's MCP guide](https://developers.openai.com/codex/mcp/). Client timeouts do not increase server budgets.

The server runs ordinary Symfony HTTP requests with its own dependencies. No worker, Docker, gVisor or Hugging Face key is involved. Codex uses its existing model access. This guide does not automatically change your client configuration.

## Record the actual conversation

Use the [two exact prompts and shot list](demo.md#exact-prompts). First read published article **101**, then request **202** once in the same chat. Confirm the actual responses: the public title and a sanitized `tool_denied`, with no restricted data. A permitted alternative can reuse the public result already retrieved.

The authenticated user has **two execute submissions, including failures**; discovery does not consume them. The budget survives new connections and server restarts. Before another take, the operator runs `php examples/symfony/bin/console code-mode:reset-budget demo-reader`. Do not ask the agent to restart it or bypass a denial. Use only this MCP connection to retrieve demo data; its policy cannot control a client's separate shell or filesystem access.

Expand the client's tool records to show exact JSON arguments and responses. Save the conversation/tool export and original footage privately before editing. The stock catalog command does **not** support `--trace-file`; that option is PHP-only. The [workbench proof](https://ronaldtebrake.github.io/symfony-hackathon/#native-rehearsal) shows the new HTTP operator capture alongside the installed handler and policy. It is not a live mirror of your chat. The earlier native client rehearsal and Hugging Face comparisons remain separately labelled; they predate the HTTP migration. Save new takes separately.

## Historical generated-PHP connection

The original root-demo connection uses `code-mode:mcp --backend=fixture --no-debug`, exposing `tools_search`, `tools_describe` and `php_execute`. It requires the dedicated [gVisor runtime](../packages/php-code-mode/README.md#sandboxed-php). Keep it separate from `symfony_catalog`; the root demo and the small independent application have different tools and data.

The retained [original conversation](evidence/codex-conversation-2026-11-01.json) shows that earlier PHP path. It is not the JSON recording above. Its generated source and execution evidence remain available under Version one in the workbench.
