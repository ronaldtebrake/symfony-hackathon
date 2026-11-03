# Evidence used by the hackathon demo

These are reviewed execution captures, not generated example outputs. The read-only [workbench](http://127.0.0.1:8000/demo) displays them without starting models or PHP. Local reports in ignored `var/demo/` take precedence, including failed runs.

| Capture | What it establishes | Viewer |
| --- | --- | --- |
| [Hugging Face matched pair](huggingface-pair.json) | Real Symfony AI generation, identical model/question/data/permissions, both answers correct, measured usage, generated PHP and host calls. Includes all 21 development invocations and their original verdicts. | Live comparison / Symfony AI run |
| [Codex conversation](codex-conversation-2026-11-01.json) | Desktop chat, 1 November at 12:42 CET: audit and recomputed repair plan, two generated programs, twenty successful calls. Both results matched the fixture. Model and MCP process identity were not in the export. | Codex run |
| [Boundary recovery](boundary-recovery-2026-11-02.json) | Codex CLI rehearsal: denied draft read, agent-proposed alternative, user-selected published-content audit. No denied value returned; both containers removed. This is not desktop footage. | Recovery |
| [Security probes](security-proof-2026-11-01.json) | Fixed PHP programs: authorized read, `tool_denied`, and `wall_timeout` after a completed read. Exact container removal checked. No model called. | Security |
| [Reference comparison](reference-comparison-2026-10-31.json) | Operator-written algorithms: 48 articles, ten matching findings and ten MCP calls each; 20,154 → 3,014 serialized tool-result bytes. No model called. | Reference |
| [Drupal authorization](drupal-authorization-proof-2026-11-02.json) | Optional DDEV example: published read allowed, restricted read denied with independent Drupal access evidence and cleanup checks. No model called. | [Drupal example](../../examples/drupal/README.md) |

The original [Hugging Face Code Mode capture](huggingface-proof.json) and [failed direct baseline](huggingface-baseline.json) are retained as exact regression fixtures. The baseline binds the original Code Mode file by SHA-256. Their attempts also appear in the matched pair; retaining these original bytes preserves that historical binding and the replay tests. Superseded prose and screenshots are not needed to retain the failed runs.

[Comparison methodology and diagnosis](../huggingface-comparison.md) explains the prompt changes and limits of the successful pair. [Record the demo](../demo.md) explains how to match new footage to its actual execution records. These captures are our observed evidence, not an independent security certification or a completed hackathon submission.

## Visuals

Only two visuals are kept: the README's self-contained [animation](../images/code-mode-value.svg) and the [actual matched-comparison screenshot](../images/huggingface-matched-comparison.jpg). The animation illustrates reference payload bytes, not model tokens or execution speed; preserve its reduced-motion behavior when editing. Both use the Symfony AI palette. The unretouched screenshot was captured on 2 November 2026. Other proof details are available in the viewer and JSON rather than duplicated as screenshots.
