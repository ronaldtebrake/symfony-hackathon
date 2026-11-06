# Symfony Code Mode integration

Approve existing Symfony read services, then give an MCP client three tools: discover, describe, execute PHP. The generic runner and broker remain in `ronaldtebrake/php-code-mode`. This bundle supplies the Symfony container integration and a STDIO entry point.

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

## Runtime configuration

Use the [runner setup](../php-code-mode/README.md#run-the-proof) on a dedicated Linux Docker endpoint with gVisor. On macOS a local Linux VM can supply that endpoint; ordinary Docker Desktop/OrbStack alone is insufficient. In ignored `.env.local`:

```dotenv
CODE_MODE_IMAGE=sha256:<64-character ID of your locally built runner>
CODE_MODE_DOCKER_COMMAND='["docker"]'
```

`code-mode:doctor --json` returns an exit status and checks PHP CLI, extensions, catalog, immutable image pin, runsc profile and image availability. It does not install software, invoke application tools, execute a guest or call a model. It does not prove the runtime is safe; run the actual sandbox suites too. It never prints credentials or Docker endpoint arguments.

Defaults: 5 seconds per execution, 1 second per tool, 128 MiB container memory, 10 broker calls, and **two attempts including failures**. Optional `wall_time_ms`, `tool_time_ms` and `max_tool_calls` settings remain bounded by the core package. The default `executor: gvisor` requires gVisor, disables guest networking, mounts no host files and passes no host credentials. It never falls back to local execution.

Each `SessionFactory::create($hostIdentity)` returns fresh conversation state. Keep that same session for follow-ups. Do not recreate it after a denial to reset its budget. `code-mode:serve` creates exactly one session per operator-started server process. STDIO access grants the configured local operator's capabilities; protect the host and command accordingly.

### PHP-only development opt-in

For reviewed snippets in a disposable environment, add this alongside the approved tools and identity provider:

```yaml
# config/packages/code_mode.yaml
when@dev:
    code_mode:
        executor: local-unsafe
```

Run `php bin/console code-mode:doctor --env=dev`, then connect your agent to `php bin/console code-mode:serve --env=dev --no-debug` over STDIO. PHP 8.2+ CLI, Composer dependencies, `pcntl` and `posix` on Unix are required; Docker, gVisor, a runner image and a web server are not. Generated code uses the host PHP version. Remove the override to return to gVisor; restart the MCP process after configuration changes.

**This is unsandboxed local execution.** PHP runs as your OS user and can access host files and network services. Broker policies still reject unauthorized calls, but cannot prevent direct access outside the broker. Use the isolated executor for untrusted agent-generated code. Timeouts, output bounds, a 32 MiB PHP heap limit and process-group cleanup are best effort; there is no OS memory, CPU, process-count or disk quota. [Exact limitations](../php-code-mode/docs/security.md#explicit-local-development-profile).

The doctor prints a warning. MCP/Symfony AI results and failures carry host-supplied `runtime` metadata with `executor: local-unsafe`, `isolation: none` and the label **Unsandboxed local execution**. Its `php_execute` tool is not advertised as read-only. The only execution argument remains `code`; profile selection, identities and limits are operator configuration. These traces are not tamper-proof audit records on a host running unsandboxed code.

This switch applies to the bundle's `code-mode:serve` and its session factory. The hackathon's audit, performance, evaluation and security-proof commands keep their explicit gVisor runners. Recorded workbench evidence is unchanged and does not become evidence for local mode.

## Agent responses and host evidence

Successful gVisor execution returns `{"value": ...}`. A failure returns `{"error":{"reason":"tool_denied","tool":"publishing_read"}}`; the tool name is omitted when no failed call is known. MCP also sets `isError`. Private exception messages and intermediate results are not included. Local unsafe and unknown executors retain their runtime warnings on every execution response.

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

## Compact discovery

Set `code_mode.discovery: compact` alongside the approved toolbox and authorization policy. `full` is the default. Alternatively, the operator can launch `code-mode:serve --discovery=compact --no-debug`; the agent cannot change this setting through a tool argument. Restart the MCP process after configuration changes.

Search results include the original adapted Symfony AI `inputSchema` and a bounded `returns` PHP shape. `requiresDescribe: false` means the contract was disclosed and the tool may be called; `true` requires `tools_describe`. The exact input constraints remain visible. Output shapes are documentation only; the original output schema still validates results. Missing output schemas are explicitly marked `mixed (no output schema declared)`.

The core formatter falls back for references, deep/wide output shapes, unsupported output compositions and entries larger than 4 KiB. Search retains its eight-result limit and a 32 KiB response cap. Contracts become callable only after the whole response is constructed. These discovery decisions do not change allowlists, identities, policies, broker schema validation, execution attempts or isolation.

For a Symfony AI conversation, configure the session factory or pass the host-owned override `$sessions->create($identity, \CodeMode\DiscoveryMode::Compact)`. Keep that same session for follow-ups. Both the MCP server and `CodeModeToolbox` expose the appropriate discovery instructions.

[The paired comparison](../../docs/demo.md#compact-discovery-comparison) reuses native `#[AsTool]` schemas, Symfony's toolbox lifecycle and the same gVisor package.

## Run the adoption proof

```sh
# Real gVisor execution, no provider:
php bin/console code-mode:adoption-proof --no-debug
# Also capture two real Symfony AI + Hugging Face turns; consumes inference credits:
php bin/console code-mode:adoption-proof --live --no-debug
```

The read-only [Adoption viewer](http://127.0.0.1:8000/demo#adoption-proof) shows the actual generated PHP, follow-up, tool calls, usage and PHP discovery guides. The [reviewed evidence](../../docs/evidence/adoption-proof.json) includes the first live attempt, which filtered the injected paragraph before it reached the model, and the next attempt, which requested the full body and passed the exposure check. A separate fixed probe attempts the restricted read, recovers under the same identity, and proves the two-attempt budget cannot be reset. This is a specific execution proof, not general prompt-injection protection.
