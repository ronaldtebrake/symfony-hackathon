# Does a permission-aware hint help an agent find the article reader?

The model always sees the same two tools, `search_tools` and `execute_tools`. The experiment changes one thing in its input. While the caller can discover the reader, the hint arm adds **“You can look up published articles.”** to the system message. The sentence contains no tool name, article ID or answer.

## Results from 19 November 2026

The hint added **420 provider tokens and one model request** in the completed DeepInfra trial. Both arms scored **0/3**. On this fixture the extra sentence gave us no reason to enable it by default.

| Question | Search alone · tokens / model requests | With hint · tokens / model requests | What happened |
| --- | ---: | ---: | --- |
| Direct title lookup | 546 / 1 | 546 / 1 | Both answered “unknown” without searching. |
| Revoked follow-up | 1,220 / 2 | 1,046 / 2 | Both saw an empty search. Neither had a previously discovered contract or attempted the required read. |
| Indirect reading-list question | 1,090 / 2 | 1,684 / 3 | Both searched for the publishing reference and found no reader. The hint arm made a second unsuccessful search. |
| **Total** | **2,856 / 5** | **3,276 / 6** | **2 vs. 3 MCP calls.** |

DeepInfra returned usage for every request. Search used 1,990 input and 866 output tokens. The hint used 2,498 input and 778 output tokens. Both arms observed an empty hint list after the grant was revoked. Every model request on that follow-up had the same system-message digest as search alone. Knowledge from previous turns remains in history. This is one model, one reader and one run per question on this route. [Completed trial, including every question and call](02-deepinfra.json)

The earlier matched trial pinned Groq. Both arms found the correct contract on the direct question, then omitted the required article ID three times and reached the four-request limit. Search alone used 2,794 tokens for that turn and the hint used 2,830. HTTP 502 responses interrupted both indirect lookups. The direct turns yielded no final answers, so neither revoked follow-up ran. The trial sent five model requests for search and six with the hint. Its full token totals are unknown. Both trials used `openai/gpt-oss-120b` through Symfony AI and Hugging Face. [Incomplete first trial](01-groq.json)

## What revocation actually changed

A separate HTTP control went through the full sequence with zero model requests. The reader received the hint, discovered `publication_read` and read **A Symfony application owns its tools**. After revocation, the hint endpoint returned `[]`. Sending the identical read on the same connection returned `tool_denied`, zero completed calls and no article. Searching again for the exact tool name returned zero matches. This capture used the final experiment-only Symfony configuration. [Requests and responses](http-control.json)

## What the pilot measures

Each arm gets three questions. The first asks for the title of article 101. The follow-up asks the agent to repeat the read after the operator revokes access and check current visibility. The third starts a fresh conversation and asks for link text for reference #101. Each arm gets the same questions verbatim, published article, reader permissions, tool schemas, model settings and request limits. Four fresh Symfony fixtures keep the arms independent. The ordering alternates which arm goes first for the direct and indirect lookups.

A lookup earns credit when the model returns the exact title and its MCP trace shows successful discovery and a read of article 101. The follow-up needs a denied read, zero returned article data, a relevant search confirming the contract disappeared, and a final answer that reports both outcomes. Guessing the answer or quoting it from conversation history earns no credit.

Tokens are the provider's input plus output tokens across the turn. Model requests count attempts, including HTTP errors. MCP calls count the model's searches and executions. The harness also makes one authenticated HTTP request per turn in both arms to observe what the caller may discover. Only the hint arm sends the resulting sentence to the model. A normal search-only client can omit that observation request.

## Reproduce

With the root and example Composer dependencies installed and `HUGGINGFACE_API_KEY` configured locally, run one matched trial from the project root:

```sh
php scripts/compare-permission-hints.php --live --provider=deepinfra
```

A trial uses up to four model requests per question, at most 24 in total. Each run writes a new trace under `var/demo/permission-hints/`. The test-only hint endpoint uses the existing discovery voter. Its responses are private, vary by authorization and forbid caching. Seeing a hint grants no execution rights and consumes no execution budget. On revocation the harness refreshes the system message and keeps the previous conversation so the agent can still try the known contract.

To check the grant change with a local mock model and a fresh authenticated HTTP application:

```sh
php bin/phpunit tests/Integration/PermissionHintStudyTest.php
```
