# Symfony Code Mode

A Symfony application can offer an agent dozens of tools. Sending every definition up front costs tokens, and the list tells the agent little about how the team uses those tools together. Symfony Code Mode exposes two tools over the Model Context Protocol (MCP). An agent uses `search_tools` to find what its user can access, then `execute_tools` to call the Symfony services it needs.

[Watch the 90-second demo](https://ronaldtebrake.github.io/symfony-hackathon/#demo-tour) · [Explore the GitHub Pages workbench](https://ronaldtebrake.github.io/symfony-hackathon/) · [Try it locally](#try-it-locally)

We started with agent-written PHP in a sandbox. Hosting a sandbox was a lot to ask just to call services a team already runs. With the PHP MCP SDK and Symfony AI, the agent can send JSON arguments to those services through ordinary Symfony HTTP requests. [Design journey](https://ronaldtebrake.github.io/symfony-hackathon/#journey)

[![Architecture of Symfony Code Mode. An agent can search the catalog or open a named group. Both routes check the caller’s current access before Symfony AI invokes the application’s reader. Pink diamonds mark what we built and purple squares mark Symfony and PHP MCP SDK components.](docs/images/symfony-mcp-architecture.svg)](https://ronaldtebrake.github.io/symfony-hackathon/#mcp-architecture)

For recurring work, we prototyped the MCP working group’s idea of named groups. In the demo, an agent opens a team procedure and gets the tools it needs to follow it. The same endpoint supports this through catalog search and the draft Groups methods. Symfony checks permission on each read. Revoke access mid-review and the next read is denied before the handler runs. [Try the judge prompt](docs/demo.md#record-the-refund-review-and-the-symfony-profiler)

## Try it locally

With PHP 8.2+ and Composer 2, run these commands.

```sh
git clone https://github.com/ronaldtebrake/symfony-hackathon.git
cd symfony-hackathon
composer install
composer proof:discovery
```

The proof starts a fresh Symfony HTTP server and uses the PHP MCP SDK to find the group, open its procedure and call both readers. It then revokes access and checks the denial and empty search results. This uses no model or provider key. To try the conversation yourself, enable the `symfony_code_mode` server in your agent. [Connect Symfony Code Mode](docs/codex.md#connect-symfony-code-mode)

## What we can contribute

We can share a catalog recipe for Symfony AI tools, a Groups compatibility test for the PHP MCP SDK, and traces for the MCP working group showing what happens when access changes after discovery. [Contributions and evidence](https://ronaldtebrake.github.io/symfony-hackathon/#contributions)
