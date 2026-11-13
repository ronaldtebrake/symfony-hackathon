# Record the 90-second Symfony product demo

**Show what developers can use today:** ask an agent, discover an approved Symfony AI tool, send JSON, and receive an application result. Show the handler and policy behind it. The PHP-to-JSON journey supports this product demo; it is no longer the opening act.

Record a **fresh Codex conversation** against [the independent Symfony app](../examples/symfony/README.md). It has its own Composer dependencies, native Symfony AI tool and access policy, with synthetic articles. The [workbench guide](https://ronaldtebrake.github.io/symfony-hackathon/#demo-tour) provides shot cues and labelled reference evidence. It cannot run the conversation. The [fresh HTTP proof](evidence/http-catalog-proof.json) records actual discovery, successful lookup, denial and persistent budgets. The workbench shows these operator-authored messages alongside the installed Symfony handler and policy. The earlier [native MCP rehearsal](evidence/native-catalog-rehearsal.json) is retained separately; it predates HTTP. Video footage still needs to be recorded.

## Prepare the real application

From the repository root:

```sh
composer install --working-dir=examples/symfony
composer check --working-dir=examples/symfony
ADOPTION_MODE=catalog php examples/symfony/bin/console code-mode:doctor
composer proof:http --working-dir=examples/symfony
```

Connect Codex using [the JSON MCP configuration](codex.md#connect-the-json-catalog). Confirm **symfony_catalog** exposes only **search_tools** and **execute_tools**. Start the example with `composer start --working-dir=examples/symfony`. The authenticated `/mcp` endpoint runs normal Symfony requests, with no worker, Docker, gVisor or Hugging Face key. The client and server need the same local bearer token; keep it off screen.

Before each take, the operator runs `php examples/symfony/bin/console code-mode:reset-budget demo-reader`. The example permits **two execute submissions per authenticated user, including failures**. Reconnecting or restarting the server does not reset them. Never reset between the two turns. Do not increase the budget for the recording. Keep the old PHP server out of this take, and use only the selected MCP server to retrieve fixture data. A client with repository access can otherwise read the synthetic fixture directly; the MCP policy does not restrict the client's unrelated tools.

### Exact prompts

**Turn one:**

> Use only the symfony_catalog MCP server to read published article 101 and tell me its title. Discover its contract first. Do not read workspace files or use shell commands.

Expected application result: `{"id":101,"title":"A Symfony application owns its tools"}`. Verify it in the actual tool response, not just the final prose.

**Follow-up in the same chat:**

> Now try article 202 once through the same tool. If access is denied, explain that and suggest a permitted alternative from what we already retrieved. Do not retry or use another access path.

Expected application response: `tool_denied`, `completedCalls: 0`, no article data. The agent may suggest the already-read published article; that suggestion needs no third execution. The final wording is model-generated, not scripted evidence. If it retries, bypasses MCP, or invents a result, retain that attempt and fix the take honestly.

## Exact recording sequence and voice-over

Record at **1920 × 1080**, notifications off. Prepare the editor and workbench tabs before recording. Keep three seconds of still footage around each clip. Capture the real conversation at its actual speed, then trim waiting time with a visible **“wait trimmed”** caption. Ninety seconds is the edited duration, not a promised execution time.

| Time | Record | Say |
| --- | --- | --- |
| **00:00–00:12** | Codex: submit turn one, show the resulting title. Caption **“Authenticated HTTP · independent Symfony app · synthetic articles”**. | “This is Symfony Code Mode. In Codex, I ask a separate Symfony application for a published article. The application exposes its approved tools through MCP.” |
| **00:12–00:23** | Expand that chat's `search_tools` and `execute_tools` records. Show `publication_read`, `id: 101` and the actual result. Use the client transcript if its cards truncate arguments. | “The agent discovers the contract, then sends a tool name and JSON arguments. Symfony runs the installed handler and returns the article.” |
| **00:23–00:37** | Editor: [ArticleTool.php](../examples/symfony/src/ArticleTool.php), its `#[AsTool]` and `read()` method; then [ReadPolicy.php](../examples/symfony/src/ReadPolicy.php), `isGranted()`. The guide also has highlighted source excerpts. | “Here is that handler, registered with Symfony AI. The application owns the permission check and publication rule. The catalog reuses its schemas and existing toolbox.” |
| **00:37–00:50** | Back to the same Codex chat: submit the follow-up. Open its actual denial; hold on the explanation and permitted alternative if the agent supplies one. | “Now I ask for a restricted article. The host denies it before the handler runs. The agent can offer the published article we already retrieved.” |
| **00:50–01:01** | Workbench → **Inspect the controls**. Show the denied read, third execution refused, and exhausted budget after a server restart. Caption **“Separate operator probes · no model called”**. Label the capture as the fresh HTTP adoption proof. | “The same user cannot reset the budget by reconnecting. Even after a server restart, the next execution is refused. Application access checks stay in charge.” |
| **01:01–01:17** | Workbench → **Compare tokens**. Frame **Direct 46,438 / JSON 29,798 / PHP 12,806**, all ten findings correct. Caption **“Historical matched audit · Symfony AI + Hugging Face · review included · not a new HTTP benchmark”**. | “In a separate matched audit, all three paths found the same ten issues. JSON used thirty-six percent fewer tokens than direct tools. Isolated PHP saved more.” |
| **01:17–01:30** | Editor: [config/services.php](../examples/symfony/config/services.php), `http`, `ai_tools`, `ai_authorization`. Then the actual passing `code-mode:doctor` output and repository link. | “Register approved tools and a policy, then connect your agent over HTTP. This is ordinary Symfony: no worker, Docker or gVisor for JSON.” |

This fits 90 seconds with prepared views and an edited conversation. Do not try to install dependencies, explain the whole design history or show every check on screen. Keep the current product first; the optional PHP path gets its comparison column and a link to the full story.

## What each shot proves

- The fresh chat proves that a client can discover and call the independent application's approved tool. Symfony AI supplies its schema and invokes the handler; **Codex supplies the conversation model**. This is not a fresh Symfony AI + Hugging Face model run.
- The token table predates the HTTP migration. It is the retained **48-article, 24-image audit**, not the small article lookup. It uses provider-reported input + output tokens, includes reviews, and retains failed attempts. JSON used **35.8% fewer** tokens than direct calls in that matched run; PHP used **72.4% fewer**. These are neither bills nor universal savings.
- The current controls prove authentication, denial and persisted execution/call budgets. They do **not** prove forced handler termination. In-process deadlines are cooperative; hosting and downstream clients must enforce hard timeouts.
- The fresh capture ran with process creation disabled. It tests a local bearer authenticator and one persistent host, not every hosting platform or a multi-host deployment. JSON is not a prompt-injection defense. Older injection and forced-termination captures remain historical.
- Preserve the new chat's requests, responses and untrimmed footage locally. The saved native rehearsal is a separate take containing only MCP messages, not a full chat transcript. Operator and Hugging Face traces are separately labelled. Match new footage to its own requests and responses. `--trace-file` on the stock server currently supports only the PHP path, so retain the JSON records from the client.

## Edit and deliver

Use straight cuts at **0, 12, 23, 37, 50, 61, 77 and 90 seconds**. Record narration separately. Export **MP4, H.264, 1080p, 30 fps**, exactly **90 seconds / 2,700 frames**. Check text legibility and that every claim matches its source. Keep untrimmed footage outside the tracked repository. Recording, editing, uploading and formal submission remain manual steps.

The [hackathon brief](https://symfony.com/hackathon) is the source for submission requirements. The [evidence index](evidence/README.md) identifies supporting captures. The workbench's read-only walkthrough is a recording aid, not a finished video.

## Optional follow-up material

**The journey:** [From PHP to JSON](https://ronaldtebrake.github.io/symfony-hackathon/#version-two) explains the current execution model, then the infrastructure trade-off that led to it. PHP remains useful for loops, joins and filtering; easier managed isolation could improve its adoption later.


**Reproduce model evidence:** `php bin/console code-mode:catalog-proof --no-debug` runs operator checks; `--live` adds paid Symfony AI + Hugging Face inference. Both retain attempts. The public catalog capture includes five live scenarios and the independent app's operator proof. The native client rehearsal is a separate file; no video is included.

<details>
<summary>Supporting PHP evidence: repeated evaluation, timelines and response measurements</summary>

## Historical repeated direct/PHP evaluation

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

The repeated PHP evaluation is supporting evidence. Keep it separate from the current JSON suite and the matched three-way audit; their protocols and costs differ.

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

</details>

## Refresh the other proofs

| Command | What it runs |
| --- | --- |
| `php bin/console code-mode:catalog-proof --no-debug` | Current JSON control probes and fixed five-scenario reference suite; no Docker or model. Add `--live` for separately recorded catalog conversations. |
| `composer proof:http --working-dir=examples/symfony` | Fresh HTTP consumer: authentication, own native tool, allowed/denied reads, call limits and budget persistence after restart. |
| `php bin/console code-mode:huggingface-compare --no-debug` | Both live Symfony AI approaches; requires a host-only Hugging Face token. [Protocol and retained failures](huggingface-comparison.md). |
| `composer demo` | Model-free reference comparison: real MCP and gVisor, checked-in PHP. |
| `composer demo:security` | Three fixed PHP probes: approved read, denied delete, infinite loop. No model called. |

Security probes use two seconds, 128 MiB and three calls each. Normal audit executions use 15 seconds, 128 MiB, 30 calls and two attempts. The loop must complete an approved read before timing out; each exact container must be confirmed absent. A generic failure or unknown cleanup state cannot pass. See the [security model](../packages/php-code-mode/docs/security.md).

The CLI also supports `composer demo -- --mode=live --scenario=impact` and `--question='Count published articles by language. Return counts.'`. Custom answers are marked **review required**; they have no independent correctness badge. Runtime settings and permissions remain host-controlled. Custom-question live inference is outside the captured proof.

The [hackathon brief](https://symfony.com/hackathon), checked **3 November 2026**, asks for a public runnable repository and a 90-second real demo by **22 November, 18:00 CET**. The [evidence index](evidence/README.md) identifies the sources for each claim.
