# Getting started

## Requirements

- PHP 8.4 or newer.
- [Composer](https://getcomposer.org).

## Install

```bash
composer require nexusphp/mcp-sdk
```

The SDK targets MCP spec **2026-07-28** and runs on [AMPHP](https://amphp.org) and
[Revolt](https://revolt.run), so its synchronous-looking API is fiber-driven. No further setup is needed
to build a server.

## Your first MCP server

Create `hello.php`:

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
                'properties' => [
                    'name' => ['type' => 'string'],
                ],
                'required' => ['name'],
            ],
            description: 'Greets the named person.',
        ),
        executor: static function (?array $args, ServerContext $context): CallToolResult {
            $name = is_string($args['name'] ?? null) ? $args['name'] : 'stranger';

            return new CallToolResult(content: [new TextContent(text: sprintf('Hello, %s!', $name))]);
        },
    )
    ->build()
;

$server->run(new StdioServerTransport());
```

That is a full, runnable MCP server. It advertises one tool (`greet`), exposes the `server/discover` /
`tools/list` / `tools/call` handlers the SDK ships by default, and speaks line-framed JSON-RPC over
STDIN/STDOUT.

## Run it

### MCP Inspector (recommended)

[MCP Inspector](https://github.com/modelcontextprotocol/inspector) gives you an interactive UI for poking
at tools, prompts, and resources without writing a client:

```bash
npx @modelcontextprotocol/inspector php hello.php
```

Open the URL it prints, click **Connect**, then invoke `greet` from the Tools panel.

### Claude Desktop, Cursor, or any `mcpServers`-aware client

Drop this into the client's MCP configuration:

```json
{
    "mcpServers": {
        "hello": {
            "command": "php",
            "args": ["/absolute/path/to/hello.php"]
        }
    }
}
```

### Direct CLI

```bash
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientInfo":{"name":"cli","version":"1.0.0"},"io.modelcontextprotocol/clientCapabilities":{}}}}' | php hello.php
```

Every request carries a `_meta` block with the client's identity: `io.modelcontextprotocol/protocolVersion`
and `io.modelcontextprotocol/clientCapabilities` are required, `io.modelcontextprotocol/clientInfo` is
optional. The server reads JSON-RPC
envelopes one per line on STDIN and writes responses to STDOUT. Useful for scripting smoke tests. Less useful for
interactive exploration.

## Logging

MCP servers MUST NOT write to STDOUT outside of the JSON-RPC stream. The SDK uses
[PSR-3](https://www.php-fig.org/psr/psr-3/) for diagnostic logging and writes nothing by default (a
`NullLogger` is used unless you provide one). Pass a real logger via `ServerBuilder::setLogger()` and
target STDERR. See [examples/stdio-server.php](../examples/stdio-server.php) for a worked example that
routes server diagnostics to a PSR-3 logger on STDERR.

## Next steps

- **[Server API](server.md)**: full `ServerBuilder` reference covering tools, prompts, resources,
  completions, custom request handlers, and the request/notification lifecycle.
- **[Client API](client.md)**: `ClientBuilder` + `Client` reference covering `server/discover`, the typed
  request methods, and streaming progress from `callTool`.
- **[Transports](transports.md)**: what each transport does and doesn't guarantee, stdio and Streamable
  HTTP alike.
- **[Error handling](error-handling.md)**: the exception model, JSON-RPC error codes, and which calls throw what.
- **[Best practices](best-practices.md)**: conventions for servers and clients.
- **[Architecture](architecture.md)**: namespacing, layering rules, dispatch kernel, spec-compliance notes.
- **[Design rationale](design-rationale.md)**: the choices behind the SDK.
- **[examples/](../examples/)**: runnable demo servers.
