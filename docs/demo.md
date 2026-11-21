# Demo for the judges

A growing catalog creates two problems for an agent. Which tools does this job need? And how does the team use them together? Our Symfony app exposes two MCP tools for search and execution, then publishes the team’s procedure alongside its tools in a Group. [Watch the 90-second demo](https://ronaldtebrake.github.io/symfony-hackathon/#judge-demo) · [Try the prompt](codex.md#run-the-film-prompts)

> Please find and follow our team’s refund procedure for order R-1042. What should I tell the customer?

The featured desktop cut shows R-1042 going to manual review and R-1043 being denied on its first read. The final ten seconds show the architecture and the repository.

Ronald narrates the demo using ElevenLabs v4. [Download the MP4](../public/media/symfony-code-mode-judge-90s.mp4) · [Read the transcript](judge-workflow-voiceover.txt)

## Run the client-written refund review

Use the native client quickstart to launch a fresh Symfony fixture and ask the featured support question. A fresh agent may choose different wording or write different JavaScript. For the separate single-prompt experiment, the receipt contains the original question, script, tool calls and outcome. [Run the film prompts](codex.md#run-the-film-prompts) · [Single-prompt receipt](evidence/programmatic-refund.json)
