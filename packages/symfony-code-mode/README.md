# Symfony Code Mode integration

Expose a searchable catalog from an ordinary Symfony HTTP endpoint. By default, `search_tools` returns the tool contracts visible to the authenticated user and `execute_tools` calls the selected handlers. You can also advertise a frequently used reader directly. Symfony AI supplies its definition and handler for both routes.

To try the live demo, [connect your agent to Symfony Code Mode](../../docs/codex.md#connect-symfony-code-mode). To integrate the package into an application, see the [independent Symfony example](../../examples/symfony/README.md) and the configuration below. These packages install from this repository using Composer path repositories.

## Enable HTTP

Register `CodeMode\Symfony\CodeModeBundle`, `Symfony\Bundle\SecurityBundle\SecurityBundle`, `Symfony\AI\AiBundle\AiBundle` and `Symfony\AI\McpBundle\McpBundle`. The native tool integration uses `symfony/ai-agent` and `symfony/ai-bundle` (`^1.0@beta`). Install a PSR-7/17 implementation such as `nyholm/psr7`, and reuse your application's Symfony Security authenticator for `/mcp`.

```yaml
# config/packages/code_mode.yaml
code_mode:
    mode: catalog
    http:
        enabled: true
        permissions:
            publication.read_published: ROLE_READER
    ai_tool_services: [App\ArticleTool]
    ai_tools: [publication_read]
    voter_rules:
        publication_read:
            discover: PUBLICATION_DISCOVER
            execute: PUBLICATION_READ
            subject: id

# config/packages/mcp.yaml
mcp:
    servers:
        catalog:
            name: 'Approved application tools'
            transports: { http: true }
            http: { path: /mcp }
            registry: [CodeMode\Symfony\Http\CatalogTools]
```

Import the MCP route loader (`resource: .`, `type: mcp`) in `config/routes.yaml`, and require a fully authenticated user on `/mcp` in `security.yaml`. The [example's configuration](../../examples/symfony/config/services.php) includes a local bearer-token authenticator. Use your application's existing authentication and HTTPS outside loopback.

`search_tools` returns full approved contracts; `execute_tools` accepts literal JSON calls. Only the HTTP adapter reads Symfony's current security token. Agent arguments cannot select identity, permissions or server configuration. `code-mode:doctor` checks the selected mode's prerequisites without invoking handlers or calling a model.

### Advertise a frequently used tool

If most conversations need the same reader, add its approved catalog name to the existing HTTP configuration:

```yaml
code_mode:
    http:
        enabled: true
        direct_tools: [publication_read]
```

An authorized client's MCP `tools/list` now includes `publication_read` alongside `search_tools` and `execute_tools`. It can call the reader immediately with `{"id":101}` or find it through search. Keep the application handler registered once, through the catalog. Both routes use the same input schema, current permission checks and per-user execution budget. Revoking access takes effect even if a client remembers the tool name.

With the independent example's dependencies installed, run `composer proof:direct --working-dir=examples/symfony` from the repository root. [Mixed-route HTTP proof](../../docs/evidence/direct-tool.json)

### Reusing Symfony AI search

`SessionFactory` uses `SymfonyCatalogSearch` for full-contract JSON discovery when Symfony AI is installed. It adapts the proposed `ToolSearchInterface` and defaults to BM25, which searches names, descriptions and input schema fields and enum values. No search server, embedding model or additional Composer dependency is required.

The package includes [three attributed, MIT-licensed search classes at a pinned revision](src/Ai/Search/Upstream/README.md), with only their namespace changed. A compatible installed `Symfony\AI\Agent\Toolbox\ToolSearch\Bm25ToolSearch` takes precedence. This does not install the WIP agent loop or native-provider integration.

Visibility is applied before ranking; the ranker receives detached schemas and no executable toolbox. Exact names, empty-query browsing, eight-result truncation and the existing disclosure budget remain owned by the catalog. Execution still checks discovery, current authorization, argument/result schemas and persistent budgets. The PHP discovery protocol is unchanged.

For a custom upstream strategy, pass its first-class `search(...)` callable to `SymfonyCatalogSearch`, then supply that adapter through the optional `SessionFactory` constructor argument `catalogSearch`. The standalone core uses its existing weighted search when Symfony AI is absent.

## Native Symfony development

### Scaffold an approved tool

Enable MakerBundle in development, then run these commands in your HTTP application:

```sh
composer require --dev symfony/maker-bundle
php bin/console make:code-mode-tool InventoryLookup
```

The command creates a native `#[AsTool]` service with `#[IsGrantedTool]`, a Symfony voter, an explicit `ai_tool_services`/`ai_tools` registration, and a PHPUnit test proving the initial voter denies access. Implement the read, its output schema, capability access and resource/field checks, then replace the scaffold assertions with allowed, denied and revoked cases. Until then the tool is hidden and denied, including for an administrator role. It does not modify authentication, existing policies or call budgets, and refuses to overwrite files.

For a new installation, enable `code_mode.http` and secure `/mcp` first. An empty catalog can boot for scaffolding; doctor and execution remain unavailable until an approved tool exists. Existing custom toolboxes require manual registration or migration to `ai_tool_services`. Existing tools need their own `voter_rules` or explicit visibility policy before generation, so the maker cannot silently change discovery for them.

### Share application voters

`voter_rules` maps each approved name to two decisions: `discover` checks the capability without a resource; `execute` passes the named JSON argument as the voter subject. Missing rules, a missing argument, unauthenticated access or a mismatch with the current Security user deny access. Voters must validate their subject. Full broker schema validation still applies.

The [example's ArticleVoter](../../examples/symfony/src/ArticleVoter.php) serves both the normal `GET /articles/{id}` controller and the agent tool. `#[IsGrantedTool(ArticleVoter::READ, subject: 'id')]` also checks native Symfony AI invocation. The broker checks visibility and authorization on every call; the native attribute is an additional guard. Neither discovery nor an earlier successful read grants future access. Existing `ai_authorization` and `visibility_policy` services remain authoritative when configured explicitly; voter rules never replace them.

`ai_tool_services` builds a native Symfony AI toolbox from explicitly listed service IDs and uses the application's event dispatcher. `ai_tools` is still the name allowlist, and read-only hints are still required. An existing `ai_toolbox` remains supported instead; do not configure both.

### Inspect requests in the profiler

Install and enable WebProfilerBundle and TwigBundle only in development/test. With Symfony's profiler enabled, opt in:

```yaml
when@dev:
    code_mode:
        profiling: true
```

Open the request's **Code Mode** panel via its `X-Debug-Token-Link` header or `/_profiler`. It shows discovery counts, persisted attempt/call reservations, broker outcomes, elapsed time and native Symfony AI requested/succeeded/failed events. A requested event alone does not establish that the handler ran. Broker denials appear without a native invocation. The collector is bounded and resets between requests; it stores no queries, arguments, returned records, user identifiers or exception messages. Agent responses stay unchanged.

The standard **AI** panel also works through Symfony AI's `TraceableToolbox`. It may contain tool arguments and results, as other Symfony profiler panels may contain request/authentication data: keep the entire profiler private and disabled in production. These local tool requests do not measure the external MCP client's model tokens. [Integration tests](../../examples/symfony/tests/NativeIntegrationTest.php) use Symfony AI's own `AiAssertionsTrait` to check tool invocation and verify that denied calls never reach the native toolbox.

## HTTP state and limits

Attempts, discovered contracts and submitted-call counts are stored per authenticated user. Reservations are persisted **before** dispatch and guarded by a lock. Failures consume attempts; calls after the first failure are not executed, but their reserved capacity is not refunded. A new request or MCP session cannot mint a fresh budget. The default is two attempts, at most ten submitted calls per attempt and twenty across the user budget.

The default filesystem state lives under `var/code-mode`, outside the container cache, with no automatic expiry. Preserve it across deploys. The operator can run `code-mode:reset-budget USER`; no agent-facing reset tool exists. This demonstration budget is deliberately small. Plan operator-controlled quotas/reset policy for your application rather than treating reconnects as new authorization.

The default store and lock support one host with multiple PHP request workers. For multiple hosts, replace `code_mode.http.cache` and `code_mode.http.lock_factory` with a shared persistent store and compatible shared lock. All hosts must use the same identity namespace and budget state. Cache deletion is a budget reset, not routine cleanup.

JSON handlers execute **in process**. Time limits are checked between calls and after each handler returns; a late result is rejected. A blocked handler cannot be interrupted by this package. Configure hard request and memory limits in your hosting, plus database and outbound HTTP timeouts. There is no worker option or per-tool heap isolation. Approved handlers are trusted application code.

## Approve tools and select the catalog

Register `CodeMode\Symfony\CodeModeBundle::class` in `config/bundles.php`, then:

```yaml
# config/packages/code_mode.yaml
code_mode:
    mode: catalog
    identity_provider: App\CodeMode\OperatorIdentityProvider
    tools:
        - App\CodeMode\ReadArticle
        - App\CodeMode\ListPublishedArticles
```

The catalog advertises `search_tools` and `execute_tools`. Set `mode: php` and configure gVisor to use generated PHP instead. Normal Symfony autowiring supplies those services. Only the listed services enter the catalog; implementing an interface does not grant approval. Implement [ReadToolInterface](src/ReadToolInterface.php) for each reader. For local STDIO, the operator supplies a trusted [IdentityProviderInterface](src/IdentityProviderInterface.php).

A `ReadToolInterface` service supplies:

- `definition()`: one bounded `ToolDefinition`, including its input schema.
- `authorize(identity, arguments)`: a host-side capability and resource check, performed on every call **before invocation**. This runs before schema validation, so malformed arguments must fail closed.
- `invoke(arguments, identity)`: JSON data, with mutable resource/field permissions rechecked at the read. Normal injected database and HTTP clients can be reused in the JSON request. Keep resource and field access checks at the read boundary.

The identity provider is trusted application code. A local CLI account identifies the operator. HTTP requests use the caller authenticated by Symfony Security. A production host must authenticate its operator/user and supply the appropriate capabilities. Never take the identity, permissions, service IDs or Docker command from agent input. The read-only interface/annotations describe a trusted operator contract; they cannot make an arbitrary PHP handler read-only. Review approved handlers.

## Select fields from list readers

When a Symfony AI list reader returns wide rows, configure its provider to accept an optional `fields` argument. This example registers the existing `publishing_list` Symfony AI tool under the MCP name `articles.page`. The publishing toolbox and authorization policy are application services.

```yaml
# config/services.yaml
services:
    app.approved_articles:
        class: CodeMode\Symfony\Ai\ApprovedToolbox
        arguments: ['@app.publishing_toolbox', [publishing_list], '@App\CodeMode\PublishingAuthorization']

    app.articles_provider:
        class: CodeMode\Symfony\Provider\SymfonyAiProvider
        arguments:
            $namespace: articles
            $toolbox: '@app.approved_articles'
            $methods: { page: publishing_list }
            $selectableFields: { publishing_list: [id, title, meta_description] }
            $limits: '@code_mode.limits'

# config/packages/code_mode.yaml (add to your existing configuration)
code_mode:
    providers: [app.articles_provider]
```

After discovery, calling `articles.page` with `{"page":1,"fields":["id"]}` returns each visible row with just its `id` and preserves the surrounding pagination metadata. Omitting `fields` returns the full rows. Selectable names appear in discovery, so configure only names you intend every user of that reader to discover. The original reader controls which records and values the user can access. A field withheld from an authorized row stays absent even when selected.

The decorator accepts readers with ordinary object arguments and an object result containing an `items` array of objects. Each selected name identifies one top-level row key. The decorator strips the selector when calling the original policy and handler. Full reader output must fit the session’s per-tool byte limit before selection. The original Symfony AI tool validates any declared full output schema first. Field selection reduces the response sent to clients. The underlying handler still performs the full read.

## Compose tool providers

To combine approved Symfony AI tools and an MCP service, register providers explicitly:

```yaml
code_mode:
    identity_provider: App\CodeMode\OperatorIdentityProvider
    providers: [app.catalog_provider, app.media_provider]
```

`SymfonyAiProvider` wraps an `ApprovedToolbox`; `McpProvider` wraps a host-configured `McpGateway`. Each takes a namespace and an explicit map from PHP method aliases to approved original tool names. `ReadToolsProvider` adapts existing `ReadToolInterface` services. The [provider registry](../php-code-mode/src/Provider/ProviderRegistry.php) validates namespaces and rejects collisions before creating the catalog and broker.

Discovery still returns full schemas. Selected calls can use `$tools->catalog->getArticles(['ids' => [1]])` or `$tools->call('catalog.getArticles', ['ids' => [1]])`. Both reach the same broker. Host policy, input validation, the disclosed-tool allowlist and execution budgets are unchanged. The generated PHPDoc interfaces describe supported schema shapes; they do not prove program correctness or confer permission. Unknown/complex shapes stay broad.

The generic provider contract and MCP adapter live in the PHP core; the Symfony AI adapter stays in this bundle. Other PHP applications can use the core independently of the demo.

## Generated PHP runtime

Use the [runner setup](../php-code-mode/README.md#sandboxed-php) on a dedicated Linux Docker endpoint with gVisor. On macOS a local Linux VM can supply that endpoint; ordinary Docker Desktop/OrbStack alone is insufficient. In ignored `.env.local`:

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

The JSON path runs in the current PHP process; it does not require CLI, `pcntl`, `posix` or Docker. The HTTP adapter adds authenticated identity and persistent budgets. The retained STDIO transport uses an explicit trusted local identity. Use `SessionFactory::createBatch($identity)` and
`CodeMode\Symfony\Ai\BatchToolbox` for a Symfony AI conversation. Ordered
results reach the agent without automatic filtering or joining. Explicit
`mode: php` keeps gVisor; the default mode is `catalog`. No unsafe local PHP executor is available.
[HTTP state and limits](#http-state-and-limits) · [Core JSON batch API](../php-code-mode/README.md#json-batches-without-a-sandbox-dependency).

## Agent responses and host evidence

Successful gVisor execution returns `{"value": ...}`. A failure returns `{"error":{"reason":"tool_denied","tool":"publishing_read"}}`; the tool name is omitted when no failed call is known. MCP also sets `isError`. Private exception messages and intermediate results are not included. Unknown executors retain their runtime warning on every execution response.

The underlying `ExecutionResult` and `SandboxException` keep their complete host ledgers. For Symfony AI, `CodeModeToolbox::lastExecution()` exposes the latest call's PHP and sanitized trace to trusted application code, outside `ToolResult`. It is cleared before each tool call.

For MCP recording, the operator can enable a separate file:

```sh
mkdir -p -m 700 var/code-mode-traces
php bin/console code-mode:serve --no-debug --trace-file=var/code-mode-traces/take-1.jsonl
```

Use a **new filename for each server process**. The sink creates files exclusively with mode 0600, refuses existing files/symlinks and caps each file at 2 MiB. If recording fails, the call reports a sanitized failure; it does not retry execution. Traces contain submitted PHP and returned application data: keep them private and review before sharing. No path or trace option is exposed to the agent, and traces are never added to MCP content, structured results or `_meta`.

## Reuse existing Symfony AI tools

Install compatible `symfony/ai-agent` and `symfony/ai-platform` packages (`^1.0@beta`). Existing `#[AsTool]` handlers can participate without implementing `ReadToolInterface`:

```yaml
code_mode:
    identity_provider: App\CodeMode\OperatorIdentityProvider
    ai_toolbox: app.publishing_toolbox
    ai_tools: [publishing_read, publishing_list]
    ai_authorization: App\CodeMode\PublishingAuthorization
```

Use a registered Symfony AI toolbox and a [ToolAuthorizationInterface](src/Ai/ToolAuthorizationInterface.php) service. An application can combine explicit `tools` services with its `ai_tools` allowlist.

Only named tools with an explicit read-only hint enter the catalog. Missing names, duplicates and absent policy configuration fail closed. The hint is a prerequisite, not proof that a handler is read-only: approve reviewed handlers. `ToolAuthorizationInterface::isGranted()` receives the host identity, tool name and arguments before schema validation and immediately before invocation. It must reject malformed or unauthorized requests. Handlers must retain resource/field checks at the actual read.

Calls go through the original Symfony AI toolbox, preserving its argument resolver and lifecycle events. Results must be safe JSON data. Optional tool metadata `code_mode.output_schema` supplies an output contract, validated before returning data to the sandbox. The publishing example declares object schemas. The installed Symfony AI version represents lifecycle denials as ordinary string results; an object schema rejects these with sanitized `tool_failed`. Do not infer denial by matching text, or use a toolbox that converts private exception diagnostics into public result strings.

`tools_describe` adds a bounded PHP invocation example and input/output shapes for only the selected tools. JSON objects are `stdClass`, lists are arrays, and unknown/referenced schemas remain `mixed`. These guides are documentation; the broker still checks schemas and authorization for every call.

## Full schema discovery

Search returns candidate names and descriptions in a `tools` list, with `matched`, `returned`, `limit` and `truncated`. A truncated or empty result includes refinement guidance. All query terms must match; an exact approved name selects one tool. Search returns at most eight entries and 32 KiB. It never makes a tool callable.

Call `tools_describe` for the selected tools before executing PHP. The response includes the original input/output schemas and a PHP invocation guide. The broker continues to validate arguments and results and authorize every call. Search and describe never reset execution attempts. The core's list-only `search()` API remains available for existing PHP callers.

## Permission-aware discovery

Configure a capability visibility policy alongside your existing resource authorization:

```yaml
code_mode:
    visibility_policy: App\PublicationVisibility
```

```php
use CodeMode\ExecutionIdentity;
use CodeMode\ToolVisibilityInterface;

final class PublicationVisibility implements ToolVisibilityInterface
{
    public function isVisible(ExecutionIdentity $identity, string $tool): bool
    {
        return 'publication_read' === $tool
            && $identity->allows('publication.read_published');
    }
}
```

The policy receives the registered tool name (including its provider namespace, when used). It checks capability availability without inventing resource arguments. The catalog filters contracts **before** ranking, counts and truncation. Both search and describe apply it; exceptions hide the capability. A hidden tool cannot be invoked by guessing its name.

Execution rechecks visibility before the existing argument/resource policy on **every** underlying call. Previously discovered names and persisted session state never grant permission. Symfony HTTP derives a fresh identity from Security on each request. In long-lived sessions, a policy must query current application permissions if they can change; an `ExecutionIdentity` is an immutable snapshot. Keep mutable resource and field checks at the actual read as well.

The policy is optional for compatibility: without one, discovery lists the host-approved catalog and existing per-call authorization still applies. It works with read services, Symfony AI tools and namespaced providers, for JSON and isolated PHP. No per-user discovery result is shared or cached. The independent Symfony application uses `voter_rules` to apply its existing access policy.
