# Server API

The `Server` class wires a `MessageDispatcher` to a `TransportInterface` and blocks the caller until the
transport closes. Build one with the fluent `ServerBuilder` and run it against any transport
implementation.

```php
use Nexus\Mcp\Server\Server;
use Nexus\Mcp\Server\Transport\StdioServerTransport;

$server = Server::builder()
    ->setServerInfo(name: 'my-server', version: '1.0.0')
    // ... register features ...
    ->build()
;

$server->run(new StdioServerTransport());
```

`Server::run()` returns when the transport closes (stdin EOF for the stdio transport).

## Server info

Required before `build()`.

```php
->setServerInfo(
    name: 'my-server',
    version: '1.0.0',
    title: 'My Friendly Server',
    description: 'A short description sent to the client during initialize.',
    websiteUrl: 'https://example.com',
)
```

## Instructions

Optional. Sent to the client on `initialize`. Use it to give models guidance about how to use the server.

```php
->setInstructions('Use the search_docs tool before answering questions about Nexus.')
```

## Logger

Optional. Defaults to `Psr\Log\NullLogger`. Logs go to whatever PSR-3 logger you provide. MCP servers MUST
NOT write to STDOUT outside of the JSON-RPC stream. Target STDERR or a file.

```php
->setLogger($psrLogger)
```

## Tools

```php
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\ServerContext;

->addTool(
    tool: new Tool(
        name: 'search_docs',
        inputSchema: [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string']],
            'required' => ['query'],
        ],
        description: 'Searches the docs index.',
    ),
    executor: static function (?array $args, ServerContext $context): CallToolResult {
        $query = is_string($args['query'] ?? null) ? $args['query'] : '';

        return new CallToolResult([new TextContent(text: "Results for {$query}")]);
    },
)
```

The executor can be either a `\Closure` or a class implementing `ToolExecutorInterface`. Registering at
least one tool advertises the `tools` capability automatically.

## Prompts

```php
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;

->addPrompt(
    prompt: new Prompt(name: 'summarise', description: 'Summarises the user input.'),
    renderer: static fn(?array $args, ServerContext $context): GetPromptResult => new GetPromptResult([
        new PromptMessage(
            role: Role::User,
            content: new TextContent(text: 'Summarise the following ...'),
        ),
    ]),
)
```

The renderer can be a `\Closure` or a `PromptRendererInterface`.

## Resources

Static URIs:

```php
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;

->addResource(
    resource: new Resource(name: 'config', uri: 'config://app.toml', mimeType: 'application/toml'),
    reader: static fn(string $uri, ServerContext $context): ReadResourceResult => new ReadResourceResult([
        new TextResourceContents(uri: $uri, text: file_get_contents('/etc/app.toml')),
    ]),
)
```

URI templates ([RFC 6570](https://datatracker.ietf.org/doc/html/rfc6570)):

```php
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;

->addResourceTemplate(
    template: new ResourceTemplate(name: 'user', uriTemplate: 'users://{userId}'),
    reader: static fn(string $uri, array $vars, ServerContext $context): ReadResourceResult => new ReadResourceResult([
        new TextResourceContents(uri: $uri, text: loadUser($vars['userId'])),
    ]),
)
```

`$vars` carries the resolved template variables (`['userId' => '123']` for `users://123`). The reader can
be a `\Closure` or a `ResourceReaderInterface` / `TemplatedResourceReaderInterface`.

## Completions

```php
->setCompletionStore(new MyCompletionStore())
```

The store implements `CompletionStoreInterface` and is consulted on `completion/complete` requests.
Registering a store advertises the `completions` capability.

## Custom request and notification handlers

For vendor-extension methods (those outside the MCP spec):

```php
->addRequestHandler('acme/lookup', new MyLookupHandler())
->addNotificationHandler('acme/heartbeat', new MyHeartbeatHandler())
```

Both reject spec-reserved methods. To override the SDK's built-in handler for a spec method
(e.g. to take over `logging/setLevel` or `ping`), use the `replace*` variants:

```php
->replaceRequestHandler('logging/setLevel', new MySetLevelHandler())
->replaceNotificationHandler('notifications/initialized', new MyInitializedHandler())
```

The `replace*` variants in turn reject non-spec methods, so each tool steers vendor extensions
and spec overrides to the correct entry point.

See [examples/stdio-server.php](../examples/stdio-server.php) for a worked example of
`replaceRequestHandler('logging/setLevel', ...)`.

## Capability advertisement

`ServerCapabilities` is derived automatically from what you registered.

| Capability slot | Lit up by |
| --- | --- |
| `tools` | At least one `addTool(...)`, or both `tools/list` and `tools/call` `replaceRequestHandler(...)`. |
| `prompts` | At least one `addPrompt(...)`, or both `prompts/list` and `prompts/get` `replaceRequestHandler(...)`. |
| `resources` | At least one `addResource(...)` or `addResourceTemplate(...)`, or both `resources/list` and `resources/read` `replaceRequestHandler(...)`. |
| `completions` | `setCompletionStore(...)`, or `completion/complete` `replaceRequestHandler(...)`. |
| `logging` | Always advertised. The SDK ships a default `logging/setLevel` handler so the capability is always honoured. |

`listChanged` is not advertised on any slot. The per-feature stores are immutable after `build()` returns,
so there is nothing to notify the client about.

## What `ServerContext` exposes to a handler

Every handler closure receives a `ServerContext` as its last argument.

| Property / method | Purpose |
| --- | --- |
| `$context->requestId` | The originating `RequestId`. |
| `$context->cancellation` | An `Amp\Cancellation` token. Pass it to any `await()` so client `notifications/cancelled` can interrupt long-running work. |
| `$context->meta` | The request's `_meta` object (`progressToken`, etc.). |
| `$context->sessionId` | The transport's session id, if any. `null` for stdio. |
| `$context->log($level, $data, $logger = null)` | Emits a `notifications/message`. Gated by the level the client set via `logging/setLevel` (default: `info`). |
| `$context->reportProgress($progress, $total, $message)` | Emits a `notifications/progress` if the original request carried a `progressToken`. |

## Lifecycle

1. **`build()`** validates the configuration (e.g. server info must be set) and returns a `Server` instance.
2. **`run($transport)`** wires the listener chain on the transport, starts it, and blocks on a
   `DeferredFuture` until the transport closes.
3. While running, the dispatcher classifies each inbound envelope:
   - `initialize` request: routed before initialization completes. Subsequent calls are rejected with
     `InvalidRequest`.
   - `notifications/initialized`: marks the gate ready.
   - All other requests and notifications: gated on the initialization state. Requests before init get an
     `InvalidRequest` error.
4. **Shutdown** is signalled by the transport closing (e.g. stdin EOF). The dispatcher drains in-flight
   coroutines before the transport's close listeners fire, so responses already in flight are flushed
   before the process exits.

## See also

- **[Getting started](getting-started.md)**: install + minimal server.
- **[Transports](transports.md)**: `StdioServerTransport` contract.
- **[Architecture](architecture.md)**: dispatch kernel, layering, spec compliance.
