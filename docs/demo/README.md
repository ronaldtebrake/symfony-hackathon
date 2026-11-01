# 90-second judge demo

**Symfony Code Mode: ask in Codex, let PHP do the data work, inspect the evidence.**

This is a recording pack, not a finished video. It contains a timed shot list, [voice-over](narration.txt), [draft captions](captions.srt) and [copy-ready prompts](prompts.md). Keep the story Symfony-only. Target a 90-second export, including the closing frame; record the interactions at their real pace and trim waiting time in the edit.

## What is ready

The workbench and screenshots are committed in `8372a7e`. The reviewed [1 November Codex capture](../evidence/codex-conversation-2026-11-01.json) contains two different generated programs, two successful executions and 20 host-recorded upstream calls. The local comparison and security reports also exist and passed their recorded checks. Opening the viewer does not execute anything.

**Still needed for the recording:** actual Codex UI footage. The saved rehearsal used an ephemeral Codex app-server conversation; the JSON capture is not a replayable desktop chat or an existing video. Record a new two-turn Codex conversation using the prompts below. The workbench deliberately continues to show the reviewed 1 November capture. Label the cut to that earlier rehearsal explicitly; do not imply it automatically synchronized the newly filmed conversation. The shot list and narration already make this distinction.

## Shot list and voice-over

| Time | Screen and action | Say |
| --- | --- | --- |
| **00–10** | **Codex.** Show the original audit prompt and the actual `tools_search` → `tools_describe` → `php_execute` calls in the conversation. Record the real interaction, then cut out generation waits. Caption: **Symfony Code Mode · Symfony fixture**. | This is Symfony Code Mode. I ask Codex to audit published articles. It writes PHP to combine approved MCP tools. |
| **10–20** | **Same Codex conversation.** Show the follow-up asking which shared-image repairs help the most articles. Include the second real `php_execute` call and its response. Keep the prompt visible long enough to recognize the changed task. | Then I ask which shared-image fixes help the most articles. Same conversation, new computation, same permissions. |
| **20–35** | **Workbench → Codex run.** Cut with the label **Recorded rehearsal · 1 November 2026**. Briefly show **Original question**, then select **Follow-up → Generated PHP**. Hold on the three image-to-article pairs. Scroll the PHP to `$byImage`, then `usort($imageRepairs, ...)` so the changed computation is visible. | This saved rehearsal shows both turns. The follow-up rechecked forty-eight articles and found three shared-image fixes, each helping two articles. Here is the exact PHP it submitted. |
| **35–48** | **Follow-up → Tool calls.** Hold on the ten-row host ledger. Scroll inside that inspector to **Codex → Code Mode** and expand **php_execute · completed**. Show the recorded arguments and response. A short cut within this same recorded turn is fine. | The host recorded ten upstream calls: four list pages, four article batches and two media batches. Expand PHP execute for the actual request and response. |
| **48–60** | **Comparison.** Keep the **REFERENCE RUN · No model was called** label, both ten-call counts and payload definition in the frame. Caption: **Separate reference execution · 31 October 2026**. Hold on **20.15 kB → 3.01 kB**. | A separate Symfony AI reference comparison returns the same findings with ten calls each and eighty-five percent less tool-result payload. That measures bytes, not model tokens. |
| **60–82** | **Security proof.** Caption: **Recorded fixed probes · 1 November 2026 · No model called**. Hold on all three outcomes. Expand the infinite-loop trace to show the successful read witness, `wall_timeout` and checked container absence. Show the **No guest network** boundary below if there is time. | These fixed probes ran real PHP in gVisor. An approved read succeeded. A delete call was denied. An infinite loop hit the two-second watchdog. Each container was confirmed removed. The guest has no network or host credentials. These checks are evidence, not a security audit. |
| **82–90** | **Package README in the editor.** Hold on the reusable package introduction, with the repository URL as a small closing overlay. Overlay: **Symfony AI + PHP MCP SDK · reusable Composer package**. End at 90 seconds. | A reusable Composer package for Symfony AI and MCP agents. Conversations stay in Codex; the host controls execution. |

The voice-over is 177 whitespace-separated words, leaving room for the clicks and short pauses. The captions are timed to these shots, not an already-recorded voice track; align them to the final take before export. Read `php_execute` as “PHP execute” and gVisor as “gee visor.”

## Prepare the recording

