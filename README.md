# Nexus MCP SDK

[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.4-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![Latest Stable Version](https://img.shields.io/packagist/v/nexusphp/mcp)](https://packagist.org/packages/nexusphp/mcp)
[![Unit Tests](https://github.com/NexusPHP/mcp/actions/workflows/unit-tests.yml/badge.svg)](https://github.com/NexusPHP/mcp/actions/workflows/unit-tests.yml)
[![Static analysis](https://github.com/NexusPHP/mcp/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/NexusPHP/mcp/actions/workflows/static-analysis.yml)
[![Code style](https://github.com/NexusPHP/mcp/actions/workflows/code-style.yml/badge.svg)](https://github.com/NexusPHP/mcp/actions/workflows/code-style.yml)
[![Mutation score](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2FNexusPHP%2Fmcp%2F1.x)](https://dashboard.stryker-mutator.io/reports/github.com/NexusPHP/mcp/1.x)
[![License](https://img.shields.io/github/license/NexusPHP/mcp)](LICENSE)

> [!IMPORTANT]
> Pre-v1.0.0. Through `0.x` the project ships the single umbrella package
> `nexusphp/mcp`, and minor releases may carry breaking changes until `1.0.0`. The stdio transport
> is implemented. Streamable HTTP lands with the 2026-07-28 migration.

A PHP SDK for the [Model Context Protocol (MCP)](https://modelcontextprotocol.io/docs/getting-started/intro),
tracking spec revision **2026-07-28**. It provides both sides of an MCP session: a server for exposing
tools, resources, and prompts, and a client for connecting to MCP servers over a transport.

This SDK is architected independently of the official PHP MCP SDK. See [ROADMAP.md](ROADMAP.md) for
direction and the path to the 2026-07-28 spec migration.

## Requirements

- PHP 8.4 or newer
- [Composer](https://getcomposer.org)

## Installation

```bash
composer require nexusphp/mcp
```

The SDK runs on [AMPHP](https://amphp.org) and [Revolt](https://revolt.run). Its synchronous-looking
API is driven by fibers under the hood.

## Quickstart

A minimal stdio server exposing one tool:

```php
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Transport\StdioServerTransport;

$server = new ServerBuilder()
    ->setServerInfo(name: 'hello', version: '0.1.0')
    ->addTool(
        tool: new Tool(
            name: 'greet',
            inputSchema: [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'required' => ['name'],
            ],
            description: 'Greets the named person.',
        ),
        executor: static function (?array $args, ServerContext $context): CallToolResult {
            $name = is_string($args['name'] ?? null) ? $args['name'] : 'stranger';

            return new CallToolResult([new TextContent(text: sprintf('Hello, %s!', $name))]);
        },
    )
    ->build()
;

$server->run(new StdioServerTransport());
```

Run it through [MCP Inspector](https://github.com/modelcontextprotocol/inspector):

```bash
npx @modelcontextprotocol/inspector php hello.php
```

See [Getting started](docs/getting-started.md) for the client side and a full walkthrough.

## Documentation

- [Getting started](docs/getting-started.md): install plus a minimal server and client.
- [Server API](docs/server.md): `ServerBuilder` reference (tools, prompts, resources, completions,
  handlers).
- [Attribute discovery](docs/attribute-discovery.md): declare features with `#[AsTool]`, `#[AsServer]`, and
  friends, registered via `ServerBuilder::register()`.
- [Client API](docs/client.md): `ClientBuilder` and `Client` reference (handshake, typed requests,
  streaming progress).
- [Transports](docs/transports.md): the stdio transport and the in-memory paired transport.
- [Error handling](docs/error-handling.md): the exception model and JSON-RPC error codes.
- [Best practices](docs/best-practices.md): conventions the SDK is shaped to reward.
- [Architecture](docs/architecture.md): layering, dispatch kernel, spec-compliance notes.
- [Design rationale](docs/design-rationale.md): why the SDK is shaped this way.
- [API reference](https://nexusphp.github.io/mcp-sdk/): the generated class-level reference for the public
  `Nexus\Mcp\` API, published to GitHub Pages.
- [Examples](examples/): runnable demo server and client.

## Development

```bash
composer update          # install dependencies
composer test:all        # full gate suite (style, static analysis, docs, tests, mutation)
composer test:unit       # unit tests only
composer cs:fix          # fix code style
composer phpstan:check   # static analysis (PHPStan level 10)
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full workflow.

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) and the
[Code of Conduct](CODE_OF_CONDUCT.md). To report a security issue, see [SECURITY.md](SECURITY.md).

## License

Released under the [MIT License](LICENSE).
