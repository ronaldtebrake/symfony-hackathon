# Symfony Code Mode

A Symfony app might have a hundred tools. An agent reviewing a refund only needs to read the order and check its policy. We built a searchable catalog over the Model Context Protocol (MCP) so the agent can ask for the tools it needs. For recurring jobs, we prototyped the MCP working group’s idea of named groups. A team can offer both readers under one name and link its review checklist as a skill.

[Watch the 90-second demo](https://ronaldtebrake.github.io/symfony-hackathon/#demo-tour) · [Explore the GitHub Pages workbench](https://ronaldtebrake.github.io/symfony-hackathon/) · [Try it locally](#try-it-locally)

Our first agent wrote PHP to match articles with images in a gVisor sandbox. It found the same ten issues as direct tool calls and used 71% fewer tokens on that audit. Running a sandbox was a lot to ask just to call services a team already hosts. So we used the PHP MCP SDK to expose two tools over HTTP. `search_tools` finds readers visible to the user. `execute_tools` calls them with JSON, and Symfony checks every read. [Design journey and measurements](https://ronaldtebrake.github.io/symfony-hackathon/#journey)

[![Architecture of Symfony Code Mode. An agent can search the catalog or open a named group. Both routes check the caller’s current access before Symfony AI invokes the application’s reader. Pink diamonds mark what we built and purple squares mark Symfony and PHP MCP SDK components.](docs/images/symfony-mcp-architecture.svg)](https://ronaldtebrake.github.io/symfony-hackathon/#mcp-architecture)

In the demo, the host opens `refund-review`. A Symfony AI agent using Hugging Face reads a €75 order, follows its policy ID and recommends manual review because the amount exceeds €50. When we revoke access and ask it to double-check, Symfony denies the policy read before the handler runs. The agent explains why it can’t verify its answer. The two readers were enough for this case, with or without the checklist. [See the conversation and actual calls](https://ronaldtebrake.github.io/symfony-hackathon/#refund-attempts)

## Try it locally

With PHP 8.2+ and Composer 2, run these commands.

```sh
git clone https://github.com/ronaldtebrake/symfony-hackathon.git
cd symfony-hackathon
composer install
composer proof:discovery
```

The command exercises both discovery routes in a fresh Symfony test kernel, then verifies the recorded refund exchanges and model answers. It needs no provider key or sandbox. Run `composer start` to [open the local workbench](http://127.0.0.1:8000/demo).

## What we can contribute

We can share a catalog recipe for existing Symfony AI tools, a Groups compatibility test for the PHP MCP SDK, and traces for the MCP working group showing what happens when access changes after discovery. [Symfony package](packages/symfony-code-mode/README.md) · [Contributions and evidence](https://ronaldtebrake.github.io/symfony-hackathon/#contributions) · [What we’d test next](https://ronaldtebrake.github.io/symfony-hackathon/#whats-next)
