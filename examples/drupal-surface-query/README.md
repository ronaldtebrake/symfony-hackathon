# Data Surface query proof

A separate experiment: compose two approved Drupal searches in isolated PHP,
deduplicate their results, and return three visible records. It uses the same
Code Mode package and `McpProvider` as the Symfony examples. It adds no routes,
providers or reports to the main Symfony workbench or the existing Drupal demo.

```text
Code Mode PHP → drupal.search → MCP → Tool API → QueryRunner → published-content View
```

The Rosetta query adapter owns the Data Surface contract and Drupal access checks.
The provider maps one approved MCP tool to `$tools->drupal->search(...)` and
derives PHP guidance from its discovered schemas. The gVisor guest receives no
Drupal account selector, credentials, database connection or DDEV socket.

## Run it

Requires the root Composer dependencies and configured gVisor runtime, plus the
existing Rosetta DDEV sandbox with `webmcp_integration_surface_query` and
`webmcp_integration_surface_demo`. Those experimental modules are maintained in
the separate WebMCP/Rosetta checkout, not bundled here. The tested Data Surface
checkout is `afd33e2e570349544c13cb52aa48a552bd4139d2`, unmodified. Apply the field
access and empty-output fixes described below in the Rosetta adapter first.
Tool API, MCP Server and MCP Server Tool Bridge must also be installed.

From this repository's root:

```bash
export SURFACE_PROOF_SITE=/absolute/path/to/rosetta-ddev-site
bash examples/drupal-surface-query/scripts/setup.sh
php examples/drupal-surface-query/proof.php
```

Alternatively put `SURFACE_PROOF_SITE` in this example's ignored `.env.local`
for PHP runs. The setup script uses the exported shell variable. It installs
only this optional fixture module and creates its own reader, role, content
type, five synthetic nodes and MCP tool configuration. It preserves existing
fixtures on repeat runs and refuses conflicting database identifiers.

This is local MCP over STDIO, launched by a trusted operator as
`code_mode_surface_reader`. Selecting a Drush account is **not remote client
authentication**. DDEV hosts Drupal; gVisor isolates the generated execution
program. No model or Hugging Face token is required.

## What passes

| Probe | Required outcome |
| --- | --- |
| Two overlapping searches | Two real broker calls; three unique accessible matches |
| No matching content | `{"matches":[],"count":0}` from the runner, MCP and Code Mode |
| Unpublished or title-denied content | No returned rows, titles or contribution to count |
| Limit 11 | Rejected by the broker schema; maximum is 10 |
| Unknown `include_unpublished` input | Refused by the Drupal input pipeline |
| Raw MCP tool name | Denied by the local broker; only `drupal.search` is approved |
| Permission revoked after discovery | MCP fails and the already-discovered Code Mode session cannot read |
| Permission restored | The same PHP and session can read again |
| Third execution attempt | Refused before creating a container |

The proof temporarily removes only its reader's search permission and restores
it in `finally`. Independent operator snapshots verify the account, real field
access decisions, unchanged node data, source/config hashes and restored
permissions. These controls are not exposed as tools. Each execution allows
15 seconds, two tool calls, 3 seconds per call and 128 MiB guest memory. The
existing broker, sandbox and security limits implementation is unchanged.

## Field access and empty results

The View host checks View access, publication, entity access, `nid`/`title` field
access and canonical-route access before serializing each row. If a required
field is denied, it omits the row and counts only returned rows. The View limit
applies before this final filter; fewer than the requested number may return.

Core interprets a required Typed Data list as non-empty. `SearchSurface` now
declares the collection optional/nullable, with required items and required
properties. This producer always returns both `matches` and `count`, including
an empty list. The schema does not claim that list presence is mandatory.

Core also skips nested validation of some empty containers, including `[null]`.
The adapter's `OutputPresence` checks required nested values before unchanged
Data Surface output conformance. Null items, empty item maps, missing required
fields/counts and wrong scalar types are rejected. This strengthens the shared
runner for form, Tool API and MCP callers. No upstream Data Surface patch is
needed. Existing historical schema-fidelity captures remain untouched.

