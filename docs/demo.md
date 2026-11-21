# Demo for the judges

A growing catalog creates two problems for an agent. Which tools does this job need? And how does the team use them together? Our Symfony app exposes two MCP tools for search and execution, then publishes the team’s procedure alongside its tools in a Group. [Watch the 90-second demo](https://ronaldtebrake.github.io/symfony-hackathon/#judge-demo) · [Try the prompt](codex.md#run-the-film-prompts)

> Please find and follow our team’s refund procedure for order R-1042. What should I tell the customer?

The featured desktop cut shows R-1042 going to manual review and R-1043 being denied on its first read. The final ten seconds show the architecture and the repository.

**ElevenLabs timing.** Import the [approved MP4](../public/media/symfony-code-mode-judge-90s.mp4) and use one clip per paragraph from the [plain-text script](judge-workflow-voiceover.txt). Start the nine clips at 0:00, 0:12, 0:20, 0:32, 0:42, 0:50.5, 0:58, 1:05 and 1:20. The movie contains sound effects only. Use the final ten seconds for the credits and invitation to clone the project.

## Earlier JavaScript proof

The terminal footage shows the original third conversation reopened in Claude Code. Claude used `search_tools` to find and open `refund-review`. Between **0:43 and 1:15**, follow one value through the JavaScript. The order returned `policyId: "standard-eu"`. Claude decoded the JSON reply, read `ov.policyId` and passed it into a second `execute_tools` call. Symfony authorized both reads. The policy required manual review for this €75 refund. [Inspect the program, calls and answer](https://ronaldtebrake.github.io/symfony-hackathon/#code-mode-ready)

The first two attempts at the same question extracted the ID with a regular expression. On attempt three, Claude could see the result path in its tool preview and used the named field. All three attempts are available for inspection. A separate PHP MCP client demonstrates the proposed `groups/list` and `groups/get` methods. [Three attempts](evidence/claude-structured-refund.json) · [Native Groups calls](evidence/refund-group-discovery.json)

[Download the film](../public/media/symfony-code-mode-ready-90s.mp4) · [Narration for ElevenLabs](judge-ready-voiceover.txt) · [Edit notes and sources](../public/media/symfony-code-mode-ready-film.json)

The earlier Claude films show how the script changed. In one, Claude extracted the policy ID from text. In another it made the two reads in separate programs. [Original single-script film](https://ronaldtebrake.github.io/symfony-hackathon/#plain-demo) · [Separate-program film](https://ronaldtebrake.github.io/symfony-hackathon/#support-demo)

## Earlier terminal cut with revocation

The [earlier 90-second film](https://ronaldtebrake.github.io/symfony-hackathon/#native-explainer) shows a staged three-turn conversation with access revoked between two reads. Its separate [voiceover](judge-native-explainer-voiceover.txt) begins at 0:00, 0:06, 0:23, 0:39, 0:56, 1:01 and 1:22.

## Narrate the explainer

The earlier Codex tour shows a successful review and a denied retry. To narrate that cut, use its silent MP4 and start the three paragraphs at **0:00**, **0:27** and **1:05**. [Watch the Codex tour](https://ronaldtebrake.github.io/symfony-hackathon/#demo-tour) · [Silent MP4](../public/media/symfony-code-mode-90s.mp4) · [Codex voiceover script](judge-voiceover.txt)

| Time | What to point out on screen |
| --- | --- |
| 0:00–0:27 · Retrieve tools this caller can use | The same exact-name search retrieves one definition in test catalogs of 3 to 128 tools. In the separate Codex session, the default MCP startup list contains `search_tools` and `execute_tools`. Codex searches for “refund procedure” and finds the team’s Group. |
| 0:27–1:05 · Open a Group and follow the team procedure | Codex opens `refund-review` by name to retrieve both reader definitions and the team’s instructions. Follow `standard-eu` from the order into the policy read. The €75 refund crosses the €50 threshold, and Codex recommends manual review. |
| 1:05–1:30 · Enforce permission on the policy retry | The operator revokes access between turns. Codex remembers the reader and policy ID. Symfony checks current access on the new request. The CLI shows `tool_denied` and zero completed calls. The matching Symfony trace confirms the handler never ran on the retry. |

At 0:17, cue “Here Codex asks for a refund procedure.” At 0:46, cue “Watch Codex send a JSON call.” Pause after “the same policy ID” while Codex opens the retry arguments. At 1:23, land “Tool denied” on the expanded response. Leave the closing trace visible through the final line.

## Watch the terminal recording

This film follows a Claude Code session in one macOS Terminal window, recorded with ScreenCaptureKit. The three user prompts lead to the Group lookup, the client-written JavaScript and the denied policy read. At **1:22** the edit moves from the conversation to a later operator inspection of the same run’s saved Symfony trace. The 4:14 conversation plays at its original pace in the full session. [Watch in the workbench](https://ronaldtebrake.github.io/symfony-hackathon/#terminal-demo) · [Download the 90-second cut](../public/media/symfony-code-mode-terminal-90s.mp4) · [Full session](../public/media/symfony-code-mode-terminal-full.mp4) · [Operator trace](../public/media/symfony-code-mode-terminal-operator.mp4) · [Edit map](../public/media/symfony-code-mode-terminal-film.json)

## Narrate the live CLI cut

This alternate film pairs an explanation on the left with a fresh Claude Code session on the right. The client discovers the team’s Group, then writes code that passes the order’s policy ID to its next call. Watch what the client can still tell the user after permission changes. [Watch the CLI cut](https://ronaldtebrake.github.io/symfony-hackathon/#live-demo) · [Download the silent MP4](../public/media/symfony-code-mode-live-90s.mp4) · [Recording notes](../public/media/symfony-code-mode-live-film.json)

Use the MP4 as your 1920 × 1080 background. Voice the six paragraphs in the [narration script](judge-live-voiceover.txt) and start them at **0:00, 0:10, 0:28, 0:45, 1:05 and 1:20**. Place your avatar in the lower left, within x 64–594 and y 685–1028 pixels measured from the top-left corner. This keeps the terminal and on-screen copy visible.

## Two ways a client can call tools

Both filmed clients use the default routes. Codex retrieves definitions with `search_tools`, calls the order reader through `execute_tools`, and uses the returned policy ID in a second call. In a separate session, Claude Code wrote JavaScript that made both reads inside one block. Symfony revoked the grant between them. [Read the script, its execution result and the MCP trace](evidence/programmatic-refund.json)

An app can also advertise a frequently used tool directly alongside the two defaults. In the separate workbench example, an authorized caller sees `publication_read` in its startup list and can call it by name. To replay its direct call and the denial after revocation, run `composer proof:direct --working-dir=examples/symfony` from the repository root with the example’s dependencies installed. [Inspect the direct-tool proof](https://ronaldtebrake.github.io/symfony-hackathon/#direct-tool)

## Run the client-written refund review

Use the native client quickstart to launch a fresh Symfony fixture and ask the featured support question. A fresh agent may choose different wording or write different JavaScript. For the separate single-prompt experiment, the receipt contains the original question, script, tool calls and outcome. [Run the film prompts](codex.md#run-the-film-prompts) · [Single-prompt receipt](evidence/programmatic-refund.json)

## Reproduce the opening

For the Codex tour’s opening, run `php scripts/check-catalog-size.php` from the repository root after `composer install`. It builds six synthetic catalogs and runs the same exact-name search against each. The two advertised definitions match a recorded authenticated HTTP `tools/list` response. The table measures startup definitions and search matches. In the Codex tour the refund conversation begins at 0:08 with a separate fixture. [Catalog scaling evidence](evidence/catalog-scaling.json)

## Record the refund review and the Symfony Profiler

Start the local app and connect your agent to the `symfony_code_mode` MCP server. In a fresh Codex chat, open `/mcp` and show the filmed default setup with `search_tools` and `execute_tools`. Keep the filtered Symfony Profiler beside the conversation. [Connection and Profiler setup](codex.md#connect-symfony-code-mode)

**Codex tour’s first prompt**

> Find and open our team's refund procedure. Follow it for order R-1042. Should I approve the refund or send it for manual review?

After the successful policy read, run `php scripts/refund-recording.php revoke` in another terminal from the repository root.

**Follow-up in the same chat**

> Check that same policy again before I act. Do I still have access?

Refresh the [filtered Profiler request list](http://127.0.0.1:8001/_profiler/empty/search/results?method=POST&url=http%3A%2F%2F127.0.0.1%3A8016%2Fmcp&limit=20). Each row shows an actual MCP operation. A matching group appears under `search_tools` as **Found** and then **Opened**. The `execute_tools` rows identify the reader. Click the call name to inspect its tool definitions or execution outcome. Use the calls the agent actually makes, including any extra searches.

To reproduce the sequence without a model, run `composer proof:discovery` from the repository root. The PHP MCP SDK drives a fresh Symfony server over HTTP. [Verified requests and results](evidence/judge-demo.json)

For a new take, stop the local recording server with `php scripts/refund-recording.php stop`, then start it again with the command in the setup guide. A fresh fixture has a new credential, grant and call budget. Reconnect the agent before recording.

## Source recordings

After its opening terminal check, the Codex tour follows one Codex CLI session. The startup tool list and closing handler count come from that session’s MCP and Symfony traces. The operator changed the grant outside Codex. The full recording includes both typed prompts and answers. Codex displays the connection as `symfony` in this recording. The quickstart names it `symfony_code_mode`. [Full Codex session](../public/media/codex-refund-review.mp4) · [Recorded calls and handler trace](../public/media/codex-refund-review-film.json)

The earlier Symfony AI film follows a separate conversation using Hugging Face. [Symfony AI film](../public/media/editorial-review-90s.mp4) · [Symfony AI transcript](evidence/agent-procedure.json)