1. Use the synthetic Symfony fixture and the existing [Codex MCP configuration](../codex.md). Check the dedicated gVisor host and immutable runner image before filming. Keep credentials out of the screen recording. Start a fresh, operator-authorized MCP process for this task; it permits two execution attempts, including failures. Do not use either attempt for a warm-up call.
2. Use the two exact prompts in [prompts.md](prompts.md), in one conversation. Let Codex discover the schemas and generate its own PHP. Keep the actual calls and results visible in Codex. Do not paste an expected answer or a reference program into the conversation. Record the result actually obtained; a failed or exhausted run is not a successful take.
3. From the repository root, run `composer start`. Open the [Codex capture](http://127.0.0.1:8000/demo#codex-run), [comparison](http://127.0.0.1:8000/demo#workbench) and [security proof](http://127.0.0.1:8000/demo#security-proof). The Codex section is checked in; the other two reports are local and ignored by Git. If missing, run `composer demo` and `composer demo:security` sequentially against the configured gVisor host. If rerun, use their new dates and observations in the edit.
4. Check the evidence against the table below. Use the existing reports for this recording unless there is a reason to rerun them. A styling change does not require new execution. Stop the local server with Ctrl+C when finished.
5. Record the Codex and workbench shots as separate clips. Use readable text at the final export size. Preserve the recorded/reference labels and distinguish a new Codex interaction from the older workbench capture. Trim waiting, navigation and dead air; keep outcomes and source code intact. Screenshots in [../images](../images/README.md) are useful references, but do not replace the actual Codex footage.
6. Record the narration, align the draft captions, and export exactly 90 seconds. Watch the final export once to check readable source, visible call results, correct evidence dates and audible narration. No uploading or submission is part of this pack.

## Evidence to keep visible

| Claim in the demo | Source and exact scope |
| --- | --- |
| Audit, then a recomputed repair plan | [Reviewed Codex capture](../evidence/codex-conversation-2026-11-01.json): two distinct `php_execute.arguments.code` values in one MCP process. This capture is independent of the new footage. |
| 48 articles, three shared-image fixes | Recorded follow-up: image **7 → articles 7, 31**; **14 → 14, 38**; **21 → 21, 45**. All tie at two articles helped. Missing descriptions remain on **11, 22, 33, 44**. No content was changed. |
| Ten upstream calls in each recorded turn | Four `cms_list_articles`, four `cms_get_articles`, two `cms_get_media`. The outer MCP call is `php_execute`; these are the inner broker calls. The ledger records outcomes, durations and result sizes, not separate per-call argument snapshots. |
| 85% less tool-result payload | Separate **31 October reference execution**, not the Codex follow-up: **20,154 vs 3,014 bytes**, same ten calls and same findings. Includes Code Mode discovery. Excludes prompts, initial schemas and generated code. No model-token, cost or latency savings claim. |
| Authorization and execution limits | Separate **1 November fixed probes**: authorized read succeeds; delete is `tool_denied`; loop is `wall_timeout` after a successful read. Exact container absence checked for all three. The probes use a **2-second / three-call** profile; normal agent executions retain **15 seconds / 30 calls / two attempts per process**. |
| Symfony AI and PHP MCP SDK integration | [`AuditToolbox`](../../src/Demo/AuditToolbox.php) implements Symfony AI's `ToolboxInterface`; [`DemoRunner`](../../src/Demo/DemoRunner.php) supplies it to Symfony AI's `Agent` in live mode and exercises it without inference in reference mode. The [STDIO command](../../src/Command/CodeModeMcpCommand.php) exposes Code Mode through the official PHP MCP SDK. Codex itself supplies the model for the conversation footage. |
| Reusable package | [Package README](../../packages/php-code-mode/README.md): the library is in the repository, used via Composer's path repository. It is not yet published on Packagist. |

## Fit with the hackathon

The [brief](https://symfony.com/hackathon), checked on 1 November 2026, asks for a creative use of Symfony AI and the PHP MCP SDK, working runnable code, craft, and a 90-second video. Submission closes **22 November 2026 at 18:00 CET**. This sequence demonstrates a changed question producing a new PHP computation, inspectable real execution, and enforced boundaries in a reusable package.

The brief also asks entrants to use **Hugging Face Inference Providers**. The project's Symfony AI live comparison path is implemented and locally tested with mocked provider responses, but live Hugging Face inference remains unverified. Complete that check before submission, keeping the key in ignored `.env.local`. Codex footage and the model-free comparison do not demonstrate that integration. If a verified live report replaces the reference segment, update its narration, captions and figures from the actual result; do not reuse the 85% reference claim automatically.

This pack does not claim production security, a completed video, live report synchronization, or a verified Drupal integration. The current [verification record](../../packages/php-code-mode/docs/verification.md) is the source for tested scope.