Tool API's conversion still loses some rules, including nested unknown-key
rejection and root output requiredness. PHP interfaces remain guidance; Drupal's
runtime pipeline enforces the original rules. Generic MCP `isError` is recorded
as `tool_failed`, not guessed to mean an authorization denial. The revocation
proof also requires independent access snapshots and a successful restored read.

## Inspect the evidence

[Reference PHP](proof/compose.php.txt) is operator-written, **not model-generated**.
[The reviewed capture](proof/reference.json) includes submitted and executed PHP,
schema-derived interfaces, exact results, host tool ledgers, timings, versions,
source hashes and container cleanup. Repeat runs and failed attempts stay in
ignored `var/`; only one reviewed reference belongs in the repository.

```bash
php bin/phpunit --bootstrap vendor/autoload.php examples/drupal-surface-query/tests
```

The reference demonstrates permission-preserving reuse of the Symfony package
across transports. It makes no model-quality, token-saving, pagination or public
service security claim. A future Data Surface provider can reuse this boundary
without coupling the Symfony package to Drupal or bypassing the query pipeline.

## JSON batch comparison

The separate batch server reuses the same approved `McpProvider`, reader identity,
query schemas and per-call broker checks. It executes literal calls to trusted
handlers; no PHP program, Docker client or gVisor runner is involved in this path.
DDEV still hosts the Drupal fixture. Configure an MCP client to start:

```bash
php examples/drupal-surface-query/batch-mcp.php
```

For the separate two-tool interface, append `--catalog`. It advertises only
`search_tools` and `execute_tools`. Search returns complete `drupal.search`
contracts, so the describe step is unnecessary; the same JSON below goes to
`execute_tools`. Provider approval, Drupal entity/field access, supervision and
limits are unchanged. The retained reference comparison below used the original
three-tool interface; it is not a live measurement of this catalog iteration.
The two-tool server was also checked against the real DDEV site on 9 November:
both restricted canary searches returned no visible matches, then the permitted
orchard follow-up returned two accessible records in the same session. This was
a read-only MCP smoke check, with no model or generated PHP execution.

Describe `drupal.search`, then call `tools_execute` with:

```json
{"calls":[
  {"name":"drupal.search","arguments":{"values":{"keywords":"SCMQ orchard","limit":10}}},
  {"name":"drupal.search","arguments":{"values":{"keywords":"SCMQ garden","limit":10}}}
]}
```

Run the reference probes without gVisor, or compare both paths:

```bash
php examples/drupal-surface-query/compare-batch.php --batch-only
php examples/drupal-surface-query/compare-batch.php
```

The paired [9 November reference capture](proof/batch-comparison.json) passes
**34 checks**. Both paths use the same schemas and make the same two actual MCP
reads, with the same arguments, reader and returned data. Limits remain 15 seconds,
3 seconds/tool, two calls per attempt and two attempts per session. Both exclude
unpublished and title-denied records, return an empty list/count for no matches,
reject unknown inputs and raw upstream names, and fail after permission revocation
following discovery. The same sessions can read again after restoration. The
PHP containers are confirmed removed. All attempts, including an initial run
blocked by local Docker-socket access, are retained in ignored `var/`.

| Composition reference | JSON batch | Code Mode PHP |
| --- | ---: | ---: |
| Actual underlying MCP reads | 2 | 2 |
| Returned records | 4 across two lists, including one duplicate | 3 unique records |
| Whole invocation, including cleanup | 4,408 ms | 4,892 ms |
| Execution request payload tokens | 47 | 141 |
| Execution response payload tokens | 118 | 83 |
| Definitions + discovery + execution, each counted once | 864 | 1,151 |

The batch **does not produce the requested combined answer**: the caller must
still deduplicate, sort and calculate the final count. The verifier checks its
raw lists against independently known visible records. PHP performs that work
before returning. Batching has less request/discovery overhead in this tiny
one-tool example; PHP returns a smaller response. This is a reason to offer both
paths, not evidence that either always wins.

