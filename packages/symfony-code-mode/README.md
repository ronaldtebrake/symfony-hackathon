# Symfony Code Mode integration

Approve existing Symfony read services, then give an MCP client isolated PHP execution or bounded JSON calls. The generic runner and broker remain in `ronaldtebrake/php-code-mode`. This bundle supplies the Symfony container integration and a STDIO entry point.

For a consumer with its own dependencies, native `#[AsTool]` service and authorization policy, use the [separate Symfony app](../../examples/symfony/README.md). Its recorded allowed/denied proof runs through this bundle's normal MCP server without the main demo autoloader.

These packages are currently installed through this repository's Composer path repositories; they are not published to Packagist. To try the integration here:

```bash
composer install
php bin/console code-mode:doctor
php bin/console code-mode:serve --no-debug
```

The last command speaks MCP over STDIO; configure it in your agent rather than typing into its terminal. No HTTP execution endpoint is added. The audit server, `code-mode:mcp`, uses the same lean response format with its own tool catalog and limits.

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

## Compose tool providers

To combine approved Symfony AI tools and an MCP service, register providers explicitly:

```yaml
code_mode:
    identity_provider: App\CodeMode\DemoIdentityProvider
    providers: [app.catalog_provider, app.media_provider]
```

`SymfonyAiProvider` wraps an `ApprovedToolbox`; `McpProvider` wraps a host-configured `McpGateway`. Each takes a namespace and an explicit map from PHP method aliases to approved original tool names. `ReadToolsProvider` adapts existing `ReadToolInterface` services. The [independent composition app](../../examples/composition/README.md) includes complete service wiring and a real allowed/denied execution proof. It does not replace the main demo or its configuration.

Discovery still returns full schemas. Selected calls can use `$tools->catalog->getArticles(['ids' => [1]])` or `$tools->call('catalog.getArticles', ['ids' => [1]])`. Both reach the same broker. Host policy, input validation, the disclosed-tool allowlist and execution budgets are unchanged. The generated PHPDoc interfaces describe supported schema shapes; they do not prove program correctness or confer permission. Unknown/complex shapes stay broad.

The generic provider contract and MCP adapter live in the PHP core; the Symfony AI adapter stays in this bundle. Drupal/Rosetta integration can use that core without adopting this demo's UI or fixtures.

## Runtime configuration

