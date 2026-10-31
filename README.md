# PHP Code Mode

An agent writes PHP that batches, joins and filters approved MCP tool results inside gVisor. The demo audits published articles for missing descriptions and image alt text, then compares the result with direct tool calling. Built for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon) with Symfony 7.4, Symfony AI 1.0 beta and the PHP MCP SDK 0.9.

The reusable [Composer package](packages/php-code-mode/README.md) supplies the runner, authorization broker, MCP gateway and progressive tool discovery. Read the [security model and runtime decision](packages/php-code-mode/docs/security.md). This is a tested development proof, not an audited public execution service.

## Run locally

Requires PHP 8.2+ CLI with `pcntl`/`posix`, Composer 2, and a dedicated Linux Docker host with gVisor. Follow the [sandbox setup](packages/php-code-mode/README.md#run-the-proof) once to install `runsc`. Ordinary Docker alone is insufficient.

```bash
git clone https://github.com/ronaldtebrake/symfony-hackathon.git
cd symfony-hackathon
composer install
# Use the Docker context connected to your dedicated sandbox host:
docker build -t php-code-mode-runner:proof packages/php-code-mode/runner
export CODE_MODE_IMAGE="$(docker image inspect --format '{{.Id}}' php-code-mode-runner:proof)"
composer demo
composer start
```

Open [the workbench](http://127.0.0.1:8000/demo). The reference run needs no API key: it executes real PHP and MCP calls with a checked-in program, explicitly labelled as **no model called**. `/health` returns JSON. Stop the preview with Ctrl+C. Execution starts from the CLI; the web page only displays local reports.

For live generation, set `HUGGINGFACE_API_KEY` in ignored `.env.local`, then run `composer demo -- --mode=live`. The default model is `openai/gpt-oss-120b`; calls consume inference credits. Reports include provider token usage only when supplied. See [demo setup, Drupal connection and 90-second walkthrough](docs/demo.md).

## Verify

```bash
composer check          # Configuration, app and package unit/integration checks
composer test:sandbox  # Real adversarial PHP; requires the configured gVisor host
composer test:demo     # Real MCP + gVisor comparison and external MCP client
```

Run the sandbox suites sequentially on a dedicated test Docker endpoint. The demo also exposes `tools_search`, `tools_describe` and `php_execute` over STDIO: `php bin/console code-mode:mcp`. [Verification record](packages/php-code-mode/docs/verification.md).

The self-contained audit and mocked-provider AI pipeline have been verified locally. Live Hugging Face inference and an actual Drupal installation remain to be verified. Submission needs a public runnable repository and a 90-second video by **22 November 2026, 18:00 CET**.
