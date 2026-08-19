# Client API

The `Client` class drives the client side of an MCP session over a `TransportInterface`. Build one with the
fluent `ClientBuilder`, connect it to a transport, then issue typed requests. Each request is self-contained,
so typed calls can start as soon as the transport is connected.

The SDK ships two client transports: `StdioClientTransport`, which launches the server as a subprocess, and
`StreamableHttpClientTransport`, which POSTs to a remote MCP endpoint. Everything below is identical on both,
apart from [mirrored tool parameters](client/requests.md#mirrored-tool-parameters-over-http), which are HTTP-only.

```php
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Client\Transport\StdioClientTransport;

$client = (new ClientBuilder())
    ->setClientInfo(name: 'my-client', version: '1.0.0')
    ->build()
;

$transport = new StdioClientTransport(command: [\PHP_BINARY, 'server.php']);
$client->connect($transport);

foreach ($client->listTools()->tools as $tool) {
    echo $tool->name, "\n";
}

$transport->close();
```

`connect()` is non-blocking: it attaches the listener chain and starts the transport. Shut the session down
with `Client::disconnect()`, which closes the transport and detaches it so a later `connect()` can run, or
by closing the transport directly. Either way pending requests are cancelled with a
`TransportAlreadyClosedException`. `disconnect()` is a no-op when the client is not connected.

Every request the client sends carries the client's identity in its `_meta` block. The SDK stamps three
namespaced keys onto every outbound request automatically: `io.modelcontextprotocol/protocolVersion`,
`io.modelcontextprotocol/clientInfo`, and `io.modelcontextprotocol/clientCapabilities`. The server reads the
client's identity and capabilities from each request's `_meta`. Only `protocolVersion` and
`clientCapabilities` are required in the envelope, so a server must treat `clientInfo` as optional and must
not make behavioural or security decisions on it.

A server that will not serve the stamped version answers `-32022` naming the versions it accepts. The client
retries once, restamping the request with the first of those this SDK also speaks. When the rejection names
none, or the retry is rejected in turn, the error reaches the caller as a `RemoteCallFailedException` whose
`error` is the `UnsupportedProtocolVersionError`, carrying `supported` and `requested`.

## Guide

- **[Client configuration](client/configuration.md)**: client info, capabilities, the logger, and the
  request-id and progress-token factories.
- **[Connecting and discovery](client/connecting.md)**: attaching a transport and reading
  `server/discover`.
- **[Typed requests](client/requests.md)**: the per-method calls, mirrored tool parameters over HTTP, and
  the `sendRequest()` escape hatch.
- **[When the server asks for input first](client/input-required.md)**: answering an
  `InputRequiredResult`.
- **[Progress and timeouts](client/progress-and-timeouts.md)**: streaming progress and request deadlines.
- **[Notification handlers](client/notifications.md)**: reacting to server notifications.
- **[Extensions](client/extensions.md)**: enabling SEP-2133 extensions, their capability
  advertisement, and the outbound gate.
- **[Tasks](client/tasks.md)**: the SEP-2663 tasks extension, calling tools as tasks and polling
  them to completion.
- **[Apps](client/apps.md)**: the SEP-1865 MCP Apps extension, advertising renderable mime types
  and reading `_meta.ui` metadata.
- **[Subscriptions](client/subscriptions.md)**: opening `subscriptions/listen` streams.

## Lifecycle

1. **`build()`** validates the configuration (client info must be set) and returns a `Client`.
2. **`connect($transport)`** attaches the listener chain, starts the transport, and returns immediately.
3. **Typed requests** correlate inbound responses to awaiting callers by `RequestId`. Optionally call
   `discover()` first to learn the server's identity and capabilities.
4. **Shutdown** is `disconnect()`, or `($transport)->close()` directly. Pending requests are cancelled with
   `TransportAlreadyClosedException`. In-flight notification handlers drain before the close listeners fire.

Losing the peer is not the same as shutting down. Behind a `SupervisedTransport`, a pending request can be
sent again to the replacement instead of failing, which
[Retrying a lost request](transports/supervised.md#retrying-a-lost-request) covers. It is off by default and limited to
methods that read state.

## See also

- **[Getting started](getting-started.md)**: install + minimal server.
- **[Server API](server.md)**: the symmetric builder reference.
- **[Transports](transports.md)**: `StdioClientTransport` (subprocess launcher),
  `StreamableHttpClientTransport` (one POST per message), and the in-memory paired transport.
- **[Architecture](architecture.md)**: dispatch kernel, layering, spec compliance.
