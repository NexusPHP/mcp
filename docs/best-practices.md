# Best practices

Practical guidance for building servers and clients with the SDK. These are conventions the SDK is shaped
to reward, not hard requirements.

## Server

**Advertise capabilities honestly.** `ServerBuilder` derives the advertised `ServerCapabilities` from what
you register: adding tools advertises `tools`, a completion store advertises `completions`, and so on. Do
not hand-advertise a capability whose notifications you cannot deliver. A client tolerates an absent
capability but breaks when a declared one stays silent, which is why the SDK does not advertise
`listChanged` for the immutable-after-build stores.

**Keep STDOUT for the protocol.** A stdio server MUST NOT write anything to STDOUT except the JSON-RPC
stream. Send all diagnostics to STDERR through the PSR-3 logger you pass to the builder. The examples'
`ExampleLogger` ([examples/ExampleLogger.php](../examples/ExampleLogger.php)) does this and follows the
client's `logging/setLevel` requests.

**Signal tool failures with `isError`, not exceptions.** A tool that fails for a domain reason (bad input
the schema allowed, an upstream timeout) should return a `CallToolResult` with `isError: true` and a
descriptive content block. Reserve thrown exceptions for protocol-level faults; the dispatcher turns an
uncaught handler throwable into a generic `-32603` so internal details never leak. See
[error handling](error-handling.md).

**Lean on schema validation.** Give tools an `inputSchema` (and an `outputSchema` when they return
`structuredContent`). The SDK validates arguments before your executor runs and validates structured
results after, so your handler can assume well-formed input.

**Register everything before `run()`.** Tools, prompts, resources, handlers, and the logger all register
on the builder; the built `Server` is immutable. There is no runtime registration, so compose fully, then
call `Server::run()`.

## Client

**Follow the lifecycle: `connect()`, `initialize()`, then calls.** Typed methods throw
`ClientNotConnectedException` / `ClientNotInitializedException` if called out of order. Always pair
`connect()` with a `disconnect()` in a `finally` so the transport closes even when a call throws:

```php
$client->connect($transport);

try {
    $client->initialize();
    // ... typed calls ...
} finally {
    $client->disconnect();
}
```

**Degrade gracefully on missing capabilities.** Before relying on an optional capability, check
`getServerCapabilities()` or catch `ServerCapabilityNotSupportedException`. The client gates each typed
request on what the server advertised, so a `complete()` against a server without completions fails before
sending. See [examples/capability-aware-client.php](../examples/capability-aware-client.php).

**Stream progress for long tools.** Pass an `onProgress` callback to `callTool()` to receive
`notifications/progress` while the call is in flight, and register a `LoggingMessageNotification` handler
to surface the server's log notifications.

**Mind that requests currently have no timeout.** A request to a hung or slow server blocks the calling
fiber until the transport closes. Per-request timeouts are a planned addition (see [ROADMAP.md](../ROADMAP.md));
until then, close the transport to unblock a stuck call.

## Both sides

**Catch `McpExceptionInterface`.** A single catch traps every SDK-originated failure. Narrow to specific
exceptions only where you act on them differently. See [error handling](error-handling.md).

**Pass a real PSR-3 logger.** Both `ServerBuilder` and `ClientBuilder` accept a logger and default to
`NullLogger`. Passing a logger surfaces transport and dispatch diagnostics, which the SDK keeps off STDOUT.

## See also

- **[Getting started](getting-started.md)**: minimal server and client.
- **[Server API](server.md)** and **[Client API](client.md)**: the full builder and method surface.
- **[Error handling](error-handling.md)**: the exception model and JSON-RPC error codes.
- **[Design rationale](design-rationale.md)**: why the SDK is shaped this way.
