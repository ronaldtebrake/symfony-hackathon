# What a skill added to a Symfony article lookup

The Symfony app serves a 732-byte article lookup recipe over MCP. It uses the caller’s catalog visibility to decide which files the PHP SDK registers on this request. A reader can discover the recipe, inspect its manifest and read the Markdown. Someone who knows the URI still needs permission to fetch it. [Reviewed skill](../../examples/symfony/skills/read-published-article/SKILL.md) · [Symfony integration](../../examples/symfony/src/SkillPresentation.php)

This article lookup tested skill delivery on its own. The later refund study tested how a host can open one group to find both a reviewed skill and its two readers. It also compared linking the skill with putting the same text directly in the group’s `instructions` field. [Refund study and results](../reusable-workflows.md#give-the-group-a-procedure-as-well-as-tools)

## What the agent actually did

We asked Symfony AI for the title of article **317**. Each pair used `openai/gpt-oss-120b` through one Hugging Face provider. Each arm started with an empty conversation and a fresh Symfony app, identical data, permissions, two execution attempts and the same prompt. Only the second host loaded the skill. Its worked example uses article **101**. The expected title of 317 was absent from the prompt and the skill.

| Provider and path | Host MCP requests | Agent MCP calls | Model requests | Provider tokens | Observed result |
| --- | ---: | ---: | ---: | ---: | --- |
| DeepInfra · search | 2 | 0 | 1 | 283 | No lookup or final text |
| DeepInfra · skill | 5 | 2 | 3 | 1,827 | Read the right article. Empty final answer. |
| Novita · search | 2 | 2 | 3 | 1,363 | Read the right article and answered with the right words. Four nonbreaking spaces failed the original exact-title check. |
| Novita · skill | 5 | 2 | 3 | 1,874 | Read the right article and answered **A reading room above the station**, exactly as stored. |

Both Novita answers would satisfy an editor reading them. Loading the skill added 511 provider tokens and three host MCP requests in this pair. Two pairs with a fixed order and one question cannot establish a general quality, speed or cost advantage. The original exact-match scores and all four raw answers are retained. [Full provider and MCP traces](../evidence/skills-agent.json)

The host chose the skill, checked its origin-bound URI, manifest, frontmatter, size and pinned digest, and supplied the reviewed text as a separate reference message. The agent chose its own searches and tool arguments. Symfony AI supplies the Agent, input processor and toolbox. The host-side loader is application code against a pinned SDK draft. [Loader and scoring](../../src/Demo/Skills/AgentStudy.php)

## What the server enforced

We interleaved two authenticated users on a single HTTP endpoint. The viewer’s lists were empty, and direct reads of the reader’s known URI had the same error shape as an absent URI. After the reader lost access, both lists emptied for that user too. The same tool call that previously returned article 317 now returned `tool_denied`, with zero operations completed. A downloaded copy of the recipe can survive revocation. The server still controls the next read. [31 HTTP requests and responses](../evidence/skills-http.json)

The experiment rebuilt the PHP SDK’s file registry for the current requester, sent private, non-storable HTTP responses, and used private zero-TTL MCP results for listings and reads. Eight further requests in one booted Symfony kernel switched reader → viewer → reader → revoked reader, with skill counts of 1 → 0 → 1 → 0. This gives the SDK and MCP working group a specific integration test for per-user discovery, direct reads and registry reuse. [Single-kernel capture](../evidence/skills-kernel-reuse.json) · [PHP SDK draft](https://github.com/modelcontextprotocol/php-sdk/pull/372)

## What this offers the two working groups

SEP-2640 reached Final status. The published extension specification is beta. It treats a skill’s identity as its originating server and URI, and gives the host a way to confirm a reference with `skills/get` before loading the resource. The Groups draft proposes listing the skill URI with other resources in `groups/get`. The positioning discussion compares possible relationships between the two extensions. [SEP-2640](https://modelcontextprotocol.io/seps/2640-skills-extension) · [Skills beta](https://skills.extensions.modelcontextprotocol.io/specification/beta/skills) · [Groups and Skills discussion #22](https://github.com/modelcontextprotocol/progressive-disclosure-wg/pull/22)

The refund fixture supplies a concrete interoperability test. A group links a skill resource that stays out of the top-level resource list. The caller can follow the link while authorized. If an operator revokes access after the group opens but before the first download, the manifest and Markdown reads both fail. The article fixture separately checks two users who know the same URI. [Refund controls](../evidence/refund-protocol.json) · [Two-user article fixture](../evidence/skills-http.json) · [Groups draft and review](https://github.com/modelcontextprotocol/progressive-disclosure-wg/pull/19)

## Reproduce

Install dependencies in the repository root and in `examples/symfony`. For the optional experiment, prepare the pinned draft in a separate checkout. The regular demo continues to use its Composer lockfile.

```sh
git clone --no-checkout https://github.com/modelcontextprotocol/php-sdk.git /tmp/php-sdk-skills
git -C /tmp/php-sdk-skills fetch origin 1ef0205d9b9daf93c44eea437b58da38a6b84883
git -C /tmp/php-sdk-skills checkout --detach 1ef0205d9b9daf93c44eea437b58da38a6b84883
composer install --working-dir=/tmp/php-sdk-skills --no-dev --no-scripts --no-plugins
php scripts/check-skill-http.php /tmp/php-sdk-skills
php scripts/check-skill-kernel.php /tmp/php-sdk-skills
```

The two checks above use zero model requests. To repeat a live pair, configure `HUGGINGFACE_API_KEY` locally and choose a provider below. Each command sends at most eight model requests and saves a fresh capture under `var/demo/skills-agent/`.

```sh
php scripts/compare-article-skill.php --live /tmp/php-sdk-skills deepinfra
php scripts/compare-article-skill.php --live /tmp/php-sdk-skills novita
```

The earlier four-request stdio check verified file delivery and its digest. The separate hint pilot measured a one-sentence hint without loading a skill. [Initial stdio capture](../evidence/skill-interop.json) · [Hint pilot](../evidence/permission-hints/README.md) · [Design journey and next experiments](../reusable-workflows.md)
