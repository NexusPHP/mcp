# Nexus MCP SDK: Extensions

[![PHP](http://poser.pugx.org/nexusphp/mcp-extensions/require/php)](https://packagist.org/packages/nexusphp/mcp-extensions)
[![Latest Stable Version](http://poser.pugx.org/nexusphp/mcp-extensions/v)](https://packagist.org/packages/nexusphp/mcp-extensions)
[![License](https://img.shields.io/github/license/NexusPHP/mcp)](LICENSE)

The official MCP extensions for the [Nexus MCP SDK](https://github.com/NexusPHP/mcp), each with its
server and client halves: tasks, MCP Apps, and the OAuth extension grants.

> [!IMPORTANT]
> This repository is a read-only subtree split of [NexusPHP/mcp](https://github.com/NexusPHP/mcp).
> Open issues and pull requests there. Anything opened here is closed automatically with the same pointer.

## Installation

```bash
composer require nexusphp/mcp-extensions
```

This package depends on both `nexusphp/mcp-server` and `nexusphp/mcp-client`, since every extension
ships both halves. The umbrella `nexusphp/mcp` includes it.

## What is inside

| Namespace | Extension |
| --- | --- |
| `Nexus\Mcp\Extension\Tasks` | Long-running tool calls brokered into polled tasks (SEP-2663) |
| `Nexus\Mcp\Extension\Apps` | `ui://` views linked to tools (SEP-1865) |
| `Nexus\Mcp\Extension\Auth` | Client credentials (SEP-1046) and enterprise-managed authorization (SEP-990) |

Each extension keeps its capability identifier and protocol literals on a vocabulary class (`Tasks`,
`Apps`, `Auth\ClientCredentials\ClientCredentials`, `Auth\Enterprise\EnterpriseAuthorization`), with
`Server` and `Client` subnamespaces for the two halves.

## Documentation

- Server guides: [Extensions](https://nexusphp.github.io/mcp/server/extensions/),
  [Tasks](https://nexusphp.github.io/mcp/server/tasks/), [Apps](https://nexusphp.github.io/mcp/server/apps/).
- Client guides: [Extensions](https://nexusphp.github.io/mcp/client/extensions/),
  [Tasks](https://nexusphp.github.io/mcp/client/tasks/), [Apps](https://nexusphp.github.io/mcp/client/apps/).
- [OAuth extension grants](https://nexusphp.github.io/mcp/auth/extension-grants/).
- [API reference](https://nexusphp.github.io/mcp/api/).
- [Changelog](https://github.com/NexusPHP/mcp/blob/1.x/CHANGELOG.md) and
  [versioning policy](https://github.com/NexusPHP/mcp/blob/1.x/VERSIONING.md), shared by every component.

## License

Released under the [MIT License](LICENSE).
