# Adopt the catalog in a separate Symfony app

This CLI-only app owns its dependencies, synthetic articles, native `#[AsTool]` service, identity and policy. It defaults to `mode: catalog` and uses the bundle's normal `code-mode:serve` command. It imports no main-demo code and requires no model token or Docker configuration.

```sh
composer install --working-dir=examples/symfony
composer check --working-dir=examples/symfony
php examples/symfony/bin/console code-mode:doctor
composer proof:catalog --working-dir=examples/symfony
```

The [current capture](../../docs/evidence/catalog-proof.json) contains the `adoption` proof: **15 checks passed**, including only the approved contract appearing in search, restricted article 202 denied, permitted article 101 returned in the same session, stable entry tools and attempt exhaustion. These are operator-authored requests over real MCP, not model-generated answers. The capture came from a clean temporary install with package sources detached and no root autoloader. It proves independent consumption, not a Packagist release or remote authentication. Unix PHP CLI, `pcntl` and `posix` are still required.

Point an MCP client at `php bin/console code-mode:serve --no-debug` with this directory as its working directory. Search for `publication_read`, then use `execute_tools` with literal calls. Keep the same server process for follow-ups.

## Reproduce the historical PHP proof

Configure the [dedicated gVisor endpoint and pinned image](../../packages/php-code-mode/README.md#sandboxed-php) in this app's ignored `.env.local` or process environment; the root `.env.local` is never loaded. Do not copy model tokens.

```sh
ADOPTION_MODE=php php bin/console code-mode:doctor
composer proof
# For a client using PHP instead of catalog JSON:
ADOPTION_MODE=php php bin/console code-mode:serve --no-debug
```

Run these commands from `examples/symfony`. `composer proof` explicitly selects PHP. The two modes have separate compiled container caches. The [original 24-check capture](../../docs/evidence/fresh-symfony-app.json) remains unchanged: operator-written PHP, authorized/denied reads, container cleanup and exhausted attempts. New JSON evidence does not replace it.

## What the consuming application supplies

The integration selects the catalog plus four application settings in [config/services.php](config/services.php):

```php
$container->extension('code_mode', [
    'mode' => 'catalog',
    'identity_provider' => LocalReader::class,
    'ai_toolbox' => 'app.toolbox',
    'ai_tools' => ['publication_read'],
    'ai_authorization' => ReadPolicy::class,
]);
```

- [ArticleTool](src/ArticleTool.php) is a normal Symfony AI tool. Its existing toolbox and argument resolver execute it. `publication_count` is deliberately not approved, despite its read-only annotation.
- [ReadPolicy](src/ReadPolicy.php) checks the host permission, tool name, argument shape and publication status before invocation. The data service rechecks publication at the read boundary. Private and missing IDs both produce a sanitized denial. Unit tests confirm denied calls never reach the handler.
- [LocalReader](src/LocalReader.php) represents the local operator. Agent arguments cannot select its identity or permissions. This fixed CLI identity does not implement remote authentication.

Both proofs use the bundle's stock MCP STDIO server and the same tool, identity and policy. The catalog does not execute generated code. Its two submission attempts include failures. The PHP path uses gVisor and retains the original container limits and cleanup checks.

Both packages remain unpublished Composer path packages. Composer copies them into this app's own `vendor/` (`symlink: false`), with its own lockfile. Refresh copies after package changes with `composer reinstall ronaldtebrake/php-code-mode ronaldtebrake/symfony-code-mode`. CI installs and proves the catalog consumer before the root app on PHP 8.2–8.4; the historical PHP proof still runs in the gVisor job.
