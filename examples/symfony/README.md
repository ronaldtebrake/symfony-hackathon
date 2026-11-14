# Adopt the catalog in a Symfony application

This small application owns its Composer dependencies, synthetic articles, native Symfony AI `#[AsTool]` service and access policy. It exposes **two MCP tools over authenticated HTTP**: `search_tools` and `execute_tools`. Approved JSON calls run as normal Symfony services. There is no Unix worker, process-control extension, Docker or model-token requirement.

[Judge quickstart: clone → prove → connect → revoke](../../README.md#judge-quickstart).

## Install and verify

From the repository root:

```sh
composer install --working-dir=examples/symfony
composer check --working-dir=examples/symfony
php examples/symfony/bin/console code-mode:doctor
composer proof:http --working-dir=examples/symfony
```

The [fresh-install capture](../../docs/evidence/http-catalog-proof.json) records actual HTTP JSON-RPC: article 101 returned, article 202 denied, missing/invalid authentication rejected, an unauthorized origin rejected, oversized calls refused, and attempts still exhausted after a server restart and a new MCP connection. It then revokes the reader's permission: the identical article 101 request is denied and a new search hides the contract. Granting permission again does not reset the exhausted budget. A second authenticated user sees no article contract and cannot inherit the reader's permissions or discovery state. `ArticleVoter` controls capability discovery and reads, and protects the normal article controller too.

The capture used its own `vendor/`, with package sources detached and no root autoloader. Process creation and Unix process-control functions were disabled **in the HTTP application**. The test harness starts a temporary PHP development server and uses ephemeral credentials; it never writes tokens to the report. These are operator-authored checks, not a model conversation or a new token benchmark. The harness requires Composer's ordinary development dependencies, not a container runtime.

## Start the application

Generate a local token with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Store it in this example's ignored `.env.local`:

```dotenv
ADOPTION_MCP_TOKEN=<your random token, at least 32 characters>
```

Do not commit the token. Run:

```sh
php examples/symfony/bin/console demo:reader-access grant
php examples/symfony/bin/console code-mode:reset-budget demo-reader
composer start --working-dir=examples/symfony
```

The demo starts without a read grant. Reset the budget before this new take because an earlier proof or conversation may have consumed its attempts. The operator command writes an ignored local permission fixture; Symfony Security reloads its value on each HTTP request. Missing or malformed state denies the role. Production applications use their own permission store. `demo:reader-access` is never exposed as an AI or MCP tool.

Connect your MCP client to `http://127.0.0.1:8001/mcp`, sending `Authorization: Bearer <your token>`. [Codex configuration](../../docs/codex.md) uses a host environment variable, not a token in TOML. The built-in PHP server is for local development; deploy the same front controller through normal Symfony hosting, with HTTPS and your application's Symfony Security authentication.

Search for `publication_read`, execute with `{"id":101}`, then try `{"id":202}`. The second read must return `tool_denied`, without restricted data. There are two execution attempts per authenticated user, including failures. Discovery and submitted-call reservations persist in `var/code-mode`; reconnecting cannot reset them. Before a new recording take, the **operator** runs:

```sh
php examples/symfony/bin/console code-mode:reset-budget demo-reader
```

For the [90-second recording](../../docs/demo.md), grant access and reset the budget before the take. Discover and read article 101, then run `php examples/symfony/bin/console demo:reader-access revoke` as the operator. Repeat the identical read using the same token and connection: `tool_denied`, with no new article data. Search again: no visible contract. Do not reset or reconnect between these calls. Revocation cannot erase an earlier result already in the conversation and does not interrupt a running handler.

There is no reset tool. Preserve that state directory across requests and deploys. The default filesystem storage and lock support one application host. Multiple hosts need a shared store and compatible lock; [integration and hosting limits](../../packages/symfony-code-mode/README.md#http-state-and-limits).

## What the application supplies

[config/services.php](config/services.php) connects the native tool and application voter:

```php
$container->extension('code_mode', [
    'mode' => 'catalog',
    'ai_tool_services' => [ArticleTool::class],
    'ai_tools' => ['publication_read'],
    'voter_rules' => ['publication_read' => [
        'discover' => Adoption\ArticleVoter::DISCOVER,
        'execute' => Adoption\ArticleVoter::READ,
        'subject' => 'id',
    ]],
    'http' => ['enabled' => true, 'permissions' => [
        'publication.read_published' => 'ROLE_READER',
    ]],
]);
```

- [ArticleTool](src/ArticleTool.php) uses Symfony AI's schema, argument resolver and `#[IsGrantedTool]`. `publication_count` is deliberately not approved, despite its read-only annotation.
- [ArticleVoter](src/ArticleVoter.php) checks current permission and publication status for both the tool and [ArticleController](src/ArticleController.php). The broker still validates arguments and denies private/missing IDs before invocation. The data service rechecks publication at the read boundary. `ReadPolicy` and `PublicationVisibility` remain only for the historical operator/PHP proofs.
- [DemoTokenHandler](src/DemoTokenHandler.php) maps a local bearer token to `demo-reader`. An optional separate viewer token has no read grant. Real applications reuse their own authentication and user identifiers. Identity and permissions never come from tool arguments.
- [config/routes.php](config/routes.php) and Symfony's MCP bundle expose `/mcp`. The registry contains only `CatalogTools`; application tools cannot bypass the broker through a separate MCP registration.

Deadlines are **cooperative**: late results are rejected and later calls do not start. The package cannot terminate a blocked in-process handler. Configure hosting request limits, memory limits and downstream timeouts. Installed handlers remain trusted application code; JSON is not a defense against prompt injection.

Both packages remain unpublished Composer path packages, copied into this app's own `vendor/` with `symlink: false`. After package edits, refresh copies with `composer reinstall --working-dir=examples/symfony ronaldtebrake/php-code-mode ronaldtebrake/symfony-code-mode`. The HTTP CI job exercises Linux and Windows without Unix process extensions; the local reviewed capture was made on macOS.

## Maker and profiler

Development dependencies already include MakerBundle and WebProfilerBundle. From this example directory:

```sh
APP_ENV=dev php bin/console make:code-mode-tool InventoryLookup
APP_ENV=dev composer start
```

The maker creates a tool, deny-by-default voter, explicit registration and security test. Implement the handler and voter before enabling access. It never changes the existing article grant or budget. The dev server exposes `/_profiler`; open the latest `/mcp` request and select **Code Mode** for discovery, budget checkpoints, broker decisions and native AI events. The normal `catalog` environment has no profiler. Keep dev mode local: standard Symfony/AI panels can include request credentials and tool data, even though the Code Mode panel omits them.

`composer test` exercises the generated files in a temporary copied app, verifies controller/tool policy parity and permission revocation, checks native Symfony AI calls with `AiAssertionsTrait`, and renders the profiler panel. No model or container runtime is used. [Full development integration](../../packages/symfony-code-mode/README.md#native-symfony-development).

## Historical PHP proof

The [original 24-check capture](../../docs/evidence/fresh-symfony-app.json) remains unchanged. To reproduce it, configure the [dedicated gVisor endpoint and pinned image](../../packages/php-code-mode/README.md#sandboxed-php) in this app's ignored `.env.local`, then run `composer proof --working-dir=examples/symfony`. It explicitly selects PHP mode and uses a separate container cache. Its runtime dependencies and isolation guarantees apply only to generated PHP. The HTTP catalog never executes that code or selects a worker fallback.
