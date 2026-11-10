# Use the Symfony JSON catalog from Codex

The current product demo connects Codex to the [independent Symfony application](../examples/symfony/README.md). Codex manages the conversation; Symfony AI supplies the registered tool schema and handler execution. The agent sends literal JSON to `search_tools` and `execute_tools`. This connection does not execute generated PHP or use gVisor.

## Connect the JSON catalog

Install and check the example from the repository root:

```sh
composer install --working-dir=examples/symfony
ADOPTION_MODE=catalog php examples/symfony/bin/console code-mode:doctor
composer proof:catalog --working-dir=examples/symfony
```

Add this server to the project's ignored `.codex/config.toml`, replacing the paths with your absolute paths. It has a distinct name so it cannot be confused with the historical PHP server.

```toml
[mcp_servers.symfony_catalog]
command = "/absolute/path/to/php"
args = ["/absolute/path/symfony-hackathon/examples/symfony/bin/console", "code-mode:serve", "--no-debug"]
cwd = "/absolute/path/symfony-hackathon/examples/symfony"
env = { ADOPTION_MODE = "catalog" }
enabled_tools = ["search_tools", "execute_tools"]
startup_timeout_sec = 20
tool_timeout_sec = 15
required = true
```

Run `codex mcp get symfony_catalog` from the repository. Restart the connection in MCP settings, then inspect `/mcp` in the client. Project settings require a trusted project. The desktop app and CLI share MCP configuration; see [OpenAI's MCP documentation](https://developers.openai.com/codex/mcp/). The client timeout does not increase the server's execution budgets.

The server uses Unix PHP CLI with `pcntl` and `posix`. It starts the example's Symfony kernel over STDIO, so no HTTP server is needed. It loads the example's own configuration, not the root demo's `.env.local`. No model token is needed in this application: Codex uses its existing model access. No connection changes are made just by reading this guide.

## Record the actual conversation

Use the [two exact prompts and shot list](demo.md#exact-prompts). First read published article **101**, then request **202** once in the same chat. Confirm the actual responses: the public title and a sanitized `tool_denied`, with no restricted data. A permitted alternative can reuse the public result already retrieved.

Keep the server process alive between turns. It permits **two execute submissions, including failures**; discovery does not consume these attempts. Restart the connection before another take. Do not ask the agent to restart it or bypass a denial. Use only this MCP connection to retrieve demo data; its policy cannot control a client's separate shell or filesystem access.

Expand the client's tool records to show exact JSON arguments and responses. Save the conversation/tool export and original footage privately before editing. The stock catalog command does **not** support `--trace-file`; that option is PHP-only. The [workbench rehearsal](https://ronaldtebrake.github.io/symfony-hackathon/#native-rehearsal) shows the native MCP calls captured on 10 November, alongside the installed handler and policy. It is a saved take, not a live mirror of your chat. Operator probes and Hugging Face comparisons remain separately labelled. Save new takes separately.

## Historical generated-PHP connection

The original root-demo connection uses `code-mode:mcp --backend=fixture --no-debug`, exposing `tools_search`, `tools_describe` and `php_execute`. It requires the dedicated [gVisor runtime](../packages/php-code-mode/README.md#sandboxed-php). Keep it separate from `symfony_catalog`; the root demo and the small independent application have different tools and data.

The retained [original conversation](evidence/codex-conversation-2026-11-01.json) shows that earlier PHP path. It is not the JSON recording above. Its generated source and execution evidence remain available under Version one in the workbench.
