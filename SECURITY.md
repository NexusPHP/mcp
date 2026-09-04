# Security Policy

## Supported versions

The SDK is in `0.x`, released on Packagist as [`nexusphp/mcp`](https://packagist.org/packages/nexusphp/mcp).
From `1.0.0` the same release also ships as the component packages `nexusphp/mcp-core`, `nexusphp/mcp-server`,
`nexusphp/mcp-client`, and `nexusphp/mcp-extensions`, which carry the same version and the same support window.
Security fixes target the latest released minor only, per the supported-release window in
[DEPENDENCY_POLICY.md](DEPENDENCY_POLICY.md). From 1.0 onward, fixes land on the latest minor of the
current major, and the previous major receives security fixes for six months after its successor's
first stable release.

| Version | Supported |
| --- | --- |
| Latest `0.x` minor | Yes |
| Older releases | No |

## Reporting a vulnerability

Please do not open a public issue for security vulnerabilities.

Instead, email **paulbalandan@gmail.com**, or use GitHub's private vulnerability reporting from the
**Security** tab of the repository. Include:

- a description of the vulnerability and its impact,
- steps to reproduce or a proof of concept,
- any relevant version, configuration, or environment details.

You can expect an acknowledgement within 3 business days, and an assessment with a proposed fix plan
within 7 days. Once the report is confirmed, the fix and a coordinated disclosure timeline are agreed
with you.
