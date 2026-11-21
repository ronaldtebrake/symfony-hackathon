# Judge evidence

The earlier JavaScript proof film shows the original third refund review reopened in Claude Code. Two calls to `search_tools` found and opened the Group. One client-written JavaScript program then made two calls to `execute_tools`, passing the order’s `policyId` into the policy read. The record includes the script, the four MCP calls, the handler events and Claude’s answer. It also retains the two earlier attempts that used a regular expression. [All three attempts](claude-structured-refund.json) · [Film sources](../../public/media/symfony-code-mode-ready-film.json)

The previous single-script film used a regular expression to extract the policy ID. [Program, calls and answer](claude-plain-launch.json) · [Previous film sources](../../public/media/symfony-code-mode-plain-film.json)

The earlier cut includes another Claude session in which the order and policy reads ran in separate programs, as well as a PHP MCP client calling `groups/list` and `groups/get`. [SDK exchanges](refund-group-discovery.json) · [Earlier Claude sessions](claude-support-review.json) · [Earlier film and source notes](../../public/media/symfony-code-mode-support-film.json)

The earlier terminal explainer uses three staged prompts and revokes permission before the policy read. [Terminal footage and MCP calls](../../public/media/symfony-code-mode-terminal-film.json)

The [Codex tour manifest](../../public/media/symfony-code-mode-film.json) identifies the explanatory graphics and maps the terminal excerpts to the original Codex recording. The [recording and server receipts](../../public/media/codex-refund-review-film.json) show the initial two-tool listing, the procedure Codex opened and the order and policy reads. The successful policy call and the denied retry have identical arguments.

The [Symfony AI conversation](agent-procedure.json) is the source for the separate 90-second explainer. It contains both prompts, answers and tool calls, including the agent’s first attempt to execute a group and its correction. The [explainer manifest](../../public/media/editorial-review-film.json) maps that edit to its sources.

Claude Code also opened the refund Group and composed both reads in one JavaScript program. The policy read failed after the operator revoked access. The [client-side Code Mode trace](programmatic-refund.json) includes the generated program, the execution error and the Symfony calls.

For the no-model integration check, run `composer proof:discovery`. The [six-call MCP trace](judge-demo.json) shows the PHP MCP SDK opening the procedure, reading the order and its policy, then retrying after revocation. The denied read has zero handler events. A final search returns no readers or groups.

The independent Symfony example also exercises an opted-in direct reader and the default catalog route. Replay it with `composer proof:direct --working-dir=examples/symfony` after installing the example's dependencies. [Direct calls, revocation and shared budget](direct-tool.json)

The [earlier Symfony AI conversation](refund-skill.json) and [protocol check](refund-protocol.json) cover the linked guide experiment. The [catalog sizing measurement](catalog-scaling.json) counts the initial tool definitions in a separate offline test.
