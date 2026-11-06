# Verification scope

This records observed behavior, not an external security audit. The [evidence index](../../../docs/evidence/README.md) identifies the retained captures; [security.md](security.md) defines the trust boundaries and deployment requirements.

## Tested runtime

Recorded 1–2 November 2026:

| Component | Environment |
| --- | --- |
| Coordinator | PHP 8.3.19 CLI on macOS arm64 |
| Dedicated sandbox host | Ubuntu 24.04.5 arm64, OrbStack VM `code-mode-proof` |
| Docker | 29.1.3 inside that VM |
| gVisor | Signed package `20261026.0`, release `release-20261026.0` |
| Guest PHP | 8.4.27 CLI, official Alpine image pinned in the Dockerfile |
| Built runner | `sha256:686fc2b296b8801cdfb4e9cbcc9b333f2fbe42c31bff447bab9bf1eb73ad1a6b` |

The core package was also installed and tested independently of the Symfony application. Its standalone proof ran native PHP, returned `sum: 42` and recorded three authorized `demo.add` calls.

## Checks and what they cover

Run from the repository root:

| Command | Coverage | Last recorded result |
| --- | --- | --- |
| `composer check` | Composer manifests, YAML/container, application integration, package unit tests; no paid inference | 3 November: 97 application tests / 710 assertions and 29 package tests / 44 assertions |
| `composer test:sandbox` | Actual gVisor execution, permissions, deadlines, resource and protocol bounds, crash cleanup, network isolation | 1 November: 28 tests / 124 assertions |
| `composer test:demo` | Actual MCP + gVisor: reference audits, alternate PHP, denied writes, security probes, external-client budgets and Symfony bundle recovery | 2 November: 8 tests / 99 assertions |

Run sandbox suites sequentially against a dedicated Docker endpoint. `APP_ENV=test` skips `.env.local`; export the trusted `CODE_MODE_IMAGE` and `CODE_MODE_DOCKER_COMMAND` explicitly. Never print or pass model credentials to the guest. Mocked-provider tests verify orchestration, not live model generation.

6 November local-executor regression pass: **157 application tests, 54 package tests, 28 core gVisor tests, 15 Symfony/gVisor tests and 11 independent-consumer tests passed**. The refreshed independent Composer app also passed 25 real MCP/gVisor proof checks. No model was called and no recorded judge evidence was replaced.

The package suite now runs real local PHP against synthetic data: authorized reads, denied restricted reads, discovery/attempt/call budgets, bounded frames/output, heap exhaustion, loops and sleeping, process cleanup, and a coordinator-kill watchdog check with a live PID witness. It deliberately verifies that a host sentinel file **is readable** in local mode. Symfony integration tests verify explicit opt-in, Docker-free doctor/container configuration, truthful MCP/Symfony AI descriptions and results, rejected runtime arguments, and a denied-read → permitted-read conversation. These are behavior checks for `local-unsafe`, not isolation proofs. Process inspection must be available; its failure cannot count as evidence of termination.

The security probes require an authorized read, an explicitly denied tool call, and an infinite loop terminated after its read witness. A startup timeout or generic upstream error is insufficient. Each host-generated container name must be confirmed absent. The separate crash test permits a terminated zombie while its parent finishes reaping, but rejects a live worker after the fallback deadline. Network testing proves the trusted control can reach the destination before checking that the isolated guest cannot.

## Live and recorded evidence

- **Symfony AI + Hugging Face:** the [matched comparison](../../../docs/huggingface-comparison.md) verifies correct answers on both paths with the same model, question, fixture and permissions. Every attempted invocation is retained, including bad answers and provider failures. These are development measurements, not repeated performance trials.
- **Codex:** a two-turn desktop audit/repair-plan chat generated two programs and made twenty successful upstream calls. The separate Symfony bundle CLI rehearsal denied a draft read, proposed an alternative and completed the user-selected audit. Both preserve exact PHP, actual MCP results and independently checked answers.
- **Reference audit:** checked-in algorithms made ten batched reads each, checked 48 articles and returned ten identical findings. The 85% reduction concerns serialized tool-result payload, excluding prompts, initial schemas and generated code. No model was called.
- **Drupal:** the optional [DDEV proof](../../../examples/drupal/README.md) uses Tool API, Tool Bridge and MCP Server under a fixed reader account. Published-node data was returned; restricted content was not. Independent Drupal access/read events establish the denial, because the generic gateway reports only `tool_failed` / `incomplete`. Both containers were removed and the nodes remained unchanged. Local STDIO identity selection is not remote authentication.

The workbench is a local GET-only viewer. Tests cover remote-client/Host rejection, fixed report paths, oversized reports, missing evidence, failed-run precedence, mismatched captures and verification that usage and tool bytes reconcile. Source navigation and screenshots do not constitute line-level execution traces. New agent conversations are not automatically imported.

## Limits

The optional Symfony bundle AI adapter has mocked-provider tests and real MCP/gVisor integration coverage; its paid-provider path is not the Hugging Face audit capture. Drupal HTTP authentication and its full editorial audit remain unverified. Windows, rootless Docker, alternate gVisor platforms and a native Linux coordinator were not verified locally. The configured GitHub Actions sandbox job is not evidence of a completed remote run.

Passing these tests does not establish safety for a public multi-tenant execution service. Production deployment still needs independent review, authenticated identity, admission limits and operational supervision. The [90-second manual recording](../../../docs/demo.md) and formal hackathon submission remain pending.
