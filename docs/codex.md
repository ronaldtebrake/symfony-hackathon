# Connect your agent

## Connect Symfony Code Mode

The judge demo uses one MCP server, `symfony_code_mode`, on port 8016. Its two tools are `search_tools` and `execute_tools`. The Symfony Profiler on port 8001 lets you watch the requests.

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
