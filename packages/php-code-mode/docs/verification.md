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

- `composer check`: valid Composer manifests, YAML and Symfony container; 3 application tests and 24 broker/protocol/configuration tests passed.
- Independent `composer install` and `composer test` inside the package: passed with no Symfony application dependency.
- Independent `composer test:sandbox`: **27 real gVisor tests passed**, including both hard-crash scenarios (guest sleeping and broker handler blocked). No managed containers remained.
- `composer proof:code-mode`: native PHP loop returned `sum: 42`, values `[12, 14, 16]`, and three host-recorded successful `demo.add` calls.
- Package archive inspected: runner, library, tests and documentation included; vendor, caches, local secrets and Git metadata excluded.
- PHP syntax, installer shell syntax, Composer platform requirements and Git whitespace checks passed. Composer reported no known dependency advisories during installation/update; this is not a runtime/image vulnerability scan.

The crash test allows a terminated zombie process while its OS parent/subreaper completes reaping; it rejects a live worker after the fallback deadline. An ordinary cancellation reaps the broker directly. The output and frame flood tests use bounded host reads. The network test first proves that the control server is reachable from a trusted control container, then verifies the isolated guest cannot connect.

GitHub Actions now defines an Ubuntu amd64 standalone-package job that installs the pinned gVisor release, builds the runner, and executes the same suite. **That remote CI job has not been run from this checkout.** Windows, rootless Docker, alternate gVisor platforms, and a native Linux coordinator have not been verified locally. Do not generalize this test record into compatibility or security claims for those environments.

See [setup and commands](../README.md) and [the threat model and deployment limits](security.md) before rerunning or embedding the package.
