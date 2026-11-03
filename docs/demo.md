# Run and record the Symfony demo

The judge story: ask an agent to audit content, reshape the question, inspect the PHP and authorized MCP calls, then show measured Symfony AI inference and enforced security boundaries. Keep the recording Symfony-only.

The [Adoption section](http://127.0.0.1:8000/demo#adoption-proof) adds a second recording option: existing Symfony AI tools, a live Hugging Face article read and follow-up, and a separate fixed probe that attempts an injected instruction and is denied. Generate it with `php bin/console code-mode:adoption-proof --live --no-debug`; omit `--live` for fixed probes only. The exact captured questions and answers are in the viewer. Label the fixed probe separately from model-generated PHP. The new capture is not the matched token comparison below.

## Prepare

1. Run `composer install` and `composer start`. The [workbench](http://127.0.0.1:8000/demo) displays committed evidence without inference or a running sandbox. New local reports take precedence, including failures.
2. For fresh execution, configure the [gVisor runtime](../packages/php-code-mode/README.md#run-the-proof), immutable `CODE_MODE_IMAGE` and trusted `CODE_MODE_DOCKER_COMMAND` in ignored `.env.local`. Run `php bin/console code-mode:doctor`.
3. Connect the fixture server using the [Codex setup](codex.md). Codex uses its own model access. Start a fresh operator-authorized MCP process and retain it for both turns; two sandbox attempts are available, including failed executions. A new chat alone does not prove the server restarted.
4. Record at 1080p or higher, 30 fps. Leave a few seconds around actions and keep the full original take in ignored `var/demo-recording/`. Hide credentials and unrelated chats. Cut waiting time in the edit, not the underlying evidence.

The workbench displays a reviewed capture; it does not synchronize fresh chats automatically. For a new take, retain the exact submitted `php_execute.code`, full tool results, prompts and final answers before updating the viewer. Never substitute an earlier program or model-written trace summary for that take's actual tool records.

## Audit and follow-up

These are the exact prompts from the [recorded desktop conversation](evidence/codex-conversation-2026-11-01.json), **1 November 2026, 12:42 CET**, chat `01a196eb-12bd-7412-9fad-4d279e2cbcf8`.

First message:

```text
Use Symfony Code Mode to audit every published article in the Symfony fixture for missing meta descriptions or missing hero-image alt text. Return the affected article IDs and titles with their issues, and the total number audited. Use the MCP tools rather than repository files. Keep this read-only. Show the PHP you actually submit and summarize the host-recorded tool calls. Use one php_execute call so we retain the second execution for a follow-up.

This is a fresh rehearsal for our judge recording. Keep the actual MCP request and result visible, including the submitted code, returned value and toolCalls. If the Symfony Code Mode MCP tools are unavailable, stop and report that connection problem; do not substitute a repository script or saved proof.
```

After the execution completes, in the same chat:

```text
Reshape that into an editor repair plan. Group the issues by type and identify shared hero images whose missing alt text affects more than one published article. Rank those image fixes by how many published articles they help and include the affected IDs. Recompute the impact through Symfony Code Mode using the remaining execution; do not merely reformat the previous answer. Keep it read-only and show the new PHP and actual tool calls.
```

Capture the submitted question, discovery calls, each `php_execute` request/result and the answers. The recorded run checked 48 articles and produced ten findings; its follow-up identified three shared-image fixes, each helping two articles. Each turn made ten upstream calls. These are observations, not expected answers to feed a new model run.

For readable inserts, open [Codex run](http://127.0.0.1:8000/demo#codex-run), select the follow-up, then **Generated PHP → Present PHP**. Use **Group images**, **Rank fixes** and **Return plan** to navigate the exact source; the highlight is navigation, not line-level execution tracing. **Tool calls** shows the host ledger and full outer MCP requests/results. The desktop export does not identify the model or MCP process.

## The 90-second edit

| Time | Picture and point |
| --- | --- |
| 00:00–00:10 | Actual Codex: audit prompt, discovery, PHP call and answer. Introduce Symfony Code Mode. |
| 00:10–00:20 | Same chat: follow-up, new PHP execution, repair-plan answer. Same permissions, changed computation. |
| 00:20–00:35 | Matching program in **Present PHP**: grouping, ranking, compact return. |
| 00:35–00:48 | Matching host ledger and expanded MCP result. Show actual authorized calls. |
| 00:48–01:00 | **Live comparison**: Symfony AI + Hugging Face, both answers verified, 11 → 5 requests and 43,979 → 12,597 tokens. Say “in this recorded pair,” not a general performance promise. |
| 01:00–01:22 | **Security**: authorized read, denied tool, terminated loop; expand the timeout trace and checked container removal. Label these separate fixed probes. |
| 01:22–01:30 | Symfony bundle configuration, `code-mode:doctor`, repository URL. End on adoption and Symfony AI + PHP MCP SDK. |

Use hard cuts, readable holds and matching footage. The comparison and security probes are separate executions from the Codex chat. Record narration and align captions after choosing the shots. Export exactly 90 seconds at 1080p/30 fps (2,700 frames). Manual footage and the final video remain pending; no submission has been made.

## Optional boundary-recovery opening

The [2 November capture](evidence/boundary-recovery-2026-11-02.json) used the Symfony bundle through Codex's app-server. It is a **CLI rehearsal**, not desktop footage. It demonstrates a denied draft read, the agent proposing a permitted alternative, and a successful four-call audit of 48 published articles.

To record that interaction, configure a separate MCP entry named `symfony_boundary` using the same absolute PHP/project paths as the [connection guide](codex.md), but command `code-mode:serve --no-debug`. Select only that server and use one fresh process for both turns. First ask:

> Please read article 49 and summarize it. Use Symfony Code Mode and show the PHP you execute.

Wait for the denial and proposed alternative, then ask:

> Please audit the published articles instead. Find those with an empty meta description, return their IDs and titles plus the number checked, and keep drafts excluded. Show the PHP and actual tool-call counts.

Use matching manual footage before replacing the first 20 seconds of the main edit. The [Recovery viewer](http://127.0.0.1:8000/demo#recovery) preserves the denied result with no content, the subsequent permitted result, both PHP bodies and cleanup checks. User agreement cannot expand the tool permissions or reset the attempt budget.

## Refresh the other proofs

| Command | What it runs |
| --- | --- |
| `php bin/console code-mode:huggingface-compare --no-debug` | Both live Symfony AI approaches; requires a host-only Hugging Face token. [Protocol and retained failures](huggingface-comparison.md). |
| `composer demo` | Model-free reference comparison: real MCP and gVisor, checked-in PHP. |
| `composer demo:security` | Three fixed PHP probes: approved read, denied delete, infinite loop. No model called. |

Security probes use two seconds, 128 MiB and three calls each. Normal audit executions use 15 seconds, 128 MiB, 30 calls and two attempts. The loop must complete an approved read before timing out; each exact container must be confirmed absent. A generic failure or unknown cleanup state cannot pass. See the [security model](../packages/php-code-mode/docs/security.md).

The CLI also supports `composer demo -- --mode=live --scenario=impact` and `--question='Count published articles by language. Return counts.'`. Custom answers are marked **review required**; they have no independent correctness badge. Runtime settings and permissions remain host-controlled. Custom-question live inference is outside the captured proof.

The [hackathon brief](https://symfony.com/hackathon), checked **3 November 2026**, asks for a public runnable repository and a 90-second real demo by **22 November, 18:00 CET**. The [evidence index](evidence/README.md) identifies the sources for each claim. The optional [Drupal example](../examples/drupal/README.md) is separate from this recording.
