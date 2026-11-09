# Record the 90-second Symfony demo

One story: **sandboxed PHP proved the idea; a Laravel-style JSON catalog makes it easier to adopt in Symfony.** Show the audit and follow-up, then the change in execution interface and a fair three-way comparison. **Drupal stays outside the main cut.** Its separate proof is an optional follow-up.

The [local 90-second guide](http://127.0.0.1:8000/demo#demo-tour) walks through saved evidence and suggested narration. It is a recording aid, not a video or an execution interface. Conversations stay in the agent.

## The edit and voice-over

| Time | Capture | Suggested narration |
| --- | --- | --- |
| 00:00–00:10 | Actual agent audit footage. | We began with an agent auditing published articles. It wrote PHP against approved tools, and the sandbox ran it. |
| 00:10–00:20 | Same chat: follow-up and repair plan. | Which shared image fixes help the most articles? The agent composes another program, with the same permissions. |
| 00:20–00:30 | Workbench: follow-up → Generated PHP. | This is the submitted PHP. It groups images and ranks fixes. That saves model-side work, but requires a dedicated sandbox runtime. |
| 00:30–00:40 | Follow-up: actual tool calls. | Every call crosses the broker for validation and authorization. We keep those checks when we change the execution interface. |
| 00:40–00:55 | Version two and the actual catalog search/execute calls. | For adoption, we follow Laravel: search returns full contracts; execute accepts literal JSON calls. No generated program means no gVisor. Our current worker still needs Unix PHP CLI. |
| 00:55–01:07 | Three-way comparison, attempt 13. Hold on tokens and all three correctness checks. | Same model, question, data and permissions. JSON uses 29,798 tokens; direct tools 46,438; PHP 12,806. Review costs and earlier failures remain visible. |
| 01:07–01:23 | Original security probes: allowed, denied, loop terminated. | These fixed PHP probes show an allowed read, a denied call and a terminated infinite loop. JSON removes generated code; approved handlers still need checks and budgets. |
| 01:23–01:30 | Use it in Symfony: `mode: catalog`, policy and doctor command. | Reuse your Symfony AI tools and policy. Enable the catalog, check prerequisites and connect your agent. |

Keep source labels visible: **version one: recorded MCP client conversation**,
**separate Symfony AI + Hugging Face comparison**, and **fixed security probes**.
The JSON audit is not a continuation of the recorded PHP follow-up. These are
separate executions using the same package; do not present them as one trace.

Attempt 13 uses `audit-catalog-v10`. Its JSON path uses **35.8% fewer provider
tokens than direct tools**, while PHP uses **72.4% fewer**. This is a corrected
development example selected from thirteen retained attempts, not a reliability
estimate. The model reviewed every path; the host supplied no expected answer.
Input/output pricing determines cost, so do not translate token percentages into
billing percentages. The original animated explainer remains under version one
and retains its separately labelled 2 November two-way measurements.

## Prepare the recording

1. Run `composer install` and `composer start`. The workbench reads saved reports without inference or a running sandbox. For a stable recording, use `composer docs:build` and serve `var/pages/`: this exports only reviewed captures. The local workbench otherwise prefers newer local reports, including failed attempts.
2. For a fresh conversation, configure the [gVisor runtime](../packages/php-code-mode/README.md#run-the-proof) and run `php bin/console code-mode:doctor`. Connect the fixture server with `code-mode:mcp --no-debug` in your MCP client. The [desktop connection guide](codex.md) is one client example. Keep one freshly started MCP process for both turns; the session permits two PHP attempts, including failures.
3. Enable [host trace recording](../packages/symfony-code-mode/README.md#agent-responses-and-host-evidence). Retain the trace, exact `php_execute.code`, returned values, prompts and final answers. Lean responses do not send the full host ledger to the agent.
4. Record at 1080p or higher, 30 fps. Capture a few spare seconds before and after each action. Keep the original take in ignored `var/demo-recording/`. Keep credentials and unrelated chats off screen. Cut waiting time, while preserving the order and provenance of the evidence.

The viewer does not automatically synchronize a fresh chat. **Only pair new agent footage with its matching PHP and host trace.** If recording the saved conversation, use the [1 November source capture](evidence/codex-conversation-2026-11-01.json), recorded at 12:42 CET. The viewer reproduces its exact prompts, source, tool records and results; that desktop export does not identify its model or MCP process.

## Prompts for a fresh take

These prompts fit the current lean responses. They are not replacements for the historical prompts stored in the evidence.

First message:

```text
Use Symfony Code Mode to audit every published article in the Symfony fixture for missing meta descriptions or missing hero-image alt text. Return the affected article IDs and titles with their issues, and the total number audited. Use MCP tools rather than repository files. Keep this read-only. Show the exact PHP you submit and the returned result. Use one php_execute call so we retain the second execution for a follow-up. If the MCP tools are unavailable, stop and report that connection problem.
```

Then, in the same conversation:

```text
Reshape that into an editor repair plan. Group the issues by type and identify shared hero images whose missing alt text affects more than one published article. Rank those image fixes by how many published articles they help and include the affected IDs. Recompute through Symfony Code Mode using the remaining execution. Keep it read-only and show the new PHP and returned result.
```

Record discovery, each PHP submission/result and both answers. In the saved run, the audit checked 48 articles and found ten affected articles; the follow-up identified three shared-image fixes helping six articles. Each turn made ten underlying calls. These are observations to verify, not answers to feed the model.

For readable inserts, open [the agent conversation](http://127.0.0.1:8000/demo#agent-run), choose the follow-up, then **Generated PHP → Present PHP**. The source-navigation highlights are not line-by-line execution tracing. **Tool calls** shows the actual host records. The security section separately records an allowed read, a denied tool and a terminated loop, including checked container removal.

Export exactly 90 seconds at 1080p/30 fps (2,700 frames). Manual agent footage, the final edit and submission remain pending. No replacement video is generated by this guide.

## Optional boundary-recovery opening

The [2 November capture](evidence/boundary-recovery-2026-11-02.json) used the Symfony bundle through Codex's app-server. It is a **CLI rehearsal**, not desktop footage. It demonstrates a denied draft read, the agent proposing a permitted alternative, and a successful four-call audit of 48 published articles.

To record that interaction, configure a separate MCP entry named `symfony_boundary` using the same absolute PHP/project paths as the [connection guide](codex.md), but command `code-mode:serve --no-debug`. Select only that server and use one fresh process for both turns. First ask:

> Please read article 49 and summarize it. Use Symfony Code Mode and show the PHP you execute.

Wait for the denial and proposed alternative, then ask:

> Please audit the published articles instead. Find those with an empty meta description, return their IDs and titles plus the number checked, and keep drafts excluded. Show the PHP and actual tool-call counts.

Use matching manual footage before replacing the first 20 seconds of the main edit. The [Recovery viewer](http://127.0.0.1:8000/demo#recovery) preserves the denied result with no content, the subsequent permitted result, both PHP bodies and cleanup checks. User agreement cannot expand the tool permissions or reset the attempt budget.

## Repeatable evaluation

```sh
php bin/console code-mode:evaluate --no-debug
# Real inference credits, same suite and two predeclared repetitions:
php bin/console code-mode:evaluate --live --no-debug
# Smaller operator-selected capture; each selected scenario still runs both paths:
php bin/console code-mode:evaluate --live --scenario=boundary --repetitions=1 --no-debug
```

The five scenarios cover pagination, article/media joins, a reshaped follow-up, denied draft access followed by an approved audit, and an editorial instruction embedded in published content. This is a separate synthetic Symfony MCP fixture variant; the original demo data and captures remain unchanged. The injection case explicitly requests the full record, so filtering the malicious text out before it reaches the model does not pass the exposure check. It tests that specific interaction, not general injection resistance.

Each arm gets fresh state. Both use the same model ID, generation settings, questions, read tools, resource authorization and request ceiling. Only the instructions for invoking direct tools versus PHP differ. Follow-ups retain one conversation and its two PHP attempts; a denied execution consumes an attempt. Repetition two reverses arm order. There are at most 16 model requests per conversation, 1–3 repetitions, and 60 agent calls; the full default live batch schedules 20 conversations and at most 320 provider requests. Automatic Hugging Face routing does not identify the serving provider.

The command declares the schedule before starting, checkpoints results and appends batches to ignored `var/demo/evaluation.json`. It uses an exclusive local lock and stops at eight retained batches or an 8 MiB report limit. Archive that local file deliberately before further captures; there is no automatic deletion or retry-until-success. Interrupted rows remain pending/running, and failures retain source, answers, calls and any reported usage. The reviewed [evaluation capture](evidence/evaluation.json) is the fresh-checkout fallback. No headers, credentials or provider reasoning are captured. Only synthetic tool arguments are included.

Protocol **v1** exposed ambiguous answer shapes: both models used boolean issue objects, and article reads omitted the requested wrapper. These attempts and their original verdicts remain in the report. **v2** states the exact JSON shapes for both paths. Compare within a batch; do not pool versions or select only successful runs. The verifier permits an observed failed tool call followed by recovery, while independently checking complete successful read coverage and the final answer. The viewer recomputes verification; the JSON preserves the recorded verdict as well.

In **v2**, direct tools and Code Mode each pass **8/10**, with only **6/10 pairs correct on both paths**. The direct agent receives article 44's empty description but drops it from the final joined findings. Code Mode's PHP returns the complete article; the model then changes whitespace, abbreviates its editorial note and emits malformed JSON. These are final-answer failures despite complete reads and enforced permissions.

**v3** adds one unconditional review per turn, symmetrically: the same model and settings inspect the existing conversation and tool results, with no tools available and no expected-answer feedback. It repairs both original failures, but twice damages Code Mode's correct language-count follow-up with malformed JSON. The entire failed batch remains recorded: direct **10/10**, Code Mode **8/10**.

**v4** keeps that review and checks its JSON syntax before accepting it. A review must be a JSON object; otherwise a valid draft is retained. If both are invalid, verification fails. This selection never consults the fixture or expected answer, never edits either output and makes no extra model request. Both paths use this rule. The two Code Mode follow-ups retain their correct drafts; the invalid reviews and their costs remain visible. The original v2 questions, model ID, generation settings, fixture hash, permissions, approved tools, runtime image and execution limits are unchanged. Reviews share the original 16-request conversation budget and cannot execute tools.

The complete **v4 batch on 4 November passes 10/10 conversations per path**, with all five scenarios repeated twice and **10/10 matching correct pairs**:

| Measured across the complete batch | Direct tools | Code Mode |
| --- | ---: | ---: |
| Model requests, including reviews | 116 | 78 |
| Provider tokens, including reviews | 366,898 | 133,234 |
| Review requests (included above) | 16 | 16 |
| Review tokens (included above) | 71,212 | 34,920 |

That is **63.7% fewer reported tokens and 32.8% fewer model requests in this batch**, with both accepted answers and PHP results independently checked. The single report retains 20 local reference conversations and all 80 live conversations across v1–v4, including every failed attempt: 704 live requests and 1,740,490 reported tokens in total. Cumulative development costs are not a matched comparison. Temperature-zero repetitions have identical per-task token counts; this is a small repeatability check, not an independent benchmark or a general reliability guarantee.

Open [Evaluation](http://127.0.0.1:8000/demo#evaluation). Its table includes every planned attempt and failed-run usage. Reference algorithms make no model requests, so their times are not model-performance measurements. Live timings describe these small local runs, not a general speed guarantee or a bill. Select a conversation, then a timeline bar to inspect the model request, exact generated PHP, actual broker arguments, result digest, or cleanup. Review bars expose the original draft, shared instruction, provider usage and accepted answer. Follow-ups, invalid reviews and exact outputs appear below; request/response digests bind these to the captured model calls.

The monotonic timeline measures runtime/catalog preflight, model invocation through deferred result conversion, container creation/inspection, attached PHP execution, broker calls, and cleanup. Broker spans nest inside PHP execution. Do not sum overlapping stages. Model time includes transport and SDK conversion; it does not isolate provider inference. Gaps and incomplete spans are left explicit, and historical captures receive no invented timing data. Successful cleanup means the runner's exact-container removal operation completed; the older security proof separately records a post-run absence check.

For the recording, the evaluation can replace the 12-second matched-comparison insert: show the live batch's verified/planned counts, select the Code Mode join or boundary conversation, and click its execution bar. Keep the Codex opening and security probes; these remain separate executions.

## Lean response measurement

The [payload viewer](http://127.0.0.1:8000/demo#lean-responses) replays the current formatter over nine retained execution responses, including failures. It checks source and payload hashes before displaying counts. The original evidence, provider usage and failed attempts are unchanged.

| Retained responses | Before tokens | Lean tokens | Reduction |
| --- | ---: | ---: | ---: |
| Symfony boundary recovery (3) | 285 | 42 | 85.3% |
| Symfony AI adoption (2) | 314 | 95 | 69.7% |
| Codex structured results (2) | 1,248 | 663 | 46.9% |
| Hugging Face audit (2) | 304 | 275 | 9.5% |

Counts use `tiktoken==0.12.0`, `o200k_harmony`, the [gpt-oss encoding](https://github.com/openai/gpt-oss/blob/main/gpt_oss/tokenizer.py). Symfony AI samples use exact retained result strings; Codex samples use one canonical JSON serialization of `structuredContent`. This excludes discovery, prompts, generated PHP, chat/protocol envelopes, duplicate serialization, repeated context and completions. It measures **response-body tokens**, not total conversation savings, Codex billing or a new Hugging Face run. The already compact audit path has a smaller gain.

Reproduce with a temporary, optional measurement environment; Python is not a runtime dependency:

```sh
python3 -m venv /tmp/code-mode-tokenizer
/tmp/code-mode-tokenizer/bin/pip install tiktoken==0.12.0
/tmp/code-mode-tokenizer/bin/python bin/measure-response-tokens.py > /tmp/lean-responses.json
diff docs/evidence/lean-responses.json /tmp/lean-responses.json
```

The tokenizer may download its public vocabulary on first use. No credentials, model inference or execution are involved. `php bin/response-payloads` alone exposes the exact old/new strings and byte counts. Security checks remain in the session, broker and executor; the formatter changes only presentation.

## Refresh the other proofs

| Command | What it runs |
| --- | --- |
| `php bin/console code-mode:huggingface-compare --no-debug` | Both live Symfony AI approaches; requires a host-only Hugging Face token. [Protocol and retained failures](huggingface-comparison.md). |
| `composer demo` | Model-free reference comparison: real MCP and gVisor, checked-in PHP. |
| `composer demo:security` | Three fixed PHP probes: approved read, denied delete, infinite loop. No model called. |

Security probes use two seconds, 128 MiB and three calls each. Normal audit executions use 15 seconds, 128 MiB, 30 calls and two attempts. The loop must complete an approved read before timing out; each exact container must be confirmed absent. A generic failure or unknown cleanup state cannot pass. See the [security model](../packages/php-code-mode/docs/security.md).

The CLI also supports `composer demo -- --mode=live --scenario=impact` and `--question='Count published articles by language. Return counts.'`. Custom answers are marked **review required**; they have no independent correctness badge. Runtime settings and permissions remain host-controlled. Custom-question live inference is outside the captured proof.

The [hackathon brief](https://symfony.com/hackathon), checked **3 November 2026**, asks for a public runnable repository and a 90-second real demo by **22 November, 18:00 CET**. The [evidence index](evidence/README.md) identifies the sources for each claim. The optional [Drupal example](../examples/drupal/README.md) is separate from this recording.
