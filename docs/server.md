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

`Server::run()` returns when the transport closes (stdin EOF for the stdio transport). A request-scoped
transport such as [Streamable HTTP](transports.md#streamablehttpservertransport) uses `Server::listen()`
instead, which attaches the dispatcher and returns so the HTTP host keeps driving the loop.

## Guide

- **[Server configuration](server/configuration.md)**: server info and its disclosure, instructions, the
  logger, and the in-flight dispatch cap.
- **[Tools](server/tools.md)**: registering tools, structured content, and schema validation.
- **[Prompts](server/prompts.md)**: registering prompt renderers.
- **[Resources](server/resources.md)**: static and templated resources, and the cache hints every read
  and list result carries.
- **[Completions](server/completions.md)**: serving `completion/complete` from a completion store.
- **[Stores and pagination](server/stores.md)**: page size, custom store implementations, and runtime
  mutation.
- **[Custom handlers](server/handlers.md)**: vendor-extension methods and spec-method overrides.
- **[Extensions](server/extensions.md)**: enabling SEP-2133 extensions and the declared-capability
  gate on their methods.
- **[Tasks](server/tasks.md)**: the SEP-2663 tasks extension, brokering tool calls into polled
  long-running tasks.
- **[Capability advertisement](server/capabilities.md)**: how `ServerCapabilities` is derived from what
  you registered.
- **[Subscriptions](server/subscriptions.md)**: serving `subscriptions/listen` streams and the
  list-changed notifications.
- **[ServerContext](server/context.md)**: what every handler receives.
- **[Asking the client for input](server/input-required.md)**: the `InputRequiredResult` flow and
  elicitation.

Tools, prompts, resources, completions, and the server identity can also be declared with attributes
(`#[AsTool]`, `#[AsPrompt]`, `#[AsResource]`, `#[AsResourceTemplate]`, `#[AsCompletion]`, `#[AsServer]`) on
a plain object and registered in one call with `ServerBuilder::register()`. See
[Attribute discovery](attribute-discovery.md) for the full reference.

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
- **[Attribute discovery](attribute-discovery.md)**: declaring features with attributes.
- **[Client API](client.md)**: the other side of the connection.
- **[Transports](transports.md)**: stdio, Streamable HTTP, and the transport contract.
- **[Authorization](authorization.md)**: protecting a Streamable HTTP server with OAuth 2.1.
- **[Error handling](error-handling.md)**: how failures surface on both sides.
