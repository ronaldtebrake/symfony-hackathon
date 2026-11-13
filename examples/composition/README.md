# Compose Symfony AI and MCP tools

A separate Symfony application, with its own Composer install, catalog, policies and read-only proof viewer. It uses the two reusable packages; it does not load the hackathon app or its services. The main Symfony demo stays independent.

**The proof:** one PHP program audits 48 published articles, joining native Symfony AI readers with a Symfony-backed MCP media reader. It returns all 10 expected findings through 10 broker calls. Denied reads, undiscovered tools, raw upstream names, attempt limits and an infinite loop exercise the same runner and broker.

This is **operator-written reference PHP**, not a model-generated conversation or a token benchmark. The main hackathon workbench retains the real Symfony AI + Hugging Face evidence.

## Run separately

From this directory:

```sh
composer install
# Set CODE_MODE_IMAGE and CODE_MODE_DOCKER_COMMAND in ignored .env.local.
# Use the same pinned gVisor runner as the main demo; no new image is needed.
php bin/console code-mode:doctor
composer check
composer proof
php -S 127.0.0.1:8001 -t public
```

Open <http://127.0.0.1:8001>. The viewer reads `var/latest-proof.json`, falling back to the reviewed `proof/reference.json`. It cannot execute code. Every capture stays in ignored `var/`; only one reviewed reference is kept in the source tree. `var/interfaces.php` contains generated editor stubs. The committed proof is reproducible development evidence, not a tamper-proof attestation or security audit.

Use the [existing gVisor setup](../../packages/php-code-mode/README.md#sandboxed-php). Runtime limits match the main article audit: 15 seconds per execution, 3 seconds per tool, 30 calls, 128 MiB container memory, 32 MiB PHP memory, two attempts per session. The complete guest program, including the namespaced proxy, counts against the existing source-byte limit. The proof requires gVisor.

To start a conversation in any MCP client, configure this directory as its working directory and launch:

```sh
php bin/console code-mode:serve --no-debug
```

Ask it to audit every published article for an empty meta description or hero-image alt text, then request draft 49. The client must search and describe the tools first. The provided capture is a deterministic integration check; a future agent conversation may generate different PHP and needs its own correctness check.

## Registration

[config/services.php](config/services.php) creates two providers and lists them explicitly:

```yaml
code_mode:
    identity_provider: Composition\LocalReader
    providers: [app.catalog_provider, app.media_provider]
```

The native provider takes an **approved Symfony AI toolbox**, preserving its reflected schemas, argument resolver, events and policy. The MCP provider takes an **McpGateway with host-owned connections and explicit per-tool policies**. Both map PHP aliases to approved original names:

```php
new SymfonyAiProvider('catalog', $approvedToolbox, [
    'listArticles' => 'cms_list_articles',
    'getArticles' => 'cms_get_articles',
]);
new McpProvider('media', $approvedGateway, [
    'getMedia' => 'cms_get_media',
]);
```

After full discovery, submitted PHP can compose them:

```php
$articles = $tools->catalog->getArticles(['ids' => [7, 11]])->items;
$images = $tools->media->getMedia(['ids' => [7, 11]])->items;
```

The callable broker names are `catalog.getArticles` and `media.getMedia`. The legacy `$tools->call('catalog.getArticles', [...])` form also works. Namespace and method aliases are validated, collisions fail closed, and aliases never grant access. Account selection, allowed tools, endpoints and budgets remain host configuration. This example uses a fixed local CLI identity, not remote authentication.

## Schemas and the trust boundary

`tools_describe` retains full schemas and adds a short `phpInterface` guide. `ProviderRegistry::php()->stubs()` produces PHP interfaces with PHPDoc argument/result shapes for editors. Unknown references and complex unions fall back to broad types. These are documentation, **not whole-program type safety**. No remote schema text is evaluated as PHP. Input validation and authorization still happen in the broker on every call; native Symfony AI tools also retain their output validation. Generic MCP output schemas remain descriptive unless the upstream implementation validates them.

The guest proxy translates method calls into the existing broker protocol. It is not a security boundary. Both convenience calls and forged/raw calls must pass the same disclosed-tool allowlist, identity, argument validation and per-call policy. The runner image, network isolation and resource limits are unchanged. Host traces identify the provider and original upstream tool; that diagnostic metadata is not added to successful agent responses.