Use the [runner setup](../php-code-mode/README.md#run-the-proof) on a dedicated Linux Docker endpoint with gVisor. On macOS a local Linux VM can supply that endpoint; ordinary Docker Desktop/OrbStack alone is insufficient. In ignored `.env.local`:

```dotenv
CODE_MODE_IMAGE=sha256:<64-character ID of your locally built runner>
CODE_MODE_DOCKER_COMMAND='["docker"]'
```

`code-mode:doctor --json` returns an exit status and checks PHP CLI, extensions, catalog, immutable image pin, runsc profile and image availability. It does not install software, invoke application tools, execute a guest or call a model. It does not prove the runtime is safe; run the actual sandbox suites too. It never prints credentials or Docker endpoint arguments.

Defaults: 5 seconds per execution, 1 second per tool, 128 MiB container memory, 10 broker calls, and **two attempts including failures**. Optional `wall_time_ms`, `tool_time_ms` and `max_tool_calls` settings remain bounded by the core package. The default `executor: gvisor` requires gVisor, disables guest networking, mounts no host files and passes no host credentials. It never falls back to local execution.

Each `SessionFactory::create($hostIdentity)` returns fresh conversation state. Keep that same session for follow-ups. Do not recreate it after a denial to reset its budget. `code-mode:serve` creates exactly one session per operator-started server process. STDIO access grants the configured local operator's capabilities; protect the host and command accordingly.

### JSON batches

Add `mode: batch` to the same approved-tool configuration to expose
`tools_search`, `tools_describe` and `tools_execute`. Alternatively, `mode: catalog`
exposes only `search_tools` and `execute_tools`: search returns full contracts,
so there is no separate describe step. Search includes schema fields and reports
truncation. Execution accepts one or more literal calls through the same broker.
The advertised pair remains fixed; discovery never grants application access.
The server will not expose
PHP execution, resolve runner configuration or require Docker/gVisor. The
existing providers, identity and per-call policies are reused.

The batch path requires Unix PHP CLI with `pcntl` and `posix`; it is not a
PHP-FPM adapter. Use `SessionFactory::createBatch($identity)` and
`CodeMode\Symfony\Ai\BatchToolbox` for a Symfony AI conversation. Ordered
results reach the agent without automatic filtering or joining. The default
`mode: php` keeps gVisor. No unsafe local PHP executor is available.
[Full batch contract, limits and hosted Symfony guidance](../../docs/batching.md).

## Agent responses and host evidence

Successful gVisor execution returns `{"value": ...}`. A failure returns `{"error":{"reason":"tool_denied","tool":"publishing_read"}}`; the tool name is omitted when no failed call is known. MCP also sets `isError`. Private exception messages and intermediate results are not included. Unknown executors retain their runtime warning on every execution response.

The underlying `ExecutionResult` and `SandboxException` keep their complete host ledgers. For Symfony AI, `CodeModeToolbox::lastExecution()` exposes the latest call's PHP and sanitized trace to trusted application code, outside `ToolResult`. It is cleared before each tool call. The workbench recorder uses this accessor.

For MCP recording, the operator can enable a separate file:

```sh
mkdir -p -m 700 var/code-mode-traces
php bin/console code-mode:serve --no-debug --trace-file=var/code-mode-traces/take-1.jsonl
```

The same option works on `code-mode:mcp` and `code-mode:performance-mcp`. Use a **new filename for each server process**. The sink creates files exclusively with mode 0600, refuses existing files/symlinks and caps each file at 2 MiB. If recording fails, the call reports a sanitized failure; it does not retry execution. Traces contain submitted PHP and returned application data: keep them private and review before sharing. No path or trace option is exposed to the agent, and traces are never added to MCP content, structured results or `_meta`. Fresh recordings are not automatically published in the workbench.

## Boundary recovery

[The recorded conversation](../../docs/demo.md#optional-boundary-recovery-opening) asks for article 49, receives `tool_denied`, then audits published articles after the user changes scope. The current server returns `isError: true` with a sanitized reason and affected tool; the original capture retains its older, detailed response. Private content and exception messages never become the explanation. The permitted response is a separate successful execution under the same identity and attempt budget.

For Symfony AI, the same `CodeModeSession` backs `CodeModeToolbox`, a `ToolboxInterface` implementation. Keep one session across user turns; see the [conversation recipe](../../docs/contributing/symfony-ai-recipe.md).

## Reuse existing Symfony AI tools

Install compatible `symfony/ai-agent` and `symfony/ai-platform` packages (`^1.0@beta`). Existing `#[AsTool]` handlers can participate without implementing `ReadToolInterface`:

```yaml
code_mode:
    identity_provider: App\CodeMode\DemoIdentityProvider
    ai_toolbox: app.publishing_toolbox
    ai_tools: [publishing_read, publishing_list]
    ai_authorization: App\CodeMode\PublishingAuthorization
```

The toolbox is an ordinary Symfony AI service. See the executable [tool services](../../src/CodeMode/PublishingTools.php), [authorization policy](../../src/CodeMode/PublishingAuthorization.php) and [demo wiring](../../config/packages/code_mode_adoption.yaml). The demo wires a separate session factory to keep the original MCP conversation unchanged; applications can use the minimal bundle configuration above. Existing `tools` services can also be combined with `ai_tools`.

Only named tools with an explicit read-only hint enter the catalog. Missing names, duplicates and absent policy configuration fail closed. The hint is a prerequisite, not proof that a handler is read-only: approve reviewed handlers. `ToolAuthorizationInterface::isGranted()` receives the host identity, tool name and arguments before schema validation and immediately before invocation. It must reject malformed or unauthorized requests. Handlers must retain resource/field checks at the actual read.

Calls go through the original Symfony AI toolbox, preserving its argument resolver and lifecycle events. Results must be safe JSON data. Optional tool metadata `code_mode.output_schema` supplies an output contract, validated before returning data to the sandbox. The publishing example declares object schemas. The installed Symfony AI version represents lifecycle denials as ordinary string results; an object schema rejects these with sanitized `tool_failed`. Do not infer denial by matching text, or use a toolbox that converts private exception diagnostics into public result strings. See the [upstream contribution note](../../docs/contributing/README.md).

`tools_describe` adds a bounded PHP invocation example and input/output shapes for only the selected tools. JSON objects are `stdClass`, lists are arrays, and unknown/referenced schemas remain `mixed`. These guides are documentation; the broker still checks schemas and authorization for every call.

## Full schema discovery

Search returns candidate names and descriptions in a `tools` list, with `matched`, `returned`, `limit` and `truncated`. A truncated or empty result includes refinement guidance. All query terms must match; an exact approved name selects one tool. Search returns at most eight entries and 32 KiB. It never makes a tool callable.

Call `tools_describe` for the selected tools before executing PHP. The response includes the original input/output schemas and a PHP invocation guide. The broker continues to validate arguments and results and authorize every call. Search and describe never reset execution attempts. The core's list-only `search()` API remains available for existing PHP callers.
