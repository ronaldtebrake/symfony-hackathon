# Symfony Code Mode

Let an agent compose approved MCP tools in PHP: paginate, batch, join and filter data in isolation, then return the findings. Conversations stay in the agent; the host controls permissions and execution limits.

![Code Mode returns the same findings with 85% less tool-result payload in the model-free reference run.](docs/images/code-mode-value.svg)

Built for the [Symfony AI & MCP Hackathon](https://symfony.com/hackathon). Symfony AI runs the agent, Hugging Face supplies live inference, the PHP MCP SDK connects the tools, and our reusable Composer packages execute generated PHP in gVisor.

## Try the proof viewer

Requires PHP 8.2+ CLI and Composer 2. Viewing recorded evidence needs no API key, Docker or model request:

```sh
git clone https://github.com/ronaldtebrake/symfony-hackathon.git
cd symfony-hackathon
composer install
composer start
```

Open [the workbench](http://127.0.0.1:8000/demo). Inspect the real Codex audit and follow-up, generated PHP, actual tool calls, denied draft read, and security probes. The viewer is read-only. [Evidence index](docs/evidence/README.md) · [90-second recording guide](docs/demo.md).

## Real Symfony AI results

The same model, question, Symfony data and read permissions produced **10 correct findings across 48 articles**, with ten successful MCP reads per path:

| Matched live run | Direct tools | Code Mode |
| --- | ---: | ---: |
| Model requests | 11 | 5 |
| Provider tokens | 43,979 | 12,597 |

That is **71.4% fewer provider tokens in this successful pair**, not a repeated benchmark. All 21 development invocations, including failures, remain in the [comparison evidence and methodology](docs/huggingface-comparison.md). The animation above illustrates a separate model-free reference measurement: **20,154 → 3,014 tool-result bytes**, excluding prompts, initial schemas and generated code.

![Actual workbench showing both verified answers and measured provider usage.](docs/images/huggingface-matched-comparison.jpg)

## Run your own agent

Configure the [dedicated gVisor runtime and pinned runner image](packages/php-code-mode/README.md#run-the-proof), then run `php bin/console code-mode:doctor`. Ordinary Docker alone is insufficient. The guest has no network, host mounts or credentials; the broker authorizes every tool call. [Security model and deployment limits](packages/php-code-mode/docs/security.md).

- **Codex:** connect `php bin/console code-mode:mcp --no-debug` using the [connection guide](docs/codex.md), then ask and refine the task in your chat.
- **Symfony AI + Hugging Face:** set `HUGGINGFACE_API_KEY` in ignored `.env.local`, then run `php bin/console code-mode:huggingface-compare --no-debug`. This consumes inference credits and retains earlier attempts.
- **Local proofs without inference:** run `composer demo`, then `composer demo:security`.
- **Existing Symfony AI tools:** run `php bin/console code-mode:adoption-proof --no-debug`, or add `--live` for a real Hugging Face article read and follow-up. Inspect [Adoption](http://127.0.0.1:8000/demo#adoption-proof) for PHP guides, generated code and the separate injected-content denial probe.

## Adopt and contribute

The [core Composer package](packages/php-code-mode/README.md) exposes `tools_search`, `tools_describe` and `php_execute`. The [Symfony bundle](packages/symfony-code-mode/README.md) adapts existing `#[AsTool]` services through an explicit allowlist and host policy, and supplies `code-mode:serve` and `code-mode:doctor`. Both are repository path packages, not yet published to Packagist.

[PHP MCP SDK and Symfony contribution proposals](docs/contributing/README.md) include executable checks. The optional [Drupal DDEV example](examples/drupal/README.md) demonstrates reuse with Tool API; the judge demo stays Symfony-only.

Run `composer check` for application and package checks. With the dedicated runtime configured, run `composer test:sandbox` and `composer test:demo` sequentially. [Verification scope](packages/php-code-mode/docs/verification.md).

This is a tested development proof, not an audited public execution service. The hackathon requires runnable public code and a 90-second video by **22 November 2026, 18:00 CET**. The manual recording and formal submission are still pending.
