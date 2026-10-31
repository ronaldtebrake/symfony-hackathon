# Symfony Hackathon

Foundation for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon): Symfony 7.4 LTS, Symfony AI 1.0 beta, and the official PHP MCP SDK 0.9.

We're building code mode for PHP: an agent generates real PHP, runs it in an isolated environment, and composes authorized tool calls through a trusted broker. The planned Drupal demo uses Tool API → MCP Server Tool Bridge → Drupal MCP Server. The repository currently contains the tested application foundation; the code-mode runtime and Drupal demo are not implemented yet.

## Run locally

Requires PHP 8.2+ and Composer 2. Composer checks the required PHP extensions.

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

`composer check` validates the configuration and tests HTTP health, AI wiring, and an MCP client/server exchange. GitHub Actions runs these checks on PHP 8.2–8.4.

Hackathon submission: public runnable repository and a 90-second demo video, due **22 November 2026, 18:00 CET**. This starter is the foundation; the working project and video are still to come.
