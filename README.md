# Symfony Code Mode

A growing Symfony app can offer an agent dozens of tools. Sending every definition up front costs tokens, and a long list still tells the agent little about how a team uses those tools together. Symfony Code Mode gives the agent a searchable catalog over the Model Context Protocol (MCP) so it can discover approved tools as it needs them.

[Watch the 90-second demo](https://ronaldtebrake.github.io/symfony-hackathon/#demo-tour) · [Explore the GitHub Pages workbench](https://ronaldtebrake.github.io/symfony-hackathon/) · [Try it locally](#try-it-locally)

We started by letting agents write PHP in a sandbox to combine results from several tools. Hosting a sandbox was a lot to ask just to call services a team already runs in Symfony. So we used the PHP MCP SDK to build a catalog that works on ordinary Symfony hosting. The agent starts with two tools, `search_tools` to find operations visible to its user and `execute_tools` to call them with JSON arguments. [Design journey and measurements](https://ronaldtebrake.github.io/symfony-hackathon/#journey)

[![Architecture of Symfony Code Mode. An agent can search the catalog or open a named group. Both routes check the caller’s current access before Symfony AI invokes the application’s reader. Pink diamonds mark what we built and purple squares mark Symfony and PHP MCP SDK components.](docs/images/symfony-mcp-architecture.svg)](https://ronaldtebrake.github.io/symfony-hackathon/#mcp-architecture)

For recurring work, we prototyped the MCP working group’s idea of named groups. A team can offer related tools under one name and link its written procedure as a skill. The agent chooses its next step, and Symfony checks permissions on every read. In a recorded Symfony AI conversation, we revoked access between steps. Symfony denied the same read that had succeeded moments earlier. [See the conversation and actual calls](https://ronaldtebrake.github.io/symfony-hackathon/#refund-attempts)

## Try it locally

With PHP 8.2+ and Composer 2, run these commands.

```sh
git clone https://github.com/ronaldtebrake/symfony-hackathon.git
cd symfony-hackathon
composer install
composer proof:discovery
```

The command exercises both discovery routes in a fresh Symfony test kernel, then verifies the recorded MCP exchanges and model answers. It needs no provider key or sandbox. Run `composer start` to [open the local workbench](http://127.0.0.1:8000/demo).

## What we can contribute

We can share a catalog recipe for existing Symfony AI tools, a Groups compatibility test for the PHP MCP SDK, and traces for the MCP working group showing what happens when access changes after discovery. [Symfony package](packages/symfony-code-mode/README.md) · [Contributions and evidence](https://ronaldtebrake.github.io/symfony-hackathon/#contributions) · [What we’d test next](https://ronaldtebrake.github.io/symfony-hackathon/#whats-next)
