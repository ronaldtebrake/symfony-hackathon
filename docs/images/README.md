# README visuals

`code-mode-value.svg` is a self-contained animated explanation of Code Mode, inspired by the supplied Faultline comparison. It illustrates the measured reference-run payloads; its animation timing does not represent execution speed. Both paths retain ten batched MCP calls and the same findings. It contains no JavaScript, external assets or fonts, and can animate when embedded as an image in the README. Reduced-motion readers get the complete static comparison.

Edit the SVG directly. Preserve the reference-run label, byte-versus-token distinction, equal work on both sides, and the reduced-motion rule. Preview it as an `<img>` to check README embedding, not only as an inline SVG.

The palette follows [Symfony AI](https://ai.symfony.com/), inspected on 1 November 2026: brand violet `#5a55a5`, deep purple `#4c1d95`, magenta `#db2777`, blue `#1d4ed8`, and accent violet `#7f5bff`. Pale lavender surfaces and a dark purple code panel adapt those colors to this comparison. The SVG remains self-contained.

`code-mode-proof.jpg` is an actual browser capture of the local Symfony workbench on 31 October 2026. It shows the verified reference report: 48 published articles, 10 matching findings and 10 batched MCP calls per approach; 20,154 versus 3,014 tool-result bytes. No model was called. The screenshot has not been retouched.

To refresh it, run `composer demo` with the configured gVisor host, start `composer start`, open `/demo#workbench`, and capture the comparison and PHP program. Keep the reference/live label and payload definition visible. Use the synthetic fixture for repository screenshots.
