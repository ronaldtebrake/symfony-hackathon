# Demo for the judges

## Record the refund review and the Symfony Profiler

Start the local app and connect your agent to the `symfony_code_mode` MCP server. In a fresh Codex chat, open `/mcp` and show its two tools, `search_tools` and `execute_tools`. Keep the filtered Symfony Profiler beside the conversation. [Connection and Profiler setup](codex.md#connect-symfony-code-mode)

| Time | What to show |
| --- | --- |
| 0–15 s | Open the server’s tool list. The agent can start with two tools even as the application catalog grows. |
| 15–45 s | Send the first prompt below. Expand the search that finds `refund-review`, then the search that opens it. The result contains the team’s procedure and the two reader definitions. |
| 45–65 s | Show the order read and the policy read. The agent passes the policy ID from the first result into the second call. The €75 order exceeds the €50 threshold for automatic approval, so the recommendation is manual review. |
| 65–90 s | Revoke the grant and ask for a fresh policy check. Open the failed `execute_tools` request in the Profiler. Symfony records `tool_denied` before the policy handler runs. |

**First prompt**

> Use Symfony Code Mode to find and open our team’s refund review procedure. Follow it for R-1042. Should I approve the refund or send it for manual review, and why? Leave the refund unchanged.

In another terminal, from the repository root, run `php scripts/refund-recording.php revoke`.

**Follow-up in the same chat**

> Can you check the same policy again through Symfony Code Mode before I act? Tell me whether you could verify it this time.

Refresh the [filtered Profiler request list](http://127.0.0.1:8001/_profiler/empty/search/results?method=POST&url=http%3A%2F%2F127.0.0.1%3A8016%2Fmcp&limit=20). Each row shows an actual MCP operation. A matching group appears under `search_tools` as **Found** and then **Opened**. The `execute_tools` rows identify the reader. Click the call name to inspect its tool definitions or execution outcome. Use the calls the agent actually makes, including any extra searches.

To reproduce the sequence without a model, run `composer proof:discovery` from the repository root. The PHP MCP SDK drives a fresh Symfony server over HTTP. [Verified requests and results](evidence/judge-demo.json)

For a new take, stop the local recording server with `php scripts/refund-recording.php stop`, then start it again with the command in the setup guide. A fresh fixture has a new credential, grant and call budget. Reconnect the agent before recording.

## What the published film shows

The existing silent film combines real agent footage of a catalog lookup with a separate recorded Symfony AI and Hugging Face refund review. In that recording, the host opened the group and the model chose the order and policy reads. The live walkthrough above lets the agent find the group through the two-tool catalog. [Watch the 90-second film](https://ronaldtebrake.github.io/symfony-hackathon/#demo-tour) · [Refund calls and answers](evidence/refund-skill.json)
