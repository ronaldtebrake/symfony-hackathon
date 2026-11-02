# With and without Code Mode: the reference baseline

The [committed comparison](evidence/reference-comparison-2026-10-31.json) preserves both sides of the **31 October 2026, 12:58 CET** reference execution. It is a byte-for-byte copy of the original local report, reviewed and committed on 2 November. The same capture underlies the README animation and comparison screenshot.

| Observation | Direct MCP tools | Code Mode |
| --- | --- | --- |
| Published articles checked | 48 | 48 |
| Matching findings | 10 | 10 |
| Actual upstream MCP calls | 10 | 10 |
| Toolbox calls | 10 | 3 (search, describe, execute) |
| Tool-result bytes | **20,154** | **3,014** |
| Model requests | 0 | 0 |

Both paths use the same synthetic Symfony dataset, permissions and batch-capable tools: four article-list pages, four article-detail batches and two media batches. The direct baseline batches too. Both outputs match the independent fixture oracle. Code Mode runs the checked-in PHP in gVisor and returns compact findings; direct calls return each batch through the toolbox. This produces **85% less serialized tool-result payload** in this reference workflow.

This is an operator-written reference comparison. The MCP calls and isolated PHP execution are real; no model was called. Payload includes Code Mode discovery and excludes prompts, initial tool schemas, generated code and protocol framing. It does not establish model-token, cost or latency savings. The separate [real Hugging Face capture](huggingface-proof.md) verifies model-generated Code Mode execution. The [live direct-tool baseline](huggingface-baseline.md) now records the other path, including two failed answers and their actual usage; it does not establish an equivalent-result performance comparison.

The raw report retains both results, host call names/outcomes/sizes/timings, measured toolbox-result byte counts, and the executed reference PHP. It predates full tool-argument/result capture and per-container cleanup records; those observations have not been added retroactively. Its recorded byte totals can be checked against its result events, but exact message contents are not retained in this older artifact. Tests recheck both answers against the independent dataset, call counts, payload arithmetic and source.

Open [the comparison workbench](http://127.0.0.1:8000/demo#workbench) after `composer install` and `composer start`. A fresh checkout displays this reviewed report without an API key or running sandbox. A newer local `var/demo/latest.json` takes precedence, including failed or in-progress runs. An unreadable or oversized local report fails to load instead of showing the older success.

To produce a new comparison, configure the [gVisor runtime](../packages/php-code-mode/README.md#run-the-proof) and run `composer demo`. It writes only the ignored local report; it does not replace the committed baseline. Keep live-model captures and this reference evidence clearly labelled when recording the judge demo.

Review validation on 2 November: **7 evidence/store/HTTP tests, 126 assertions passed**. These cover both recorded answers, call counts and measured totals, fresh-checkout fallback, preservation of local failures, oversized-report rejection and local GET-only report access. The exact copied artifact passed a credential scan. This review did not rerun inference or the sandbox.
