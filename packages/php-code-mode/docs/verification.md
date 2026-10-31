# Proof verification

Verified locally on 31 October 2026. This records observed behavior, not an external security audit.

| Component | Tested version/environment |
| --- | --- |
| Coordinator | PHP 8.3.19 CLI on macOS arm64 |
| Dedicated sandbox host | Ubuntu 24.04.5 arm64, OrbStack VM `code-mode-proof` |
| Docker engine | 29.1.3 inside that VM |
| gVisor | `release-20261026.0`, signed package `20261026.0` |
| Guest PHP | 8.4.27 CLI, official Alpine image pinned in the Dockerfile |
| Built runner image | `sha256:686fc2b296b8801cdfb4e9cbcc9b333f2fbe42c31bff447bab9bf1eb73ad1a6b` |

Results:

- Application/configuration checks: valid Composer manifests, YAML and Symfony container; **11 application tests and 29 package tests passed**. Coverage includes real STDIO/HTTP MCP transport, policy filtering, bounded discovery, progressive sessions, report access and a batched direct baseline.
- Independent `composer install` and `composer test` inside the package: passed with no Symfony application dependency.
- `composer test:sandbox`: **28 real gVisor tests passed**, including both hard-crash scenarios (guest sleeping and broker handler blocked), plus retention of completed calls when execution later times out. No managed containers remained.
- `composer test:demo`: **3 tests passed** using real MCP and gVisor. These cover equivalent batched reference audits, an external MCP client discovering/executing Code Mode, and the Symfony AI pipeline executing PHP supplied through a mocked provider response. The mock is not live inference.
- `composer demo`: both paths checked 48 published articles and returned the same 10 expected findings using 10 batched upstream calls. Measured tool-result payload: direct **20,154 bytes**, Code Mode **3,014 bytes**, including discovery. No model was called; no token savings are claimed. The local workbench displayed the program, trace, findings and reference label; its command selector and keyboard tabs were checked in the browser.
- `composer proof:code-mode`: native PHP loop returned `sum: 42`, values `[12, 14, 16]`, and three host-recorded successful `demo.add` calls.
- Package archive inspected: runner, library, tests and documentation included; vendor, caches, local secrets and Git metadata excluded.
- PHP syntax, installer shell syntax, Composer platform requirements and Git whitespace checks passed. Composer reported no known dependency advisories during installation/update; this is not a runtime/image vulnerability scan.

The crash test allows a terminated zombie process while its OS parent/subreaper completes reaping; it rejects a live worker after the fallback deadline. An ordinary cancellation reaps the broker directly. The output and frame flood tests use bounded host reads. The network test first proves that the control server is reachable from a trusted control container, then verifies the isolated guest cannot connect.

GitHub Actions defines an Ubuntu amd64 standalone-package job that installs the pinned gVisor release, builds the runner, and executes the security suite and full demo integration checks. **That remote CI job has not been run from this checkout.** Windows, rootless Docker, alternate gVisor platforms, and a native Linux coordinator have not been verified locally. Do not generalize this record into compatibility or security claims for those environments.

Live Hugging Face inference remains unverified because no API key is configured. The Drupal HTTP adapter is tested against a local MCP server, not a Drupal installation. The demo video is planned but has not been recorded. These gates remain separate from the 71 passing local tests.

See [setup and commands](../README.md) and [the threat model and deployment limits](security.md) before rerunning or embedding the package.
