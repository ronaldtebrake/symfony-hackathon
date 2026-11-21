# Symfony application example

This independent Symfony app demonstrates how an application registers its own reader and access policy with the reusable catalog package. It also hosts the native Symfony Profiler used to watch the hackathon demo. For the live agent conversation, use the project’s [Symfony Code Mode connection guide](../../docs/codex.md#connect-symfony-code-mode).

## Install and verify

From the repository root, with PHP 8.2+ and Composer 2, run these commands.

```sh
composer install --working-dir=examples/symfony
composer check --working-dir=examples/symfony
composer proof:http --working-dir=examples/symfony
composer proof:direct --working-dir=examples/symfony
```

The HTTP proof starts a temporary Symfony server with two local identities. It checks that one reader can discover and read a published record, the other identity cannot inherit that access, and revocation blocks a fresh read. It also exercises invalid credentials, restricted content and execution limits across reconnects. The proof uses synthetic data and needs no model credentials.

The direct-tool proof opts the same approved `publication_read` service into `code_mode.http.direct_tools` in a separate Symfony environment. The reader calls it by name, then discovers and calls it through the catalog. After those two executions, both paths report the same exhausted budget. The proof also revokes permission between two identical direct calls. The next tool list omits `publication_read`, and the retry produces a denial with zero Symfony AI handler events. [Direct-call receipt](../../docs/evidence/direct-tool.json)

## How the application supplies a tool

- [ArticleTool](src/ArticleTool.php) supplies a Symfony AI tool definition and handler.
- [Catalog configuration](config/services.php) approves the reader and connects its discovery and execution checks to [ArticleVoter](src/ArticleVoter.php). The regular HTTP controller uses the same voter.
- [Route configuration](config/routes.php) exposes the two catalog tools through the MCP bundle. The application takes the caller’s identity from Symfony Security.

The example installs the local packages into its own `vendor/`. The development Profiler shows returned contracts, broker decisions and Symfony AI tool events for each recorded request. [Symfony integration and hosting limits](../../packages/symfony-code-mode/README.md)
