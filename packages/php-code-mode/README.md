# Symfony Code Mode — PHP package

A reusable Composer library for running real PHP in gVisor and composing explicitly authorized tools through a trusted host broker. This is a security-focused proof, not an audited public execution service. See [the security model and runtime decision](docs/security.md) and [the verification record](docs/verification.md).

The package has no Symfony application, Drupal or model-provider dependency. It uses the official PHP MCP SDK for an optional gateway; hosts can also register local trusted handlers. Generated PHP receives only `$tools->call($name, $arguments)` and returns a JSON-compatible value. The broker owns the identity, schema, authorization, and call budget.

## Run the proof

The **host** needs PHP 8.2+ CLI, `pcntl`, `posix`, Composer, and access to a dedicated Linux Docker engine with gVisor `runsc`. macOS can use a Linux VM; ordinary Docker Desktop does not satisfy the runtime requirement. The **guest** uses the pinned PHP 8.4 image. Do not run the broker inside PHP-FPM or a web request; use a dedicated CLI/queue worker.

On a dedicated Ubuntu 24.04 host, review and run `sudo bash scripts/install-gvisor-ubuntu.sh`. It installs the signed, pinned gVisor package and restarts Docker. For other systems, follow the [official gVisor installation instructions](https://gvisor.dev/docs/user_guide/install/). Never expose the Docker socket to the guest or an HTTP client.

From this package directory, using a trusted Docker context connected to that host:

```bash
composer install
docker build -t php-code-mode-runner:proof runner
export CODE_MODE_IMAGE="$(docker image inspect --format '{{.Id}}' php-code-mode-runner:proof)"
composer test
composer test:sandbox
php examples/proof.php
```

The image ID must be `sha256:…`; mutable tags are rejected by the library. The build context contains only the runner bootstrap and Dockerfile. The example executes a PHP loop with three `demo.add` calls and returns `sum: 42`, the guest PHP version, and a host-recorded tool-call ledger. No model credentials or network services are needed.

When using the root Symfony checkout, dependencies already come from its Composer path repository. Run `composer test:package`, `composer test:sandbox`, and `composer proof:code-mode` there instead. The sandbox suite fails if the configured image/runtime is unavailable; it never skips or falls back. Run it alone on a dedicated test Docker endpoint: its cleanup assertions check for any remaining package-managed containers.

For this project's macOS development VM:

```bash
export CODE_MODE_DOCKER_COMMAND='["/opt/homebrew/bin/orb","-m","code-mode-proof","-u","root","docker"]'
export CODE_MODE_IMAGE="$(orb -m code-mode-proof -u root docker image inspect --format '{{.Id}}' php-code-mode-runner:proof)"
```

`CODE_MODE_DOCKER_COMMAND` is trusted local argv configuration used by examples/tests, not agent input. A standard Docker SSH context also works without an OrbStack dependency. To build across the OrbStack CLI, send only the runner context:

```bash
tar -C runner -cf - Dockerfile bootstrap.php .dockerignore | orb -m code-mode-proof -u root docker build -t php-code-mode-runner:proof -
```

## Embed the library

The package name is `ronaldtebrake/php-code-mode`; it has not been published to Packagist. This repository consumes it through a Composer `path` repository at version `0.1.0`. Another application can point a path repository at this package and require `ronaldtebrake/php-code-mode:^0.1`, or publish it from a dedicated repository later.

```php
use CodeMode\{ExecutionIdentity, ExecutionLimits, GvisorSandbox, Tool, ToolBroker};

$broker = new ToolBroker([
    new Tool(
        'demo.add',
        json_decode('{"type":"object","properties":{"a":{"type":"integer"},"b":{"type":"integer"}},"required":["a","b"],"additionalProperties":false}'),
        static fn (ExecutionIdentity $user): bool => $user->allows('calculate'),
        static fn (stdClass $args): int => $args->a + $args->b,
    ),
]);
$result = (new GvisorSandbox($trustedImageId))->execute(
    'return $tools->call("demo.add", ["a" => 20, "b" => 22]);',
    $broker,
    new ExecutionIdentity('authenticated-user', ['calculate']),
    ['demo.add'],
    new ExecutionLimits(wallTimeMs: 5000, maxToolCalls: 3),
);
```

The PHP body has no `<?php` tag. `ExecutionResult::value` is untrusted guest output; it is never proof that an action occurred. Only `toolCalls` is recorded by the host, with names, statuses, timings and serialized result sizes. Catch `SandboxException` and use its stable `reason` for rejection reporting; its `toolCalls` retains work before a failed execution. An `incomplete` call may already have caused upstream effects. Output from guest `echo`/STDERR is counted and discarded.

Handlers run in a supervised fork of the CLI host. Open database/MCP/HTTP connections **inside the handler**; do not capture already-open connections or use parent-memory mutation for state. Check object/tenant access in the authorization callback and again at the upstream service. Timeouts do not undo completed side effects. Start with read-only tools.

## MCP and progressive discovery

`McpGateway` accepts a factory that opens a **fresh connected** `Mcp\Client`, plus explicit authorization callbacks keyed by approved upstream tool name. It filters discovery by this local configuration, retains input/output schemas, and creates broker handlers. Upstream annotations never grant access. Both discovery and invocation run in supervised CLI child processes; the factory must set transport byte/time limits too.

```php
use CodeMode\{CodeModeSession, ExecutionIdentity, ExecutionLimits};
use CodeMode\Mcp\McpGateway;

$gateway = new McpGateway($connectFreshClient, [
    'articles.list' => static fn (ExecutionIdentity $user): bool => $user->allows('content.read'),
]);
$catalog = $gateway->discover();
$session = new CodeModeSession($catalog, $gateway->broker($catalog), $sandbox,
    new ExecutionIdentity('authenticated-user', ['content.read']), new ExecutionLimits());
$matches = $session->search('articles');
$schemas = $session->describe(['articles.list']);
$result = $session->execute('return $tools->call("articles.list");');
```

Adapt the example to the discovered tool's required arguments. Only described, locally approved tools can be called; describing a tool does not bypass its authorization callback. The default session allows two execution attempts, including failures. Create a new session for a new host-authorized task. `CodeModeSession::definitions()` supplies the three canonical schemas for MCP servers and model toolboxes.

Discovery permits 128 upstream tools, 8 continuation cursors, a 64 KiB result and a 3-second worker deadline. Search returns at most 8 name/description matches; describe accepts at most 16 names. Large catalogs must be narrowed upstream. Tool results must be structured JSON or a single text item; media/resource results are rejected. The gateway preserves envelopes such as Drupal Tool API's `success`, `message`, `data`. Output schemas are metadata, not proof of valid or trusted output.

## Demo for judges

The root Symfony app supplies an editorial audit, batched direct-call baseline, inspectable PHP/MCP trace, and separate reference/live modes. It also exposes Code Mode to external MCP clients over STDIO. See [the walkthrough](../../docs/demo.md). A separate [Drupal DDEV proof](../../examples/drupal/README.md) verifies real published/restricted node reads through **Tool API → MCP Server Tool Bridge → MCP Server**, using local STDIO and this package. Drupal HTTP authentication remains unverified. No token-saving claim is inferred from the reference run.
