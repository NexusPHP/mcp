# Nexus MCP SDK: Core

[![PHP](http://poser.pugx.org/nexusphp/mcp-core/require/php)](https://packagist.org/packages/nexusphp/mcp-core)
[![Latest Stable Version](http://poser.pugx.org/nexusphp/mcp-core/v)](https://packagist.org/packages/nexusphp/mcp-core)
[![License](https://img.shields.io/github/license/NexusPHP/mcp)](LICENSE)

The protocol foundation shared by the server and client halves of the
[Nexus MCP SDK](https://github.com/NexusPHP/mcp): the MCP schema types, the JSON-RPC envelope,
the transport contract, and the dispatch kernel both sides build on.

> [!IMPORTANT]
> This repository is a read-only subtree split of [NexusPHP/mcp](https://github.com/NexusPHP/mcp).
> Open issues and pull requests there. Anything opened here is closed automatically with the same pointer.

## Installation

```bash
composer require nexusphp/mcp-core
```

Most projects want a whole side rather than the foundation alone: `nexusphp/mcp-server` and
`nexusphp/mcp-client` both pull this package in, and the umbrella `nexusphp/mcp` ships everything.

## What is inside

| Namespace | Contents |
| --- | --- |
| `Nexus\Mcp\Core\Schema` | Every spec-defined object, request, notification, and result as a readonly value object or enum |
| `Nexus\Mcp\Core\JsonRpc` | Envelope parsing, the method registry, and the JSON-RPC error codes |
| `Nexus\Mcp\Core\Transport` | The `TransportInterface` contract, line-delimited duplex helpers, and `InMemoryTransport` |
| `Nexus\Mcp\Core\Dispatch` | Request correlation and the coroutine bookkeeping shared by both sides |
| `Nexus\Mcp\Core\Handler` | The request and notification handler contracts |
| `Nexus\Mcp\Core\Extension` | The SEP-2133 extensions primitive |
| `Nexus\Mcp\Core\Auth` and `Nexus\Mcp\Core\Http` | OAuth metadata documents and the HTTP vocabulary shared with the transports |
| `Nexus\Mcp\Core\UriTemplate` and `Nexus\Mcp\Core\Validation` | RFC 6570 templates and the runtime guards |
| `Nexus\Mcp\Core\Exception` | The `McpExceptionInterface` family |

## Documentation

- [Guides](https://nexusphp.github.io/mcp/), starting with [Architecture](https://nexusphp.github.io/mcp/architecture/)
  and [Spec compliance](https://nexusphp.github.io/mcp/spec-compliance/).
- [API reference](https://nexusphp.github.io/mcp/api/).
- [Changelog](https://github.com/NexusPHP/mcp/blob/1.x/CHANGELOG.md) and
  [versioning policy](https://github.com/NexusPHP/mcp/blob/1.x/VERSIONING.md), shared by every component.

## License

Released under the [MIT License](LICENSE).
