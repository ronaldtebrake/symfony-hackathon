# Verify the package

From the repository root, run `composer check` for the standard suite. The PHP package tests exercise JSON envelope limits, per-call authorization, budget reservation, failure reporting and the MCP broker. The independent Symfony example tests HTTP authentication and permission revocation. [Run the judge demo](../../../docs/demo.md) · [HTTP example](../../../examples/symfony/README.md)

## Optional sandbox verification

The earlier PHP execution design requires the gVisor environment described in the package guide. Run `composer test:sandbox` from this package on a dedicated Docker endpoint. The suite checks the isolation controls, including terminated PHP execution, authorization, resource limits and cleanup. These checks apply to the sandbox runtime and its tested host configuration. [Runtime setup](../README.md#sandboxed-php) · [Threat model](security.md)
