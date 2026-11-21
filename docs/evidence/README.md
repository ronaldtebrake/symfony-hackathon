# Judge evidence

The narrated demo follows a permitted refund review and a denied read on a different order. [Watch the demo](https://ronaldtebrake.github.io/symfony-hackathon/#judge-demo) · [Film sources and narration cues](../../public/media/symfony-code-mode-judge-film.json)

In a separate run, Claude passed the order’s structured policy ID to a second reader in one JavaScript program. [All three attempts](claude-structured-refund.json) · [PHP SDK Groups exchanges](refund-group-discovery.json) · [Symfony AI conversation](agent-procedure.json)

Claude Code also opened the refund Group and composed both reads in one JavaScript program. The policy read failed after the operator revoked access. The [client-side Code Mode trace](programmatic-refund.json) includes the generated program, the execution error and the Symfony calls.

For the no-model integration check, run `composer proof:discovery`. The [six-call MCP trace](judge-demo.json) shows the PHP MCP SDK opening the procedure, reading the order and its policy, then retrying after revocation. The denied read has zero handler events. A final search returns no readers or groups.

The independent Symfony example also exercises an opted-in direct reader and the default catalog route. Replay it with `composer proof:direct --working-dir=examples/symfony` after installing the example's dependencies. [Direct calls, revocation and shared budget](direct-tool.json)

The [earlier Symfony AI conversation](refund-skill.json) and [protocol check](refund-protocol.json) cover the linked guide experiment. The [catalog sizing measurement](catalog-scaling.json) counts the initial tool definitions in a separate offline test.

To verify the saved working-group experiments locally, run `php scripts/check-refund-protocol.php --verify` and `php scripts/compare-group-selection.php --verify`. Both read the committed evidence without calling a model.
