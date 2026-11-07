# Adopt Code Mode in a separate Symfony app

This CLI-only Symfony app owns its dependencies, synthetic articles, native `#[AsTool]` service, identity and read policy. It uses the bundle's normal `code-mode:serve` command. It imports no main-demo code and needs no model token.

From the repository root:

```sh
cd examples/symfony
composer install
composer check
```

Configure the [dedicated gVisor Docker endpoint and pinned runner image](../../packages/php-code-mode/README.md#run-the-proof). Set `CODE_MODE_IMAGE` and, if needed, `CODE_MODE_DOCKER_COMMAND` in this app's ignored `.env.local` or its process environment. The root app's `.env.local` is deliberately not loaded. Use the same trusted runtime endpoint; do not copy model tokens.

```dotenv
# Replace with the immutable image ID built on your dedicated engine.
CODE_MODE_IMAGE=sha256:REPLACE_WITH_64_HEX_DIGITS
CODE_MODE_DOCKER_COMMAND='["docker"]'
```

```sh
php bin/console code-mode:doctor
composer proof
# Optional local capture, after doctor has created var/:
php scripts/proof.php > var/adoption-proof.json
```

The [recorded proof](../../docs/evidence/fresh-symfony-app.json) was captured from a clean temporary installation, with package source directories detached after Composer installation and no root autoloader present. **24 checks passed:** discovery exposes only the approved tool, real PHP returns article 101, article 202 is denied without a value, both containers are removed, and a third execution is rejected. The report includes the exact PHP, host-observed calls, timings, run IDs, versions and lockfile hash. The PHP is operator-written, not model-generated. The separate live Symfony AI captures remain in the main workbench.

## What the consuming application supplies

The integration is four settings in [config/services.php](config/services.php):

```php
$container->extension('code_mode', [
    'identity_provider' => LocalReader::class,
    'ai_toolbox' => 'app.toolbox',
    'ai_tools' => ['publication_read'],
    'ai_authorization' => ReadPolicy::class,
]);
```

- [ArticleTool](src/ArticleTool.php) is a normal Symfony AI tool. Its existing toolbox and argument resolver execute it. `publication_count` is deliberately not approved, despite its read-only annotation.
- [ReadPolicy](src/ReadPolicy.php) checks the host permission, tool name, argument shape and publication status before invocation. The data service rechecks publication at the read boundary. Private and missing IDs both produce a sanitized denial. Unit tests confirm denied calls never reach the handler.
- [LocalReader](src/LocalReader.php) represents the local operator. Guest PHP cannot select its identity or permissions. This fixed CLI identity does not implement remote authentication.

The proof uses one MCP STDIO connection for both reads. Each execution uses a fresh container with the unchanged package limits: 5 seconds, 128 MiB, at most 10 tool calls and 2 execution attempts per session, including failures. The runner verifies gVisor, an immutable image, no guest network or host mounts, and drops credentials. No HTTP execution route or ordinary-Docker fallback is added.

Both packages are still unpublished local path packages. Composer **copies** them into this app's own `vendor/` (`symlink: false`), and this app has its own lockfile. This proves consumption independently of the demo; it does not prove a Packagist release. After changing either package, run `composer reinstall ronaldtebrake/php-code-mode ronaldtebrake/symfony-code-mode` here to refresh the copies. CI installs this app before the root application, checks it on PHP 8.2–8.4, and runs the real proof on the dedicated gVisor runner.

Full discovery is required: search returns names and descriptions, then `tools_describe` supplies the original schemas before execution. The approved tool list, policy, gVisor limits and per-call validation are unchanged. The original proof snapshot remains a historical capture; new runs verify the full-discovery default.
