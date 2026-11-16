# Symfony Code Mode

**Connect AI agents to your Symfony application through MCP.**

**[Watch the 90-second demo](https://ronaldtebrake.github.io/symfony-hackathon/#demo-tour) · [Explore the visual documentation](https://ronaldtebrake.github.io/symfony-hackathon/) · [Run the proof](#judge-quickstart)**

Symfony Code Mode is a reusable Composer package and Symfony bundle that adds a **searchable MCP (Model Context Protocol) tool catalog** to an application. An agent discovers the tools available to its authenticated user, then calls existing Symfony services with JSON arguments. The conversation stays in the agent; your application owns the data and access rules.

**Why a catalog?** Advertising every tool upfront adds schemas to the agent’s context, including tools it may never use. We expose two stable MCP tools:

- `search_tools` returns matching tool contracts visible to the current user.
- `execute_tools` calls approved handlers, checking current permissions, arguments and budgets.

Adding tools leaves those two advertised schemas unchanged. Retrieved contracts, results and conversation history still consume tokens.

**The demo:** an agent retrieves an article’s title. Revoke permission, and the identical follow-up is denied before the handler runs. A fresh search hides the contract. Knowing a tool’s name does not preserve access to it.

Built for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon): **Symfony AI** supplies tool schemas and execution, the **PHP MCP SDK** exposes the catalog over HTTP, and **Hugging Face Inference Providers** powers a separately recorded agent conversation. Shared Symfony Security voters, Maker scaffolding and profiler traces support the integration.

## Judge quickstart

You need **PHP 8.2+, Composer 2 and Git**, plus network access for the initial install. Composer checks the required PHP extensions. This proof needs **no model key, Docker or gVisor**.

```sh
git clone https://github.com/ronaldtebrake/symfony-hackathon.git
cd symfony-hackathon
composer install --working-dir=examples/symfony
php examples/symfony/bin/console code-mode:doctor
composer proof:http --working-dir=examples/symfony
```

Expected: the doctor reports ready, followed by `"status": "passed"` and **31 passing checks**. Real HTTP requests verify authorized and restricted reads, permission revocation after discovery, cross-user isolation and persistent budgets. The harness starts and stops a temporary Symfony server with disposable credentials; no model is called.

**Try your own conversation:** follow the [agent connection and revocation walkthrough](https://ronaldtebrake.github.io/symfony-hackathon/#quickstart). It uses your MCP client’s model access. The published video and evidence need no installation.

## Inspect the work

- [Real Symfony AI + Hugging Face conversation](https://ronaldtebrake.github.io/symfony-hackathon/#native-rehearsal): actual JSON calls, authorization decisions, measured usage and both retained attempts.
- [Token measurements](https://ronaldtebrake.github.io/symfony-hackathon/#catalog-cost): fixed advertised schema size; complete conversation comparisons are reported separately.
- [The design journey](https://ronaldtebrake.github.io/symfony-hackathon/#journey): sandboxed PHP → ordinary Symfony HTTP/JSON → an adapter to Symfony AI’s proposed discovery components.
- [Symfony integration guide](packages/symfony-code-mode/README.md) and [independent application](examples/symfony/README.md): registration, shared voters, Maker, profiler and hosting limits. Packages currently use Composer path repositories; they are not on Packagist.
- [Contribution proposals](docs/contributing/README.md): prepared Symfony AI and PHP MCP SDK feedback, not yet posted.

For code review, run `composer install` and `composer check` from the repository root. This checks coding standards, PHPStan, configuration and tests. CI also verifies HTTP adoption on Linux and Windows.
