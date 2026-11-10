# Adopt the catalog in a Symfony application

This small application owns its Composer dependencies, synthetic articles, native Symfony AI `#[AsTool]` service and access policy. It exposes **two MCP tools over authenticated HTTP**: `search_tools` and `execute_tools`. Approved JSON calls run as normal Symfony services. There is no Unix worker, process-control extension, Docker or model-token requirement.

## Install and verify

From the repository root:

```sh
composer install --working-dir=examples/symfony
composer check --working-dir=examples/symfony
php examples/symfony/bin/console code-mode:doctor
composer proof:http --working-dir=examples/symfony
```

The [fresh-install capture](../../docs/evidence/http-catalog-proof.json) passes **23 checks**. It records actual HTTP JSON-RPC: article 101 returned, article 202 denied, missing/invalid authentication rejected, an unauthorized origin rejected, oversized calls refused, and attempts still exhausted after a server restart and a new MCP connection. A second authenticated user cannot inherit the reader's permissions or discovery state.

The capture used its own `vendor/`, with package sources detached and no root autoloader. Process creation and Unix process-control functions were disabled **in the HTTP application**. The test harness starts a temporary PHP development server and uses ephemeral credentials; it never writes tokens to the report. These are operator-authored checks, not a model conversation or a new token benchmark. The harness requires Composer's ordinary development dependencies, not a container runtime.

## Start the application

Generate a local token with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Store it in this example's ignored `.env.local`:

```dotenv
ADOPTION_MCP_TOKEN=<your random token, at least 32 characters>
```

Do not commit the token. Run:

```sh
composer start --working-dir=examples/symfony
```

Connect your MCP client to `http://127.0.0.1:8001/mcp`, sending `Authorization: Bearer <your token>`. [Codex configuration](../../docs/codex.md) uses a host environment variable, not a token in TOML. The built-in PHP server is for local development; deploy the same front controller through normal Symfony hosting, with HTTPS and your application's Symfony Security authentication.

Search for `publication_read`, execute with `{"id":101}`, then try `{"id":202}`. The second read must return `tool_denied`, without restricted data. There are two execution attempts per authenticated user, including failures. Discovery and submitted-call reservations persist in `var/code-mode`; reconnecting cannot reset them. Before a new recording take, the **operator** runs:

```sh
php examples/symfony/bin/console code-mode:reset-budget demo-reader
```

There is no reset tool. Preserve that state directory across requests and deploys. The default filesystem storage and lock support one application host. Multiple hosts need a shared store and compatible lock; [integration and hosting limits](../../packages/symfony-code-mode/README.md#http-state-and-limits).

## What the application supplies

[config/services.php](config/services.php) connects the existing toolbox and policy:

```php
$container->extension('code_mode', [
    'mode' => 'catalog',
    'ai_toolbox' => 'app.toolbox',
    'ai_tools' => ['publication_read'],
    'ai_authorization' => ReadPolicy::class,
    'http' => ['enabled' => true, 'permissions' => [
        'publication.read_published' => 'ROLE_READER',
    ]],
]);
```

- [ArticleTool](src/ArticleTool.php) uses Symfony AI's schema and argument resolver. `publication_count` is deliberately not approved, despite its read-only annotation.
- [ReadPolicy](src/ReadPolicy.php) checks identity, permission, name, argument shape and publication status before invocation. The data service rechecks access at the read boundary. Private and missing IDs both produce a sanitized denial; denied calls never reach the handler.
- [DemoTokenHandler](src/DemoTokenHandler.php) maps a local bearer token to `demo-reader`. An optional separate viewer token has no read grant. Real applications reuse their own authentication and user identifiers. Identity and permissions never come from tool arguments.
- [config/routes.php](config/routes.php) and Symfony's MCP bundle expose `/mcp`. The registry contains only `CatalogTools`; application tools cannot bypass the broker through a separate MCP registration.

Deadlines are **cooperative**: late results are rejected and later calls do not start. The package cannot terminate a blocked in-process handler. Configure hosting request limits, memory limits and downstream timeouts. Installed handlers remain trusted application code; JSON is not a defense against prompt injection.

Both packages remain unpublished Composer path packages, copied into this app's own `vendor/` with `symlink: false`. After package edits, refresh copies with `composer reinstall --working-dir=examples/symfony ronaldtebrake/php-code-mode ronaldtebrake/symfony-code-mode`. The HTTP CI job exercises Linux and Windows without Unix process extensions; the local reviewed capture was made on macOS.

## Historical PHP proof

The [original 24-check capture](../../docs/evidence/fresh-symfony-app.json) remains unchanged. To reproduce it, configure the [dedicated gVisor endpoint and pinned image](../../packages/php-code-mode/README.md#sandboxed-php) in this app's ignored `.env.local`, then run `composer proof --working-dir=examples/symfony`. It explicitly selects PHP mode and uses a separate container cache. Its runtime dependencies and isolation guarantees apply only to generated PHP. The HTTP catalog never executes that code or selects a worker fallback.
