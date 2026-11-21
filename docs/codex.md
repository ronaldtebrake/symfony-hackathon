# Connect your agent

## Run the film prompts

In one terminal at the repository root, start Symfony (PHP 8.2+ and Composer 2.5+).

```sh
composer install
composer demo:serve
```

### Open the support project

Select **`examples/support`** as the working folder in a local Claude Code or Codex session. For the CLI, run `cd examples/support` and then `claude` or `codex`. Trust the project and approve the connection when prompted. Check `/mcp` for `symfony_code_mode` and its two tools.

[Claude’s configuration](../examples/support/.mcp.json) and [Codex’s configuration](../examples/support/.codex/config.toml) both invoke the same PHP header helper. It supplies the current demo token to the client while Symfony checks access on every call. Both clients must be able to run `php` from their environment.

### Other local MCP clients

Use a client running on the same machine that supports **Streamable HTTP** and a bearer token or custom header. Add a server named `symfony_code_mode` at `http://127.0.0.1:8017/mcp`. A cloud-hosted connector cannot reach your computer’s loopback address.

From the repository root, copy the temporary token into your client’s **bearer token** field. On macOS use `composer demo:token | pbcopy`, on Wayland Linux use `composer demo:token | wl-copy`, or in PowerShell use `composer demo:token | Set-Clipboard`. For clients that accept headers, set `Authorization` to `Bearer <token>`. Clients supporting a JSON header helper can invoke `php /absolute/path/to/scripts/refund-recording.php headers --rehearsal` directly. Restarting the server rotates the token. Keep it out of chat and recordings.

Connect and check that `search_tools` and `execute_tools` appear. Ask:

> Please find and follow our team’s refund procedure for order R-1042. What should I tell the customer?

A client can follow the Group by making ordinary MCP calls. A client with code execution can also write a program that passes the first result into the next call.

In the same chat, try a second order:

> Could you check order R-1043 using the same refund procedure? What should I tell this customer?

The reviewer is assigned R-1042 only. In the Profiler, open the second order read to see `tool_denied` and zero handler events. Leave permissions granted for this take.

### Reproduce the filmed Claude Code session

In another terminal at the same root, start your signed-in Claude Code CLI.

```sh
cd examples/support
export CLAUDE_CODE_EXPERIMENTAL_CODE_MODE=1
claude
```

The export opts this shell into Claude’s JavaScript Code Mode. You can omit it if your user settings already enable Code Mode, or if you just want ordinary MCP calls. Claude reads the project’s `.mcp.json`, asks for project and connector approval, and connects directly to Symfony on `127.0.0.1:8017`. Its header helper supplies a temporary read-only credential. Use `/mcp` to inspect the connection. [Claude Code Mode settings](https://code.claude.com/docs/en/code-mode) · [Project MCP configuration](../examples/support/.mcp.json)

> Please find and follow our team’s refund procedure for order R-1042. What should I tell the customer?

Press **Ctrl+O** to inspect the tool activity. In the earlier JavaScript proof, Claude searched for the procedure, opened `refund-review` and wrote one JavaScript program. The first read returned an order with `policyId: "standard-eu"`. The code passed that field into the next read. Claude recommended manual review because €75 exceeded the €50 threshold. Run the same prompt to see how your client uses the tools. [Recorded JavaScript and calls](https://ronaldtebrake.github.io/symfony-hackathon/#code-mode-ready) · [All three attempts](evidence/claude-structured-refund.json)

**Try a permission change.** After the review, use `php scripts/refund-recording.php revoke --rehearsal` from the repository root and ask Claude to check the same policy again. Symfony denies that read. To reset the demo’s grant and call budget, stop and restart `composer demo:serve` and start a fresh Claude session. You can also use `composer demo:chat --revoke-after-order claude` for automatic revocation between reads, or `composer demo:chat -- codex` for an isolated Codex session.

**See the draft Groups methods.** Run `php scripts/trace-refund-group.php` to inspect the actual HTTP requests from a Groups-aware PHP client. It runs `groups/list`, `groups/get`, then invokes both members with `tools/call`. Claude Code can open the same Group through `search_tools`. The programmatic CI check, `composer proof:discovery`, verifies authorized reads and revocation without calling a model. [Groups request and response bodies](evidence/refund-group-discovery.json)

## Connect Symfony Code Mode

For the judge quickstart on port 8017, you can also watch the calls in the native Symfony Profiler. Start the dev app below and open the [Profiler for port 8017](http://127.0.0.1:8001/_profiler/empty/search/results?method=POST&url=http%3A%2F%2F127.0.0.1%3A8017%2Fmcp&limit=20) before starting `composer demo:serve` so the server can share the dev profile store. Refresh after each prompt.

The following alternative uses port 8016 for the original Codex recording and port 8001 for the Profiler.

From the repository root, install the project and start the Profiler.

```sh
composer install
composer install --working-dir=examples/symfony
APP_ENV=dev composer start --working-dir=examples/symfony
```

Open the [filtered Profiler request list](http://127.0.0.1:8001/_profiler/empty/search/results?method=POST&url=http%3A%2F%2F127.0.0.1%3A8016%2Fmcp&limit=20). In a second terminal, start the MCP server and leave it running.

```sh
php scripts/refund-recording.php serve --catalog --profile
```

In the trusted project’s ignored `.codex/config.toml`, configure the connection below. Replace both absolute paths with the paths on your machine. The header helper supplies the current fixture credential.

```toml
[mcp_servers.symfony_code_mode]
url = "http://127.0.0.1:8016/mcp"
http_headers_helper = "/absolute/path/to/php /absolute/path/to/symfony-hackathon/scripts/refund-recording.php headers"
enabled = true
enabled_tools = ["search_tools", "execute_tools"]
required = false
```

Open a fresh chat and enter `/mcp`. Expand `symfony_code_mode` to see both tools. The agent uses its own model connection. [Run the judge walkthrough](demo.md#record-the-refund-review-and-the-symfony-profiler)
