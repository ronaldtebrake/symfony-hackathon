# README visuals

`code-mode-value.svg` is a self-contained animated explanation of Code Mode, inspired by the supplied Faultline comparison. It illustrates the measured reference-run payloads; its animation timing does not represent execution speed. Both paths retain ten batched MCP calls and the same findings. It contains no JavaScript, external assets or fonts, and can animate when embedded as an image in the README. Reduced-motion readers get the complete static comparison.

Edit the SVG directly. Preserve the reference-run label, byte-versus-token distinction, equal work on both sides, and the reduced-motion rule. Preview it as an `<img>` to check README embedding, not only as an inline SVG.

The palette follows [Symfony AI](https://ai.symfony.com/), inspected on 1 November 2026: brand violet `#5a55a5`, deep purple `#4c1d95`, magenta `#db2777`, blue `#1d4ed8`, and accent violet `#7f5bff`. Pale lavender surfaces and a dark purple code panel adapt those colors to this comparison. The SVG remains self-contained.

The proof screenshots are actual browser captures refreshed on **1 November 2026**, using the same Symfony AI palette as the animation. They have not been retouched.

| Image | Recorded evidence shown |
| --- | --- |
| `code-mode-proof.jpg` | Reference report from **31 October**: 48 published articles, 10 matching findings and 10 batched MCP calls per approach; 20,154 versus 3,014 tool-result bytes. No model was called. |
| `codex-proof.jpg` | The fresh **1 November, 12:42 CET** desktop Codex rehearsal: follow-up prompt, shared-image repair result and exact generated PHP. The screenshot shows the beginning of the scrollable program. |
| `codex-tool-calls.jpg` | The same follow-up's ten actual host-recorded upstream calls, outcomes, result sizes and timings. |
| `codex-php-presentation.jpg` | The same follow-up in **Present PHP**, captured on 1 November: large exact source, line numbers and a manually selected ranking section. The highlight indicates source navigation, not live line execution. |
| `security-proof.jpg` | The **1 November** fixed security probes: authorized read, blocked unauthorized tool and terminated infinite loop, with checked container removal. No model was called. |

All three Codex screenshots now match chat `01a196eb-12bd-7412-9fad-4d279e2cbcf8`, replacing the earlier 10:31 CET rehearsal.

Start `composer start` and open `/demo#codex-run`, `/demo#workbench` or `/demo#security-proof`. The Codex section reads the reviewed [capture](../evidence/codex-conversation-2026-11-01.json); it does not import current conversations. The other sections read ignored `var/demo/latest.json` and `var/demo/security.json`. If those reports are missing, generate them with `composer demo` and `composer demo:security` against the configured gVisor host before capturing. Styling-only refreshes can reuse existing reports; record the screenshot date separately from the execution date.

Use the synthetic Symfony fixture for Symfony proof screenshots. Preserve the recorded/reference labels and payload-versus-token distinction. Capture the actual browser; do not alter execution outcomes or substitute illustrative results.

`drupal-journal.jpg` is an unretouched browser capture from **2 November 2026** of the separate local Drupal site. It shows core Olivero with the Symfony AI purple accent and synthetic journal content. It illustrates the content source, not an agent conversation or execution result. The journal links back to the Symfony workbench.
