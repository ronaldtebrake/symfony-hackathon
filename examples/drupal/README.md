# Drupal authorization proof

A separate DDEV/Composer project for **Tool API → MCP Server Tool Bridge → MCP Server → Code Mode's broker**. The existing gVisor runner executes real PHP. The Symfony application, its dependencies, Codex connection and workbench reports stay separate.

This first milestone uses **local STDIO** under a fixed Drupal account. The trusted operator launches Drush; the account argument selects whose permissions Drupal evaluates. It is **not remote client authentication**. No OAuth implementation or Drupal HTTP integration is claimed. The proof does not need a model, Hugging Face token or Drupal bearer token.

## Setup

Requires DDEV, Docker, and the root project's [configured gVisor runtime](../../packages/php-code-mode/README.md#run-the-proof). DDEV runs Drupal; the separate gVisor engine runs untrusted PHP. DDEV alone is not the sandbox.

From the repository root:

```bash
composer install
bash examples/drupal/scripts/setup.sh
php examples/drupal/proof.php
```

The setup installs the committed Composer lockfile, creates Drupal only if the dedicated database has **no tables**, enables the custom read tool and seeds two authorization nodes. It also configures the journal described below. Re-running it preserves existing node content, permissions and accounts. The proof fails if their access policy has drifted. It never reinstalls a populated database.

The local Drupal site is [symfony-code-mode-drupal.ddev.site](https://symfony-code-mode-drupal.ddev.site). Run `ddev drush uli` from this directory if you need local administrator access. Administrator and reader passwords are random and uncommitted. The MCP process always runs as `code_mode_reader`, never as that administrator. No role is granted `access mcp server`, so the HTTP MCP endpoint remains inaccessible to ordinary users.

## Demo journal

The public homepage uses Drupal core's **Olivero** theme with the Symfony AI purple accent; administration uses **Claro**. The journal has **12 published articles and three unpublished drafts**, in addition to the two authorization fixtures. Each article has a body, summary, topic and a readable URL. Four published articles deliberately have an empty `field_meta_description`, ready for a later metadata-audit tool. All content is synthetic demo material.

The journal's two listing pages use Drupal's published-content query and entity access checks. The original authorization nodes are kept out of that listing. The “Symfony proof” navigation link returns to the existing Symfony workbench; Drupal is a sample content source for the reusable package.

[`content/journal.json`](content/journal.json) defines the seed content. [`scripts/present.php`](scripts/present.php) adds missing articles by deterministic UUID and applies the theme, field displays, navigation and journal view. Rerunning it reapplies presentation configuration but preserves edits to existing articles and their publication status. The current MCP read tool still returns only ID/title; it does not yet expose the new body, topic or description fields.

## What must pass

| Probe | Required evidence |
|---|---|
| Published article | Exact ID/title returned, one successful broker call, Drupal access allowed and one read event |
| Unpublished article owned by someone else | No returned content, broker failure, Drupal access denied and no read event |
| Both | Exact sandbox container removed; node data unchanged; reader permissions still restricted |

Both PHP bodies call the **same locally approved tool**, `tool_api__code_mode_read_article`, with different node IDs. Drupal's Tool API permission check, node `view` access and title-field access decide what can be read. The guest cannot choose the Drupal account, run Drush, connect to the database or access DDEV's Docker socket.

The proof uses two executions, a 15-second wall limit per execution, a 3-second broker-call limit, 128 MiB guest memory and one tool call per execution. The tool returns only scalar ID/title fields and checks access again before reading. Unknown arguments are rejected by the broker's discovered JSON Schema. Operational access logs contain IDs and decisions, never restricted titles.

The installed Tool Bridge beta5 returns successful outputs in `structuredContent`, and access denials as MCP `isError`. The generic gateway deliberately reports the latter as `tool_failed` / `incomplete`; it does not infer an authorization reason from upstream prose. **An upstream failure alone cannot pass this proof.** A separate, trusted Drush snapshot checks the real node, account and Drupal audit events around each execution. Those inspection scripts are never exposed as tools.

## Reuse from Symfony Code Mode

Lean responses now work here through the same PHP core package, with no Drupal module changes. The fresh proof checks the agent response as well as Drupal access, unchanged node data, the fixed reader account and gVisor cleanup. Schema discovery and bounded PHP guides come from `CodeModeSession`; local host traces remain available.

New proof runs use search followed by full describe. Drupal enforces node and field access on every read; a discovery result cannot grant permission. The committed report remains an unchanged historical execution capture.


The Symfony bundle's DI configuration and `#[AsTool]` adapter are Symfony integration points. Drupal continues to use Tool API and its MCP bridge. The new PHP-only development executor could technically be wired in, but this proof keeps gVisor: unsandboxed PHP could access local services outside Drupal's tool permissions. A real Drupal agent conversation and paginated journal audit remain separate milestones.

## Inspect and repeat

[`proof.php`](proof.php) contains the exact operator-written PHP bodies. It prints JSON, exits nonzero on failure and replaces the single ignored `var/latest-proof.json`. The [recorded successful run](../../docs/evidence/drupal-authorization-proof.json) includes programs, discovered schemas, host call ledgers, Drupal audit events, versions, limits and cleanup checks. The latest capture uses the shared `AgentResponse` formatter: `agentResponse` contains only the value or a sanitized reason/tool, while the host retains `toolCalls`, run IDs and independent Drupal events. No model generated this proof.

```bash
# From this Drupal directory:
ddev drush tool:info code_mode_read_article --format=json
ddev composer validate --strict
ddev composer audit

# From the repository root:
php bin/phpunit --filter DrupalProofVerifierTest
php examples/drupal/proof.php
```

Only one compact, reviewed proof snapshot is checked in. Dependencies, Drupal settings, generated files, database data and repeat-run reports are ignored. DDEV manages its own persistent database volume. To stop only Drupal, run `ddev stop` from this directory; Symfony remains available at port 8000.

The locked Tool API/MCP modules are prereleases. This is a local development proof, not an audited public execution service. A real Drupal Codex conversation, HTTP authentication, editorial batching and a Drupal workbench view are later milestones. `--backend=drupal` in the Symfony app still refers to its separately configured HTTP adapter, not this STDIO proof.

Upstream references: [Tool API](https://www.drupal.org/project/tool), [Tool Bridge](https://www.drupal.org/project/mcp_server_tool_bridge), [MCP Server](https://www.drupal.org/project/mcp_server), [DDEV Drupal setup](https://docs.ddev.com/en/stable/users/quickstart/#drupal).
