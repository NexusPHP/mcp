# Security Policy

## Supported versions

The SDK is released on Packagist as [`nexusphp/mcp`](https://packagist.org/packages/nexusphp/mcp) and as
the component packages `nexusphp/mcp-core`, `nexusphp/mcp-server`, `nexusphp/mcp-client`, and
`nexusphp/mcp-extensions`, which carry the same version and the same support window. Fixes land on the
latest minor of the current major, per the supported-release window in
[DEPENDENCY_POLICY.md](DEPENDENCY_POLICY.md), and when a new major ships, the previous major receives
security fixes for six months after its successor's first stable release.

| Version | Supported |
| --- | --- |
| Latest `1.x` minor | Yes |
| Older `1.x` releases | No |
| `0.x` | No |

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
