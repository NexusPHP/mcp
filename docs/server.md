# Server API

The `Server` class runs against a `TransportInterface`. It blocks the caller until the transport closes. Build one
with the fluent `ServerBuilder`, then run it against any transport implementation.

```php
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\Transport\StdioServerTransport;

$server = (new ServerBuilder())
    ->setServerInfo(name: 'my-server', version: '1.0.0')
    // ... register features ...
    ->build()
;

$server->run(new StdioServerTransport());
```

`Server::run()` returns when the transport closes. For the stdio transport, that is EOF on stdin. A request-scoped
transport such as [Streamable HTTP](transports/streamable-http.md#streamablehttpservertransport) uses
`Server::listen()` instead. That method attaches the dispatcher and returns, so the HTTP host keeps driving the
loop.

## Guide

- **[Server configuration](server/configuration.md)**: server info and its disclosure, instructions, the logger,
  and the in-flight dispatch cap.
- **[Tools](server/tools.md)**: registering tools, structured content, and schema validation.
- **[Prompts](server/prompts.md)**: registering prompt renderers.
- **[Resources](server/resources.md)**: static and templated resources, and the cache hints every read and list
  result carries.
- **[Completions](server/completions.md)**: serving `completion/complete` from a completion store.
- **[Stores and pagination](server/stores.md)**: page size, custom store implementations, and runtime mutation.
- **[Custom handlers](server/handlers.md)**: vendor-extension methods and spec-method overrides.
- **[Extensions](server/extensions.md)**: enabling SEP-2133 extensions and the declared-capability gate on their
  methods.
- **[Tasks](server/tasks.md)**: the SEP-2663 tasks extension. It brokers tool calls into polled long-running tasks.
- **[Apps](server/apps.md)**: the SEP-1865 MCP Apps extension. It declares `ui://` view resources and links tools to
  them.
- **[Capability advertisement](server/capabilities.md)**: how `ServerCapabilities` is derived from what you
  registered.
- **[Subscriptions](server/subscriptions.md)**: serving `subscriptions/listen` streams and the list-changed
  notifications.
- **[ServerContext](server/context.md)**: what every handler receives.
- **[Asking the client for input](server/input-required.md)**: the `InputRequiredResult` flow and elicitation.

You can also declare tools, prompts, resources, completions, and the server identity with attributes. Mark a plain
object with `#[AsTool]`, `#[AsPrompt]`, `#[AsResource]`, `#[AsResourceTemplate]`, `#[AsCompletion]`, or
`#[AsServer]`, then register it in one call with `ServerBuilder::register()`. See
[Attribute discovery](attribute-discovery.md) for the full reference.

## Lifecycle

### Build

`build()` validates the configuration and returns a `Server` instance. For example, the server info must be set.

### Run

`run($transport)` registers the listener chain on the transport and starts it. The call blocks until the transport
closes.

### Dispatch

While the server runs, the dispatcher classifies each inbound envelope and routes it to its handler. The protocol
is stateless, so every request dispatches immediately. The dispatcher reads the client's identity and capabilities
from the request's `_meta`.

A request for an unregistered method gets a `MethodNotFound` error. A malformed envelope gets an `InvalidRequest`
or a `ParseError` error.

### Shutdown

The transport signals shutdown when it closes. For stdio, that is EOF on stdin. The dispatcher drains the in-flight
coroutines before the transport's close listeners fire, so responses already in flight are flushed before the
process exits. On an event-loop HTTP host, call `close()` on the transport *before* stopping the HTTP server:
closing ends every open `subscriptions/listen` stream, and the HTTP server's own stop waits for those responses to
finish, so the reverse order deadlocks with a stream open.

## See also

- **[Getting started](getting-started.md)**: install and a minimal server.
- **[Attribute discovery](attribute-discovery.md)**: declaring features with attributes.
- **[Client API](client.md)**: the other side of the connection.
- **[Transports](transports.md)**: stdio, Streamable HTTP, and the transport contract.
- **[Authorization](authorization.md)**: protecting a Streamable HTTP server with OAuth 2.1.
- **[Error handling](error-handling.md)**: how failures surface on both sides.
