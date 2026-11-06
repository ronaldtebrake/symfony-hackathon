# Use Symfony Code Mode from Codex

Ask the question in Codex. It discovers the approved MCP tools, writes PHP, submits it to the isolated runner and answers from the result. Follow-up questions stay in that conversation. The workbench is a read-only viewer for the recorded Codex rehearsal, comparison and security proofs.

## Connect this project

Set up the [gVisor runtime](../packages/php-code-mode/README.md#run-the-proof) and the project's ignored `.env.local` first. This connection uses the read-only Symfony fixture; it does not connect to Drupal or need a Hugging Face token. Codex uses its own existing sign-in and model access.

Create `.codex/config.toml` in the project with your absolute paths:

```toml
[mcp_servers.symfony_code_mode]
command = "/absolute/path/to/php"
args = ["/absolute/path/symfony-hackathon/bin/console", "code-mode:mcp", "--backend=fixture", "--no-debug"]
cwd = "/absolute/path/symfony-hackathon"
enabled_tools = ["tools_search", "tools_describe", "php_execute"]
startup_timeout_sec = 20
tool_timeout_sec = 45
required = true
```

The local config is ignored by Git. Keep runtime credentials in `.env.local`, outside tool arguments and generated PHP. The 45-second client timeout allows for setup and cleanup; it does not change the runner's 15-second execution limit.

Run `codex mcp get symfony_code_mode` from this project to check registration. Restart the MCP connection in the desktop client's MCP settings, or launch `codex` from this directory. Use `/mcp` to inspect the connected server. Codex loads project MCP configuration for trusted projects; the CLI and desktop share the configuration. See [OpenAI's MCP documentation](https://developers.openai.com/codex/mcp/).

## Ask and inspect

Use the [audit and follow-up prompts](demo.md#audit-and-follow-up) in one chat. The human can reshape the task; agreement cannot grant permissions. The fixture server retains its two-execution budget, 15-second wall limit, 128 MiB memory, 30-call limit and per-call broker authorization. Exhaustion requires an operator-started process; no reset tool is exposed to the agent.

Inspect the actual `php_execute.code` and returned `value` in Codex. Current responses omit detailed host ledgers. For a new recording, add an operator-selected `--trace-file` argument following the [trace setup](../packages/symfony-code-mode/README.md#agent-responses-and-host-evidence); retain that private file alongside the conversation export. The [workbench](http://127.0.0.1:8000/demo#codex-run) displays the reviewed 1 November desktop capture, not a live feed from your chat. **Generated PHP → Present PHP** provides a large source view and exact-source copying. Keep the new take's tool records before replacing the saved proof. See the [recording guide](demo.md) for the timeline and optional denied-read conversation.
