# Server API

The `Server` class runs against a `TransportInterface` and blocks the caller until the transport closes.
Build one with the fluent `ServerBuilder` and run it against any transport implementation.

```php
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\Transport\StdioServerTransport;

$server = new ServerBuilder()
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
    description: 'A short description the server advertises via server/discover.',
    websiteUrl: 'https://example.com',
)
```

## Instructions

Optional. Advertised to the client via `server/discover`. Use it to give models guidance about how to use
the server.

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

        return new CallToolResult(content: [new TextContent(text: "Results for {$query}")]);
    },
)
```

The executor can be either a `\Closure` or a class implementing `ToolExecutorInterface`. Registering at
least one tool advertises the `tools` capability automatically.

Runtime exceptions thrown out of a tool executor are converted into a `CallToolResult` with `isError: true`
and a single `TextContent` carrying `"Tool execution failed."`. The underlying throwable is logged at `error`
level on the PSR-3 logger configured via `ServerBuilder::setLogger()`. To surface error detail to the LLM,
return `new CallToolResult(content: [...], isError: true)` from the executor instead of throwing.
Protocol-level conditions (`ToolNotFoundException`, etc.) still surface as JSON-RPC errors.

> [!WARNING]
> The generic-text wrap above only covers the `\Throwable` arm. Messages thrown via
> `AbstractJsonRpcProtocolException` subclasses (`InvalidParamsException` and similar) are surfaced
> **verbatim** in the JSON-RPC `error.message` field. Keep those strings free of paths, credentials,
> connection strings, and any other sensitive data. The recommended pattern for surfacing tool errors is
> `return new CallToolResult(content: [...], isError: true)`, not throwing a protocol exception.

### Structured content

A tool may return a `structuredContent` object instead of, or alongside, its `content` blocks:

```php
return new CallToolResult(
    content: [],
    structuredContent: ['temperature' => 22.5, 'unit' => 'celsius'],
);
```

For backwards compatibility, the spec recommends that a tool returning `structuredContent` also return
the serialised JSON in a `TextContent` block. When the executor leaves `content` empty, the handler adds
that block for you. Provide your own `content` to keep control of the text representation. A non-empty
`content` list is passed through untouched.

### Schema validation

A tool call is validated against the tool's schemas on the way in and on the way out:

- The call `arguments` are validated against the tool's `inputSchema`. A non-conforming payload fails the
  call with a JSON-RPC `InvalidParams` error before the executor runs.
- When a (non-error) result carries `structuredContent` and the tool declares an `outputSchema`, the
  result is validated against that schema. A non-conforming result is logged server-side and surfaced to
  the client as a generic error result, so malformed structured data is never sent.

Validation is backed by [opis/json-schema](https://github.com/opis/json-schema) (JSON Schema draft
2020-12) by default. Supply your own engine by implementing `SchemaValidatorInterface` and registering it
with `ServerBuilder::setSchemaValidator()`.

```php
use Nexus\Mcp\Server\Validation\SchemaValidatorInterface;

$server = new ServerBuilder()
    ->setSchemaValidator($myValidator) // any SchemaValidatorInterface
    // ...
    ->build()
;
```

## Prompts

```php
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;

