# Proof verification

Latest verification: 2 November 2026. The initial reference measurements below were recorded on 31 October. This records observed behavior, not an external security audit.

| Component | Tested version/environment |
| --- | --- |
| Coordinator | PHP 8.3.19 CLI on macOS arm64 |
| Dedicated sandbox host | Ubuntu 24.04.5 arm64, OrbStack VM `code-mode-proof` |
| Docker engine | 29.1.3 inside that VM |
| gVisor | `release-20261026.0`, signed package `20261026.0` |
| Guest PHP | 8.4.27 CLI, official Alpine image pinned in the Dockerfile |
| Built runner image | `sha256:686fc2b296b8801cdfb4e9cbcc9b333f2fbe42c31bff447bab9bf1eb73ad1a6b` |

## Drupal authorization milestone — 2 November 2026

A separate DDEV 1.24.4 project installed Drupal 11.4.11, Tool API 1.0.0-beta12, MCP Server 2.0.0-beta7 and Tool Bridge 1.0.0-beta5. Its dedicated reader account has exactly `access content` and `read code mode articles`. Local STDIO runs as that account; this is operator-selected execution identity, not remote client authentication.

The [recorded proof](../../../docs/evidence/drupal-authorization-proof-2026-11-02.json) ran two fixed PHP bodies through the unchanged package and gVisor runtime. Both called `tool_api__code_mode_read_article`: published node 1 returned its ID/title; unpublished node 2 returned no content. Drupal independently recorded an allowed check/read for node 1 and a denied check with no read for node 2. The generic gateway reports the MCP error as `tool_failed` / `incomplete`; only the paired Drupal evidence establishes that this was an access denial. Both exact sandbox containers were confirmed absent, and both nodes' content hash remained unchanged. No model was called.

The documented DDEV setup was rerun successfully without reinstalling the database or replacing the fixtures. Drupal's Composer validation and advisory audit passed; its initial Symfony Mime advisory was resolved by updating to 7.4.22. The HTTP MCP endpoint rejected an anonymous request with 401. No Drupal MCP subprocesses remained after execution.

`composer check` passed **40 application tests / 311 assertions** and **29 package tests / 44 assertions**. The new verifier tests reject failures without Drupal evidence, wrong identities, leaked values, missing cleanup, nonexistent restricted nodes and elevated permissions. The real Symfony reference comparison also passed **1 test / 9 assertions**, with the existing pinned runtime exported explicitly into PHPUnit's test environment. The Symfony workbench continued to return HTTP 200; its source, connection configuration and saved reports were not replaced.

See the [repeatable Drupal setup](../../../examples/drupal/README.md). A Drupal Codex conversation, HTTP authentication and the full editorial audit remain outside this milestone.

## Prior checks — 1 November 2026

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

The initial 10:31 CET rehearsal used an ephemeral app-server conversation. The workbench now shows a fresh desktop chat, **`01a196eb-12bd-7412-9fad-4d279e2cbcf8`, started at 12:42 CET**, against source commit `1b6e313`. The user authorized both prompts through the desktop tools. Codex discovered the approved schemas and generated two different PHP programs: an audit, then a recomputed repair plan grouped by issue and shared-image impact.

Both returned values matched the independent fixture checks after field-name normalization. Each execution made four list calls, four article-batch calls and two media-batch calls; all 20 succeeded. Runner durations were **765.199 ms** and **776.923 ms**, excluding model generation, container creation and final cleanup. The post-execution inventory found no managed containers. The exact programs, results and host ledgers are in the [conversation record](../../../docs/codex-conversation.md).

The checked-in capture preserves the actual MCP records and agent messages from the persisted full-turn export. It does not include reasoning items or desktop delivery wrappers. Prompt text was extracted from those wrappers. The export does not record the model or MCP process identity, so metadata from the older capture has not been reused. This verifies that interaction, not general model reliability or Hugging Face inference.

## Read-only viewer — 1 November 2026

The read-only Codex viewer was checked separately on 1 November: **3 targeted HTTP/store tests / 41 assertions passed**, covering exact capture delivery, GET-only access, local client/Host restrictions, ignored path parameters and rejection of oversized evidence. Browser checks confirmed both submitted programs, the follow-up's outer MCP call, returned JSON and final agent response match the capture exactly. Keyboard navigation stays within each independent inspector. Screenshots were refreshed with the Symfony AI palette. This viewer check did not rerun the model or sandbox proofs above.

The PHP presentation view was then browser-checked for both recorded turns: source text remained identical, section navigation selected the expected lines, clipboard output matched the submitted body, and Escape/Close restored focus to the opener. No browser warnings or errors were observed. The same **3 tests / 41 assertions** passed again; JavaScript syntax and Git whitespace checks passed. The presentation adds no execution or import endpoint. Its screenshot shows source navigation, not line-level runtime tracing.

After importing the fresh 12:42 CET desktop capture, **4 targeted HTTP/store/evidence tests / 71 assertions passed**. The added check compares both recorded results against the independent fixture, validates impact ranking and confirms successful approved read calls. Browser checks matched both displayed PHP bodies, returned JSON, final responses and complete outer MCP records to the imported capture. The follow-up's grouping and ranking buttons select the new program's actual variables. All three Codex screenshots and the recording guide now refer to this same chat. No new model or sandbox execution was needed to update the viewer.

## Verification limits

The crash test allows a terminated zombie process while its OS parent/subreaper completes reaping; it rejects a live worker after the fallback deadline. An ordinary cancellation reaps the broker directly. The output and frame flood tests use bounded host reads. The network test first proves that the control server is reachable from a trusted control container, then verifies the isolated guest cannot connect.

GitHub Actions defines an Ubuntu amd64 standalone-package job that installs the pinned gVisor release, builds the runner, and executes the security suite and full demo integration checks. **That remote CI job has not been run from this checkout.** Windows, rootless Docker, alternate gVisor platforms, and a native Linux coordinator have not been verified locally. Do not generalize this record into compatibility or security claims for those environments.

Live Hugging Face inference remains unverified because no API key is configured. The Drupal HTTP adapter is tested against a local MCP server, not a Drupal installation. The previous rendered walkthrough was removed at the author's request. The [replacement recording guide](../../../docs/demo/README.md) calls for manually recorded Codex footage and matching PHP/tool evidence; that video is pending. These gates remain separate from the recorded local checks.

See [setup and commands](../README.md) and [the threat model and deployment limits](security.md) before rerunning or embedding the package.
