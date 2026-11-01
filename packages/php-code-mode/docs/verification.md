# Proof verification

Latest verification: 1 November 2026. The initial reference measurements below were recorded on 31 October. This records observed behavior, not an external security audit.

| Component | Tested version/environment |
| --- | --- |
| Coordinator | PHP 8.3.19 CLI on macOS arm64 |
| Dedicated sandbox host | Ubuntu 24.04.5 arm64, OrbStack VM `code-mode-proof` |
| Docker engine | 29.1.3 inside that VM |
| gVisor | `release-20261026.0`, signed package `20261026.0` |
| Guest PHP | 8.4.27 CLI, official Alpine image pinned in the Dockerfile |
| Built runner image | `sha256:686fc2b296b8801cdfb4e9cbcc9b333f2fbe42c31bff447bab9bf1eb73ad1a6b` |

## Current checks — 1 November 2026

- `composer check`: valid Composer manifests, YAML and Symfony container; **34 application tests / 239 assertions** and **29 package unit tests / 44 assertions** passed. Coverage includes real STDIO/HTTP MCP transport, policy filtering, bounded discovery, question propagation through the CLI comparison harness, read-only report access and rejection before execution/report replacement.
- `composer test:sandbox`, with the dedicated runtime configuration exported: **28 real gVisor tests / 124 assertions passed**. These include both hard-crash scenarios (guest sleeping and broker handler blocked), retention of completed calls after timeout, and host-recorded denial and run IDs. No managed containers remained.
- `composer test:demo`, with the same runtime configuration exported: **7 real MCP/gVisor tests / 83 assertions passed**. These cover equivalent reference audits, alternate questions and programs, the Symfony AI pipeline with mocked provider responses, the operator security-proof command, and an external MCP client. The client receives conversation guidance, runs two different computations in one connection, then receives an execution-limit error on its third attempt. This checks the MCP interface; it does not verify a live model's conversational behavior.
- **98 PHP tests passed** across these four suites. No model was called by these checks.
- `composer demo:security`: all three fixed probes passed against real MCP and gVisor. The authorized read returned 48 articles in **473 ms**; the unauthorized tool call recorded **`tool_denied` in 320 ms**; the infinite loop recorded **`wall_timeout` in 2,211 ms**. Times include container setup and cleanup; each execution has a 2-second wall-clock budget starting at attachment. The loop first completed an authorized read, so a startup timeout cannot pass the proof. Docker independently confirmed all three exact run IDs were absent after cleanup.
- Browser checked: the workbench displays the three observed outcomes, submitted PHP, elapsed time, cleanup checks and expandable host-recorded traces. The question composer is removed. Human clarification belongs in the MCP client's conversation; the web page only reads reports. No HTTP execution endpoint or MCP budget-reset tool was added.

## Earlier baseline — 31 October 2026

- Independent `composer install` and `composer test` inside the package: passed with no Symfony application dependency.
- `composer demo`: both paths checked 48 published articles and returned the same 10 expected findings using 10 batched upstream calls. Measured tool-result payload: direct **20,154 bytes**, Code Mode **3,014 bytes**, including discovery. No model was called; no token savings are claimed. The local workbench displayed the program, trace, findings and reference label; keyboard tabs were checked in the browser.
- `composer proof:code-mode`: native PHP loop returned `sum: 42`, values `[12, 14, 16]`, and three host-recorded successful `demo.add` calls.
- Package archive inspected: runner, library, tests and documentation included; vendor, caches, local secrets and Git metadata excluded.
- PHP syntax, installer shell syntax, Composer platform requirements and Git whitespace checks passed. Composer reported no known dependency advisories during installation/update; this is not a runtime/image vulnerability scan.

## Live Codex — 1 November 2026

A separate live Codex rehearsal on 1 November used Codex app-server `0.165.0-alpha.1.2` with `gpt-6-astra` and the existing ChatGPT sign-in. Two scripted human prompts produced two different PHP programs in one conversation and MCP process: an audit, then a repair plan grouped by issue and shared-image impact. Both returned values matched the independent fixture oracles after field-name normalization. Each execution made four list calls, four article-batch calls and two media-batch calls; all 20 calls succeeded. No managed containers remained after teardown. The exact programs, outputs and host ledgers are in the [live conversation record](../../../docs/codex-conversation.md). This verifies that recorded interaction, not general model reliability or live Hugging Face support.

## Verification limits

The crash test allows a terminated zombie process while its OS parent/subreaper completes reaping; it rejects a live worker after the fallback deadline. An ordinary cancellation reaps the broker directly. The output and frame flood tests use bounded host reads. The network test first proves that the control server is reachable from a trusted control container, then verifies the isolated guest cannot connect.

GitHub Actions defines an Ubuntu amd64 standalone-package job that installs the pinned gVisor release, builds the runner, and executes the security suite and full demo integration checks. **That remote CI job has not been run from this checkout.** Windows, rootless Docker, alternate gVisor platforms, and a native Linux coordinator have not been verified locally. Do not generalize this record into compatibility or security claims for those environments.

Live Hugging Face inference remains unverified because no API key is configured. The Drupal HTTP adapter is tested against a local MCP server, not a Drupal installation. The demo video is planned but has not been recorded. These gates remain separate from the recorded local checks.

See [setup and commands](../README.md) and [the threat model and deployment limits](security.md) before rerunning or embedding the package.