->addPrompt(
    prompt: new Prompt(name: 'summarise', description: 'Summarises the user input.'),
    renderer: static fn(?array $args, ServerContext $context): GetPromptResult => new GetPromptResult(messages: [
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
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;

->addResource(
    resource: new Resource(name: 'config', uri: 'config://app.toml', mimeType: 'application/toml'),
    reader: static fn(string $uri, ServerContext $context): ReadResourceResult => new ReadResourceResult(
        contents: [new TextResourceContents(uri: $uri, text: file_get_contents('/etc/app.toml'))],
        ttlMs: 60_000,
        cacheScope: CacheScope::Public,
    ),
)
```

URI templates ([RFC 6570](https://datatracker.ietf.org/doc/html/rfc6570)):

```php
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;

->addResourceTemplate(
    template: new ResourceTemplate(name: 'user', uriTemplate: 'users://{userId}'),
    reader: static fn(string $uri, array $vars, ServerContext $context): ReadResourceResult => new ReadResourceResult(
        contents: [new TextResourceContents(uri: $uri, text: loadUser($vars['userId']))],
        ttlMs: 0,
        cacheScope: CacheScope::Private,
    ),
)
```

`$vars` carries the resolved template variables (`['userId' => '123']` for `users://123`). The reader can
be a `\Closure` or a `ResourceReaderInterface` / `TemplatedResourceReaderInterface`.

### Cache hints

`ReadResourceResult` and the `*/list` results require two cache hints the server returns to the client:
`ttlMs` (how many milliseconds the client MAY treat the response as fresh, `0` meaning re-fetch every time)
and `cacheScope` (`CacheScope::Public` for a response any shared cache MAY serve to any user, or
`CacheScope::Private` for one only the requesting user's client MAY cache). The built-in stores emit
`ttlMs: 0` / `CacheScope::Private` by default and accept `ttlMs:` / `cacheScope:` constructor arguments to
advertise a longer TTL on their `*/list` results:

```php
->setToolStore(new ToolStore($entries, ttlMs: 60_000, cacheScope: CacheScope::Public))
```

## Custom stores

Completions are served entirely by a store you provide:

```php
->setCompletionStore(new MyCompletionStore())
```

The store implements `CompletionStoreInterface` and is consulted on `completion/complete` requests.
Registering it advertises the `completions` capability.

The in-memory stores that back tools, prompts, resources, and resource templates can likewise be swapped for
a custom implementation. A setter replaces the store the builder would otherwise assemble from the matching
`add*()` entries, so registering a store also lights up that feature's capability.

```php
->setToolStore(new MyToolStore())                         // ToolStoreInterface
->setPromptStore(new MyPromptStore())                     // PromptStoreInterface
->setResourceStore(new MyResourceStore())                 // ResourceStoreInterface
->setResourceTemplateStore(new MyResourceTemplateStore()) // ResourceTemplateStoreInterface
```

Each store implements the read surface its built-in handlers depend on (`list()` plus `call()` / `get()` /
`read()`). When a custom store and the matching `add*()` entries are both supplied, the custom store wins
and those entries are ignored. A custom resource store still composes with a resource template store (custom
or entry-built) for `resources/read`.

## Custom request and notification handlers

For vendor-extension methods (those outside the MCP spec):

```php
->addRequestHandler('acme/lookup', new MyLookupHandler())
->addNotificationHandler('acme/heartbeat', new MyHeartbeatHandler())
```

Both reject spec-reserved methods. To override the SDK's built-in handler for a spec method
(e.g. to take over `tools/list`), use the `replace*` variants:

```php
->replaceRequestHandler('tools/list', new MyListToolsHandler())
->replaceNotificationHandler('notifications/cancelled', new MyCancelledHandler())
```

The `replace*` variants in turn reject non-spec methods, so each tool steers vendor extensions
and spec overrides to the correct entry point.

## Attribute discovery

Tools, prompts, resources, and the server identity can also be declared with attributes (`#[AsTool]`,
`#[AsPrompt]`, `#[AsResource]`, `#[AsResourceTemplate]`, `#[AsServer]`) on a plain object and registered in
one call with `ServerBuilder::register()`. See [Attribute discovery](attribute-discovery.md) for the full
reference.

## Capability advertisement

`ServerCapabilities` is derived automatically from what you registered.

| Capability slot | Lit up by |
| --- | --- |
| `tools` | At least one `addTool(...)`, `setToolStore(...)`, or both `tools/list` and `tools/call` `replaceRequestHandler(...)`. |
| `prompts` | At least one `addPrompt(...)`, `setPromptStore(...)`, or both `prompts/list` and `prompts/get` `replaceRequestHandler(...)`. |
| `resources` | At least one `addResource(...)` / `addResourceTemplate(...)`, `setResourceStore(...)` / `setResourceTemplateStore(...)`, or both `resources/list` and `resources/read` `replaceRequestHandler(...)`. |
| `completions` | `setCompletionStore(...)`, or `completion/complete` `replaceRequestHandler(...)`. |
| `logging` | Always advertised. `$context->log()` emits `notifications/message`, filtered by the gate's minimum level. |

`listChanged` is not advertised on any slot. The per-feature stores are immutable after `build()` returns,
so there is nothing to notify the client about.

## What `ServerContext` exposes to a handler

Every handler closure receives a `ServerContext` as its last argument.

| Property / method | Purpose |
| --- | --- |
| `$context->requestId` | The originating `RequestId`. |
| `$context->cancellation` | An `Amp\Cancellation` token. Pass it to any `await()` so client `notifications/cancelled` can interrupt long-running work. |
| `$context->meta` | The request's `_meta` object: the client's `protocolVersion`, `clientInfo`, and `clientCapabilities`, plus `progressToken`. Read client capabilities per request, never inferred from a prior one. |
| `$context->sessionId` | The transport's session id, if any. `null` for stdio. |
| `$context->log($level, $data, $logger = null)` | Emits a `notifications/message`. Dropped if below the gate's minimum level (default: `info`). |
| `$context->reportProgress($progress, $total, $message)` | Emits a `notifications/progress` if the original request carried a `progressToken`. |

## Lifecycle

1. **`build()`** validates the configuration (e.g. server info must be set) and returns a `Server` instance.
2. **`run($transport)`** registers the listener chain on the transport, starts it, and blocks until the
   transport closes.
3. While running, the dispatcher classifies each inbound envelope and routes it straight to its handler.
   The protocol is stateless, so every request dispatches immediately, with the client's identity and
   capabilities read from the request's `_meta`. Requests for an unregistered method get a `MethodNotFound`
   error, and malformed envelopes get an `InvalidRequest` or `ParseError` error.
4. **Shutdown** is signalled by the transport closing (e.g. stdin EOF). The dispatcher drains in-flight
   coroutines before the transport's close listeners fire, so responses already in flight are flushed
   before the process exits.

## See also

- **[Getting started](getting-started.md)**: install + minimal server.
- **[Attribute discovery](attribute-discovery.md)**: declare features with `#[AsTool]` and friends.
- **[Client API](client.md)**: the symmetric client-side reference.
- **[Transports](transports.md)**: `StdioServerTransport` contract.
- **[Architecture](architecture.md)**: dispatch kernel, layering, spec compliance.
