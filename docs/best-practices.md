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
`PsrLogger` ([examples/PsrLogger.php](../examples/PsrLogger.php)) does this.

**Signal tool failures with `isError`, not exceptions.** A tool that fails for a domain reason (bad input
the schema allowed, an upstream timeout) should return a `CallToolResult` with `isError: true` and a
descriptive content block. Reserve thrown exceptions for protocol-level faults. The dispatcher turns an
uncaught handler throwable into a generic `-32603` so internal details never leak. See
[error handling](error-handling.md).

**Lean on schema validation.** Give tools an `inputSchema` (and an `outputSchema` when they return
`structuredContent`). The SDK validates arguments before your executor runs and validates structured
results after, so your handler can assume well-formed input.

**Register everything before `run()`.** Tools, prompts, resources, handlers, and the logger all register
on the builder. The built `Server` is immutable. There is no runtime registration, so compose fully, then
call `Server::run()`.

## Client

**Connect, then call.** Each request stands on its own, so typed calls can start as soon as `connect()`
returns. Typed methods
throw `LogicException` if called before `connect()`. Always pair `connect()` with a
`disconnect()` in a `finally` so the transport closes even when a call throws:

```php
$client->connect($transport);

try {
    // Optionally learn the server's identity and capabilities first.
    $client->discover();
    // ... typed calls ...
} finally {
    $client->disconnect();
}
```

**Degrade gracefully on missing capabilities.** Before relying on an optional capability, call `discover()`
then check `getServerCapabilities()`, or catch `ServerCapabilityNotSupportedException`. Once discovery has
run, the client gates each typed request on what that server advertised, so a `complete()` against a server
without completions fails before sending. A `disconnect()` forgets the advertisement, so a reconnected
client is ungated until it discovers again. See
[examples/capability-aware-client.php](../examples/capability-aware-client.php).

**Stream progress for long tools.** Pass an `onProgress` callback to `callTool()` to receive
`notifications/progress` while the call is in flight.

**Bound the calls that need a different deadline.** Every request already carries one: `setRequestTimeout()`
defaults to 60 seconds of silence, restarted by each progress notification, and `setMaxRequestTimeout()` caps
the total. A call that legitimately runs long takes a per-request override, `sendRequest($request, $response,
timeout: 900.0)`, rather than a wider default for everything. A lapsed deadline raises
`RequestTimeoutException` and tells the server to stop working on the request. See
[request timeouts](client/progress-and-timeouts.md#request-timeouts).

## Both sides

**Catch `McpExceptionInterface`.** A single catch traps every SDK-originated failure. Narrow to specific
exceptions only where you act on them differently. See [error handling](error-handling.md).

**Pass a real PSR-3 logger.** Both `ServerBuilder` and `ClientBuilder` accept a logger and default to
`NullLogger`. Passing a logger surfaces transport and dispatch diagnostics, which the SDK keeps off STDOUT.

**Construct schema objects with named arguments.** The classes under `Nexus\Mcp\Core\Schema` mirror the MCP
spec shape, so their structure is dictated by the protocol rather than by this SDK's backward-compatibility
promise. As the spec evolves, a class may gain, drop, or reorder constructor parameters without that
counting as a breaking change under the SDK's own versioning. Always pass arguments by name
(`new TextContent(text: $body)`, not `new TextContent($body)`), regardless of how many parameters a
constructor currently takes, so a future parameter reordering or insertion cannot silently bind your values
to the wrong slot. The bundled examples follow this convention.

## See also

- **[Getting started](getting-started.md)**: minimal server and client.
- **[Server API](server.md)** and **[Client API](client.md)**: the full builder and method surface.
- **[Error handling](error-handling.md)**: the exception model and JSON-RPC error codes.
- **[Design rationale](design-rationale.md)**: why the SDK is shaped this way.
