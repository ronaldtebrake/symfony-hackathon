# Symfony Code Mode integration

Approve existing Symfony read services, then give an MCP client three tools: discover, describe, execute PHP. The generic runner and broker remain in `ronaldtebrake/php-code-mode`. This bundle supplies the Symfony container integration and a STDIO entry point.

These packages are currently installed through this repository's Composer path repositories; they are not published to Packagist. To try the integration here:

```bash
composer install
php bin/console code-mode:doctor
php bin/console code-mode:serve --no-debug
```

The last command speaks MCP over STDIO; configure it in your agent rather than typing into its terminal. No HTTP execution endpoint is added. The existing audit server, `code-mode:mcp`, remains available unchanged.

## Four lines to approve your tools

Register `CodeMode\Symfony\CodeModeBundle::class` in `config/bundles.php`, then:

```yaml
# config/packages/code_mode.yaml
code_mode:
    identity_provider: App\CodeMode\DemoIdentityProvider
    tools:
        - App\CodeMode\ReadArticle
        - App\CodeMode\ListPublishedArticles
```

Normal Symfony autowiring supplies those services. Only the listed services enter the catalog; implementing an interface does not grant approval. Start from the [read service](../../src/CodeMode/ReadArticle.php) and [identity provider](../../src/CodeMode/DemoIdentityProvider.php). Both are small, executable examples, using a synthetic publishing dataset.

A `ReadToolInterface` service supplies:

- `definition()`: one bounded `ToolDefinition`, including its input schema.
- `authorize(identity, arguments)`: a host-side capability and resource check, performed on every call **before invocation**. This runs before schema validation, so malformed arguments must fail closed.
- `invoke(arguments, identity)`: JSON data, with mutable resource/field permissions rechecked at the read. Open database/network connections inside the supervised worker; do not reuse a connection inherited across its fork.

The identity provider is trusted application code. Our example selects a local CLI demonstration account, which is **not remote authentication**. A production host must authenticate its operator/user and supply the appropriate capabilities. Never take the identity, permissions, service IDs or Docker command from agent input. The read-only interface/annotations describe a trusted operator contract; they cannot make an arbitrary PHP handler read-only. Review approved handlers.

## Runtime configuration

Use the [runner setup](../php-code-mode/README.md#run-the-proof) on a dedicated Linux Docker endpoint with gVisor. On macOS a local Linux VM can supply that endpoint; ordinary Docker Desktop/OrbStack alone is insufficient. In ignored `.env.local`:

```dotenv
CODE_MODE_IMAGE=sha256:<64-character ID of your locally built runner>
CODE_MODE_DOCKER_COMMAND='["docker"]'
```

`code-mode:doctor --json` returns an exit status and checks PHP CLI, extensions, catalog, immutable image pin, runsc profile and image availability. It does not install software, invoke application tools, execute a guest or call a model. It does not prove the runtime is safe; run the actual sandbox suites too. It never prints credentials or Docker endpoint arguments.

Defaults: 5 seconds per execution, 1 second per tool, 128 MiB container memory, 10 broker calls, and **two attempts including failures**. Optional `wall_time_ms`, `tool_time_ms` and `max_tool_calls` settings remain bounded by the core package. The runner always requires gVisor, disables guest networking, mounts no host files and passes no host credentials.

Each `SessionFactory::create($hostIdentity)` returns fresh conversation state. Keep that same session for follow-ups. Do not recreate it after a denial to reset its budget. `code-mode:serve` creates exactly one session per operator-started server process. STDIO access grants the configured local operator's capabilities; protect the host and command accordingly.

## Boundary recovery

[The recorded conversation](../../docs/boundary-recovery.md) asks for article 49, receives `tool_denied`, then audits published articles after the user changes scope. The server returns `isError: true` with a sanitized reason, host ledger and run ID. Private content and exception messages never become the explanation. The permitted response is a separate successful execution under the same identity and attempt budget.

For Symfony AI, the same `CodeModeSession` can back `ToolboxInterface`; see the [tested contribution example](../../docs/contributing/README.md). No Hugging Face request has been made as part of this integration proof.
