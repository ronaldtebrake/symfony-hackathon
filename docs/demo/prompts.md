# Codex recording prompts

Copied exactly from the [reviewed 1 November capture](../evidence/codex-conversation-2026-11-01.json). These are inputs for a new recording, not a guarantee of identical PHP or output. Use the configured Symfony fixture and one fresh MCP process for both turns. The workbench now displays this same desktop rehearsal, recorded at 12:42 CET. See the [recording guide](README.md) for the matching footage sequence.

## First message — audit

```text
Use Symfony Code Mode to audit every published article in the Symfony fixture for missing meta descriptions or missing hero-image alt text. Return the affected article IDs and titles with their issues, and the total number audited. Use the MCP tools rather than repository files. Keep this read-only. Show the PHP you actually submit and summarize the host-recorded tool calls. Use one php_execute call so we retain the second execution for a follow-up.

This is a fresh rehearsal for our judge recording. Keep the actual MCP request and result visible, including the submitted code, returned value and toolCalls. If the Symfony Code Mode MCP tools are unavailable, stop and report that connection problem; do not substitute a repository script or saved proof.
```

## Follow-up — reshape the task

Send after the first execution completes, in the same conversation. Keep the remaining execution for this computation.

```text
Reshape that into an editor repair plan. Group the issues by type and identify shared hero images whose missing alt text affects more than one published article. Rank those image fixes by how many published articles they help and include the affected IDs. Recompute the impact through Symfony Code Mode using the remaining execution; do not merely reformat the previous answer. Keep it read-only and show the new PHP and actual tool calls.
```

The host grants only the existing read-only capabilities. These prompts cannot change permissions or reset the two-attempt limit. Keep the actual submitted PHP and MCP results from the new take; do not present a model's prose summary as the tool trace.