The [token breakdown](proof/batch-payload-tokens.json) uses pinned
`tiktoken==0.12.0`, `o200k_harmony`, and hashes of the exact canonical payloads.
These are **offline payload tokens**, not provider usage, financial savings or a
model-quality benchmark. They exclude prompts, repeated context, protocol/chat
framing, final answer generation and the security probes. Both plans are
operator-written; no model was called. The single sequential timing sample
includes DDEV/MCP connection overhead and is not a latency benchmark.

To reproduce the optional offline counts in an isolated Python environment:

```bash
python3 -m venv /tmp/surface-tokenizer
/tmp/surface-tokenizer/bin/pip install tiktoken==0.12.0
/tmp/surface-tokenizer/bin/python examples/drupal-surface-query/measure-batch.py examples/drupal-surface-query/proof/batch-comparison.json
```

A purpose-built multi-term Data Surface query could move deduplication into
trusted Drupal code. That extension is not implemented here: its result limits,
ordering and field-access semantics would need their own contract and tests.
This proof supports reuse of typed query contracts, not a claim that Drupal Core
already provides a general JSON query language. See [batch adoption and hosted
Symfony limits](../../docs/batching.md).

## Live agent conversation

The [reviewed conversation](proof/client-conversation.md) ran successfully in
Codex (`gpt-6-astra`): two restricted searches returned no visible matches, the
agent proposed a permitted alternative, and its follow-up combined two published
searches into **three unique records**. All **13 evidence checks** pass, including
the four actual MCP-backed reads and both gVisor container removals.
[Full capture](proof/client-conversation.json).

The two initial Symfony AI/Hugging Face attempts were rate-limited before any PHP
was generated. They remain in ignored local capture directories. The successful
client capture is separate evidence, not a successful Hugging Face run.

To repeat with the installed, signed-in Codex CLI:

```bash
python3 examples/drupal-surface-query/scripts/capture-client.py --check
python3 examples/drupal-surface-query/scripts/capture-client.py
# Use the capture directory printed by the command:
php examples/drupal-surface-query/review-client.php /absolute/path/to/capture
```

This uses an ephemeral client with shell, browser, plugins and delegation disabled,
a read-only client sandbox and a separate Data Surface MCP server. It leaves
existing client configuration untouched. Both turns share one server process;
restarting the server between turns would reset the budget and is not this proof.
The offline review command writes a transcript and a verified JSON report; it
never executes recorded PHP. The capture records client usage, which includes
client context and cached input and is not comparable to the Hugging Face audit.
The launcher uses the [Codex app-server protocol](https://learn.chatgpt.com/docs/app-server).

To retry Symfony AI/Hugging Face once the provider permits requests:

```bash
# Uses the existing Hugging Face token/model from the root's ignored .env.local.
# Consumes inference credits. Keep the token out of command-line arguments.
php examples/drupal-surface-query/conversation.php
```

This separate harness uses Symfony AI's `Agent` and the reusable `CodeModeToolbox`.
The model discovers the query, generates PHP to search for the two restricted
fixtures, explains the available boundary and proposes an alternative. A second,
operator-authored user turn accepts a published-content search. The model writes
new PHP to combine and deduplicate the permitted results. Both turns retain the
conversation, reader identity and original two-attempt execution budget. No
fixtures or permissions are changed during inference.

The first search should produce **no visible matches**, not an explicit access
error. The independent Drupal snapshot proves the fixture restrictions; the agent
cannot distinguish missing content from inaccessible content using empty results.

Every attempt is retained under ignored `var/conversation-*/`, including provider
failures. `var/latest-conversation.md` is the readable transcript; the JSON report
contains exact generated and executed PHP, broker calls, actual MCP-backed
invocation arguments/results, source/config snapshots and provider usage. The
capture observer delegates the original policy, schema and invocation unchanged.
Private host journals are never available to the guest or model. Capture checks
verify exact data and evidence links; the agent's prose still needs human review.

This uses the existing demo's observation helpers without booting its application
kernel or changing its workbench, reports, MCP connection or provider configuration.
The reference proof above remains an operator-written execution proof; a live
capture is a separate artifact and makes no token-saving claim.
