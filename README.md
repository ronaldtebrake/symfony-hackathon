# PHP Code Mode for Symfony

Give a Symfony AI agent a PHP runtime for composing approved MCP tools. It can paginate, batch, join and filter data in isolation, then return compact findings to the model.

![Animated Code Mode comparison: both paths audit 48 articles with ten MCP calls; PHP processes intermediate data and returns 85% less tool-result payload in the verified reference run.](docs/images/code-mode-value.svg)

The reusable [Composer package](packages/php-code-mode/README.md) exposes `tools_search`, `tools_describe` and `php_execute`. Agents can compose workflows without a custom orchestration tool for every question. The host authorizes every tool call; generated PHP runs in gVisor with no network, host mounts or credentials. [Security model and runtime choice](packages/php-code-mode/docs/security.md).

Built for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon) with Symfony 7.4, Symfony AI 1.0 beta and the PHP MCP SDK 0.9. The first demo is **Symfony-only**; Drupal's Tool API is a later integration.

## The proof

The editorial audit checks **48 articles** and returns the same **10 findings** through both paths, using **10 batched MCP calls each**. The reference run measures **20,154 bytes** of tool-result payload for direct calls and **3,014 bytes** for Code Mode, including discovery: **85% less**.

The animation illustrates those measured results. Reference mode uses checked-in PHP; MCP calls and sandbox execution are real. Payload excludes prompts, initial schemas and generated code. **This is not a model-token savings claim.** [Verification record](packages/php-code-mode/docs/verification.md).

![Actual local Code Mode workbench showing matching findings, measured payloads and PHP executed in gVisor. No model was called.](docs/images/code-mode-proof.jpg)

*Captured on 31 October 2026. This is a tested development proof, not an audited public execution service.*

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

Change the question in the workbench and copy its CLI command, or try `composer demo -- --mode=live --scenario=impact` to group issues and find shared-image impact. `--question='Count published articles by language. Return counts.'` accepts a custom question in live mode. Both use the same read-only Symfony fixture tools and sandbox limits. Custom answers are marked for review; they do not receive a correctness or savings badge.

## Verify

```bash
composer check          # Configuration, app and package unit/integration checks
composer test:sandbox  # Real adversarial PHP; requires the configured gVisor host
composer test:demo     # Real MCP + gVisor comparison and external MCP client
```

Run the sandbox suites sequentially on a dedicated test Docker endpoint. The demo also exposes `tools_search`, `tools_describe` and `php_execute` over STDIO: `php bin/console code-mode:mcp`. [Verification record](packages/php-code-mode/docs/verification.md).

The Symfony reference audit and mocked-provider AI pipeline are verified locally. Live Hugging Face inference is the next verification step. Drupal is optional follow-on work. Submission needs a public runnable repository and a 90-second video by **22 November 2026, 18:00 CET**.
