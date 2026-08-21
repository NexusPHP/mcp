# Getting started

This tutorial takes you from an empty directory to a working pair. You build a stdio MCP server that exposes one
tool, and a PHP client that spawns the server and calls that tool. You can copy and run every step.

## Requirements

- PHP 8.3 or newer.
- [Composer](https://getcomposer.org).

## Install

```bash
composer require nexusphp/mcp
```

The SDK targets MCP spec **2026-07-28**. It runs on [AMPHP](https://amphp.org) and [Revolt](https://revolt.run),
so its synchronous-looking API is fiber-driven. You need no further setup to build a server.

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

$server = (new ServerBuilder())
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

That is a full, runnable MCP server. It advertises one tool, `greet`. It exposes the `server/discover`,
`tools/list`, and `tools/call` handlers the SDK ships by default. It speaks line-framed JSON-RPC over STDIN and
STDOUT.

## Run it

### MCP Inspector (recommended)

[MCP Inspector](https://github.com/modelcontextprotocol/inspector) gives you an interactive UI for tools, prompts,
and resources. You write no client:

```bash
npx @modelcontextprotocol/inspector php hello.php
```

Open the URL it prints. Click **Connect**, then invoke `greet` from the Tools panel.

### Claude Desktop, Cursor, or any `mcpServers`-aware client

Add this to the client's MCP configuration:

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

Every request carries a `_meta` block with the client's identity. The keys `io.modelcontextprotocol/protocolVersion`
and `io.modelcontextprotocol/clientCapabilities` are required. The key `io.modelcontextprotocol/clientInfo` is
optional. The server reads one JSON-RPC envelope per line on STDIN and writes responses to STDOUT. This is useful
for scripted smoke tests, and less useful for interactive exploration.

## Your first MCP client

The client ships in the same package. Create `hello-client.php` next to `hello.php`:

```php
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Client\Transport\StdioClientTransport;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;

$client = (new ClientBuilder())
    ->setClientInfo(name: 'hello-client', version: '0.1.0')
    ->build()
;

$client->connect(new StdioClientTransport(command: [PHP_BINARY, __DIR__.'/hello.php']));

try {
    $client->discover();

    $result = $client->callTool(name: 'greet', arguments: ['name' => 'Ada']);

    if ($result instanceof CallToolResult) {
        foreach ($result->content as $block) {
            if ($block instanceof TextContent) {
                echo $block->text, PHP_EOL;
            }
        }
    }
} finally {
    $client->disconnect();
}
```

`StdioClientTransport` spawns the server as a subprocess. It speaks the same line-framed JSON-RPC over the
subprocess's STDIN and STDOUT.

`discover()` sends `server/discover`. The client learns the server's identity and records its capabilities, which
the typed request methods check before they send. The protocol is sessionless, so this call establishes nothing.
It is a plain request, and any other request may precede it.

`callTool()` answers with a typed result. The union includes `InputRequiredResult`, which
[When the server asks for input first](client/input-required.md) covers. Branch on the type. Do not assume the
happy path.

```bash
php hello-client.php
```

This prints `Hello, Ada!`. For a client and server in one process with no subprocess, see
[examples/in-memory.php](../examples/in-memory.php).

## Logging

MCP servers MUST NOT write to STDOUT outside of the JSON-RPC stream. The SDK uses
[PSR-3](https://www.php-fig.org/psr/psr-3/) for diagnostic logging. It writes nothing by default, because it uses a
`NullLogger` unless you provide one. Pass a real logger with `ServerBuilder::setLogger()` and target STDERR. See
[examples/stdio-server.php](../examples/stdio-server.php) for a worked example that routes server diagnostics to a
PSR-3 logger on STDERR.

## Next steps

- **[Server API](server.md)**: the full `ServerBuilder` reference. It covers tools, prompts, resources, completions,
  custom request handlers, and the request and notification lifecycle.
- **[Client API](client.md)**: the `ClientBuilder` and `Client` reference. It covers `server/discover`, the typed
  request methods, and streaming progress from `callTool`.
- **[Transports](transports.md)**: what each transport guarantees, and what it does not.
- **[Error handling](error-handling.md)**: the exception model, the JSON-RPC error codes, and which calls throw what.
- **[Best practices](best-practices.md)**: conventions for servers and clients.
- **[Architecture](architecture.md)**: namespacing, layering rules, and the dispatch kernel.
- **[Design rationale](design-rationale.md)**: the choices behind the SDK.
- **[examples/](../examples/)**: runnable demo servers and clients over stdio, in-memory, and HTTP.
