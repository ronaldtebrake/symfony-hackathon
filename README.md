# Symfony Hackathon

Foundation for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon): Symfony 7.4 LTS, Symfony AI 1.0 beta, and the official PHP MCP SDK 0.9.

We're building code mode for PHP: an agent generates real PHP, runs it in an isolated environment, and composes authorized tool calls through a trusted broker. The reusable [runner-and-broker package](packages/php-code-mode/README.md) now runs native PHP under gVisor with tested authorization and execution limits. See its [security model and runtime decision](packages/php-code-mode/docs/security.md). AI generation and the planned Drupal Tool API → MCP Server Tool Bridge → MCP Server demo are still to come.

## Run locally

Requires PHP 8.2+ CLI and Composer 2, including `pcntl` and `posix` for the broker. Composer checks the required PHP extensions.

```bash
git clone https://github.com/ronaldtebrake/symfony-hackathon.git
cd symfony-hackathon
composer install
composer check
composer start
```

Open [http://127.0.0.1:8000](http://127.0.0.1:8000) — `/` and `/health` return JSON with `status: ok`. No API key or database is needed to start the app or run tests. Stop the server with Ctrl+C.

## AI and MCP

For live AI calls, add `HUGGINGFACE_API_KEY=hf_...` to `.env.local` (ignored by Git), then run `php bin/console ai:agent:call default`. Use a Hugging Face token with Inference Providers permission. Override `HUGGINGFACE_MODEL` in the same file if needed; the default is `openai/gpt-oss-120b`. Live calls consume provider credits; tests use a mocked response.

MCP is available at `/mcp` over HTTP or via `php bin/console mcp:server default` over STDIO. The `app_status` tool returns `ok`. Run `php bin/console debug:mcp` to inspect it.

`composer check` validates configuration and tests HTTP health, AI wiring, MCP, and the package's broker/protocol logic. GitHub Actions defines these checks for PHP 8.2–8.4 and a separate gVisor security suite. Follow the [package setup](packages/php-code-mode/README.md) to run `composer proof:code-mode` and `composer test:sandbox` against a dedicated Linux sandbox host.

Hackathon submission: public runnable repository and a 90-second demo video, due **22 November 2026, 18:00 CET**. This starter is the foundation; the working project and video are still to come.
