# Symfony Code Mode

**Give agents the right Symfony tools and a procedure they can follow.**

An agent shouldn’t have to load every tool your application offers to answer one question. Symfony Code Mode exposes two tools over the Model Context Protocol (MCP) by default. `search_tools` finds the definitions the user can access. `execute_tools` calls the approved Symfony AI handlers. When a job needs several tools, a team can publish them together in a **Group**, with instructions for when to use each one.

[![Watch the 90-second desktop demo of the refund Group](public/media/symfony-code-mode-judge-poster.jpg)](https://ronaldtebrake.github.io/symfony-hackathon/#judge-demo)

[Watch the narrated 90-second demo](https://ronaldtebrake.github.io/symfony-hackathon/#judge-demo) · [Try it in your MCP client](#try-it-locally)

**What makes it Code Mode–ready?** One tool can return a field another tool takes as input. In the film, the agent follows the team’s Group, using the order’s `policyId` to read its policy. Symfony checks permission again for the second order and denies the read. Clients can make these calls one at a time or compose them in code. [See a separate run with Claude’s JavaScript and four MCP calls](https://ronaldtebrake.github.io/symfony-hackathon/#code-mode-ready)

[![Architecture of Symfony Code Mode. An agent can search the catalog or open a named group. Both routes check the caller’s current access before Symfony AI invokes the application’s reader. Pink diamonds mark what we built and purple squares mark Symfony and PHP MCP SDK components.](docs/images/symfony-mcp-architecture.svg)](https://ronaldtebrake.github.io/symfony-hackathon/#mcp-architecture)

We started with agent-written PHP in a sandbox. Letting the client run its own code brought the server back to ordinary Symfony hosting. We built the catalog and prototyped the MCP working group’s Groups proposal using Symfony AI tool definitions and the PHP MCP SDK. [Design journey](https://ronaldtebrake.github.io/symfony-hackathon/#journey)

## Try it locally

With PHP 8.2+ and Composer 2.5+, start the local Symfony MCP server. Leave it running.

```sh
git clone https://github.com/ronaldtebrake/symfony-hackathon.git
cd symfony-hackathon
composer install
composer demo:serve
```

Open the **`examples/support` folder** as a local project in Claude Code or Codex (desktop or CLI). Trust the project and approve `symfony_code_mode` if prompted. Both clients have a project configuration that fetches the demo credential for you. Open `/mcp` to check for `search_tools` and `execute_tools`, then ask:

> Please find and follow our team’s refund procedure for order R-1042. What should I tell the customer?

[Connect another MCP client, try revocation, or inspect the native Groups trace](docs/codex.md#run-the-film-prompts)

## What we can contribute

We can share a catalog recipe for Symfony AI tools, a Groups compatibility test for the PHP MCP SDK, and traces for the MCP working group showing what happens when access changes after discovery. [Contributions and evidence](https://ronaldtebrake.github.io/symfony-hackathon/#contributions)
