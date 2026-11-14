# The 90-second Symfony product demo

[Watch the silent film and explore its chapters](https://ronaldtebrake.github.io/symfony-hackathon/#demo-tour) · [Download MP4](../public/media/symfony-native-tools-demo-90s.mp4) · [Capture details and checks](../public/media/video-proof.json).

The film keeps **51 seconds of real agent footage** central: discover a published-article tool, read article 101, revoke permission, and repeat the same JSON call. Symfony denies the fresh read even though the agent already knows the contract. Separate inserts show the new Maker command, shared voter and actual development profiler. All articles are synthetic. There is **no speech, music or audio track**.

The two original screen recordings were supplied on 14 November. Their chat and tool responses are not reconstructed. The operator changed permission between recordings; that action is explained by a caption. New development captures are labelled separately because the footage predates the voter/profiler additions. Original recordings and intermediate renders stay outside the tracked repository; only the final film, poster, two profiler screenshots and compact capture record are published.

## Film chapters and text alternative

| Time | On screen | Message and evidence |
| --- | --- | --- |
| **00:00–00:03** | Symfony violet title: “Two MCP entry tools. Your approved catalog.” | Discover schemas on demand. Keep Symfony’s access checks. The two entry tools are search_tools and execute_tools. |
| **00:03–00:10** | Actual Maker output, reformatted for readability. | `make:code-mode-tool InventoryLookup` creates a native AI tool, voter, registration and security test. The generated test passes: one test, two assertions. The scaffold stays hidden and denied until implemented. This is a separate example from the article tool. |
| **00:10–00:38** | Original agent footage: question, discovery, opened JSON and returned title. | “Look up published article 101 in our Symfony application. What's its title?” The agent calls `search_tools`, then `execute_tools` with `publication_read` and `id: 101`. The result is **A Symfony application owns its tools**. Seven seconds of waiting are visibly trimmed. |
| **00:38–00:46** | Current tool and controller source, with Symfony syntax colours. | `#[IsGrantedTool(ArticleVoter::READ, subject: 'id')]` and `#[IsGranted(ArticleVoter::READ, subject: 'id')]` share the voter. Discovery uses it too; execution rechecks current access. This implementation was added after the original recording. |
| **00:46–00:51** | Violet permission-change card. | The operator revoked `publication.read_published` between recordings. Same authenticated user and application connection; no budget reset. This is a caption of the actual change, not footage of an operator command. |
| **00:51–01:14** | Full second recording, original speed, then final still hold. | “Can you refresh that article from the application?” The identical JSON call returns `tool_denied`, `completedCalls: 0`. The agent explains the denial and says the earlier title remains in chat but is not a fresh result. |
| **01:14–01:22** | Actual Symfony profiler screenshots: allowed, then denied. | A separate authenticated HTTP capture records native `requested` / `succeeded` events for the allowed read. After revocation: denied broker call, no native tool events, two attempts consumed. No model was called for these profiles. |
| **01:22–01:30** | Separate historical matched audit and repository link. | Ten correct findings on all paths: direct **46,438**, JSON **29,798**, sandboxed PHP **12,806** provider tokens. JSON used **35.8% fewer** than direct tools in this run. Same model, question, data and permissions; reviews included. Not this lookup's usage, a new HTTP benchmark or a bill. |

Source views: [handler](../examples/symfony/src/ArticleTool.php), [voter](../examples/symfony/src/ArticleVoter.php), [controller](../examples/symfony/src/ArticleController.php). The workbench supplies a video player, seekable chapters, highlighted source, actual Maker output and profiler metadata. Its supporting HTTP records are separate operator checks, not the recorded chat transcript.

## Submission description

Draft for the official project entry; not submitted. Project name: **Symfony Code Mode**.

> Symfony Code Mode gives agents two stable MCP entry tools: search for approved capabilities, then call them with JSON. Symfony AI supplies the schemas and invokes existing handlers; Symfony Security controls which tools a user can discover and rechecks access on every call.
>
> We started with generated PHP in gVisor, where code could join and filter tool results before returning them to the model. That worked, but required a separate runtime. The current JSON catalog runs in an ordinary Symfony HTTP application, making adoption simpler while preserving authorization and call budgets. Sandboxed PHP remains available for computation.
>
> The real demo reads an article, revokes the user's permission, then denies the identical follow-up. A Maker command, shared voter and profiler panel make the integration familiar to Symfony developers. In a retained matched Symfony AI + Hugging Face audit, JSON used 35.8% fewer provider tokens than direct tools, with ten correct findings on both paths and review costs included. That historical audit predates HTTP; the current application has a separate 31-check execution proof.
>
> Clone the repository and run the HTTP proof with PHP and Composer. No Docker or model key is required for that proof.

Submission links:

- Repository: https://github.com/ronaldtebrake/symfony-hackathon
- Judge quickstart: https://ronaldtebrake.github.io/symfony-hackathon/#quickstart
- Video player: https://ronaldtebrake.github.io/symfony-hackathon/#demo-tour
- Final MP4: https://ronaldtebrake.github.io/symfony-hackathon/media/symfony-native-tools-demo-90s.mp4

Suggested tags: **MCP**, **MCP SDK**, **Agent**, **Tool Calling**, **symfony-ai**. Attach the final MP4 and complete submission through SymfonyConnect; the repository push does not submit the entry.

## Record another take

The following prompts are stricter reproduction instructions, not a transcript of the existing film. The additional post-revocation search is useful to demonstrate that the contract disappears; it is covered by the separate HTTP proof and is not in the supplied footage.

## Prepare the real application

From the repository root, before recording:

```sh
composer install --working-dir=examples/symfony
php examples/symfony/bin/console cache:clear
composer check --working-dir=examples/symfony
php examples/symfony/bin/console code-mode:doctor
composer proof:http --working-dir=examples/symfony
```

Connect your client using the existing [JSON MCP configuration](codex.md#connect-the-json-catalog). Confirm **symfony_catalog** exposes only **search_tools** and **execute_tools**. The application and client need the same local bearer token; keep it off screen. Start the app in a separate terminal:

```sh
composer start --working-dir=examples/symfony
```

Before **each new take**, run these as the operator:

```sh
php examples/symfony/bin/console demo:reader-access grant
php examples/symfony/bin/console code-mode:reset-budget demo-reader
```

The example starts without a read grant. `demo:reader-access` changes an ignored local permission fixture, loaded by Symfony Security on every HTTP request. It is an operator command, never an MCP tool. Production applications use their existing user/permission store. Neither changing permissions nor reconnecting resets the two-execution budget. The proof harness uses a separate temporary permission file; after running it, prepare a new take with the commands above.

Keep the same authenticated user, token, server and conversation for the entire take. **Do not reset the budget between the two reads.** Keep other MCP servers out of the take and instruct the agent not to read files or use its shell. The application's policy cannot restrict unrelated client capabilities.

### Exact prompts

**Turn one:**

> Use only the symfony_catalog MCP server to read published article 101 and tell me its title. Discover its contract first. Do not read workspace files or use shell commands.

Confirm these actual tool calls, not only the agent's prose:

```json
{"query":"publication"}
{"calls":[{"name":"publication_read","arguments":{"id":101}}]}
```

The search must include `publication_read`. The read must return `{"results":[{"name":"publication_read","value":{"id":101,"title":"A Symfony application owns its tools"}}]}`. A different valid search phrase is fine; retain the actual request.

**Between turns, record the operator terminal:**

```sh
php examples/symfony/bin/console demo:reader-access revoke
```

Expected output: `demo-reader: publication.read_published revoked`. Leave the application running. Do not reconnect, change tokens or clear any state.

**Follow-up in the same chat:**

> The operator has revoked my read permission. This is an approved access-control test: call publication_read for article 101 exactly once, using the contract already discovered. Do not search before that call. Then search for publication once and explain both results. Do not retry, use another access path, or present the earlier title as a fresh read.

The **identical** execute request must return:

```json
{"error":{"reason":"tool_denied","tool":"publication_read","index":0},"completedCalls":0}
```

The subsequent search must contain `"tools":[],"matched":0`. The two entry tools remain advertised; the underlying article contract is hidden. This is a permission denial, not `execution_limit`, a different user's token, or a missing contract before discovery.

The agent can explain the denial and distinguish the previous result from a new read. Revocation cannot erase information already in the conversation. If it declines to perform the test, retries, reads workspace files or invents a fresh result, retain that attempt and prepare a separate take; do not splice a different call into its transcript.

## Capture the development inserts

In a disposable copy of the independent Symfony application, enable the development environment and run:

```sh
APP_ENV=dev php bin/console make:code-mode-tool InventoryLookup
APP_ENV=dev php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Tool/InventoryLookupVoterTest.php
```

Show the real generated file list and passing deny-by-default test. Do not imply that this unimplemented scaffold is the article handler used in the conversation. Open the installed tool/controller source to show the shared voter attributes.

For profiler shots, use `APP_ENV=dev` locally and capture fresh authorized and revoked requests against a separate app copy. In each Symfony profile, open **Code Mode**. Show broker outcomes, native AI events and attempts used. Keep this labelled **“Separate HTTP proof · no model called”** if the requests are operator-authored. The Code Mode collector omits arguments, results and identities; other profiler panels can expose sensitive data. Keep the development profiler private and never record authentication headers.

## What each shot proves

- The fresh chat uses the client's model. Symfony AI supplies schemas and handler invocation; this is **not** a new Symfony AI + Hugging Face model run.
- Same-user revocation takes effect on the **next HTTP request**. The discovered name remains known, but execution rechecks access. The local authenticator reads the changed permission fixture; no reusable package security checks or budgets are relaxed. This does not claim cancellation of a handler already running.
- The separate HTTP proof also tests restricted article 202, cross-user discovery isolation, unchanged budgets after regrant and server restart, and authentication/origin checks. These extra probes need not fit in the recording.
- The token table is the retained **48-article, 24-image audit**, not this small lookup. It includes model reviews and failed attempts. JSON used **35.8% fewer** provider-reported tokens; PHP used **72.4% fewer** in this matched run. These are not bills or universal savings. The HTTP runtime has not been re-benchmarked.
- JSON runs trusted installed handlers in process. No worker, Docker or gVisor is required for this sequence. Hosting and downstream clients enforce hard timeouts; JSON is not a prompt-injection defense.
- Keep the real requests, responses and untrimmed footage locally. The workbench is a read-only evidence viewer, not a live mirror of your conversation. `--trace-file` is PHP-only; save the JSON records through the client.

## Edit and deliver

Use cuts at **0, 3, 10, 38, 46, 51, 74, 82 and 90 seconds**. Export **MP4, H.264, 1080p, 30 fps**, exactly **90 seconds / 2,700 frames**, without an audio track. Keep the denied request and response together. Label waiting trims and separate captures. Store untrimmed footage outside the tracked repository. The finished film is included in Pages; formal hackathon submission remains a separate step.

For another take, grant access and reset the budget before starting a new conversation. To leave the local reader disabled when finished, run `demo:reader-access revoke`.

The [hackathon brief](https://symfony.com/hackathon) is the source for submission requirements. The [evidence index](evidence/README.md) identifies supporting captures.

## Optional follow-up material

**The journey:** [From PHP to JSON](https://ronaldtebrake.github.io/symfony-hackathon/#version-two) explains the current execution model, then the infrastructure trade-off that led to it. PHP remains useful for loops, joins and filtering; easier managed isolation could improve its adoption later.

**Reproduce model evidence:** `php bin/console code-mode:catalog-proof --no-debug` runs operator checks; `--live` adds paid Symfony AI + Hugging Face inference. Both retain attempts. The public catalog capture includes five live scenarios and the independent app's operator proof. The earlier native client rehearsal is a separate file. The film and its new development capture have their own provenance record.

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
