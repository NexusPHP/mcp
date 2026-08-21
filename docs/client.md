# Client API

The `Client` class drives the client side of an MCP session over a `TransportInterface`. Build one with the fluent
`ClientBuilder`, connect it to a transport, then issue typed requests. Each request is self-contained, so typed
calls can start as soon as the transport is connected.

The SDK ships two client transports. `StdioClientTransport` launches the server as a subprocess.
`StreamableHttpClientTransport` POSTs to a remote MCP endpoint. Everything on this page works the same on both.
The one exception is [mirrored tool parameters](client/requests.md#mirrored-tool-parameters-over-http), which are
HTTP-only.

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

`connect()` does not block. It attaches the listener chain and starts the transport.

Shut the session down with `Client::disconnect()`, or close the transport directly. `disconnect()` closes the
transport and detaches it, so a later `connect()` can run. Either way, the SDK cancels the pending requests with a
`TransportAlreadyClosedException`. `disconnect()` does nothing when the client is not connected.

## Request metadata

Every request the client sends carries the client's identity in its `_meta` block. The SDK stamps three namespaced
keys onto every outbound request: `io.modelcontextprotocol/protocolVersion`, `io.modelcontextprotocol/clientInfo`,
and `io.modelcontextprotocol/clientCapabilities`. The server reads the client's identity and capabilities from each
request's `_meta`.

Only `protocolVersion` and `clientCapabilities` are required in the envelope. A server must treat `clientInfo` as
optional, and must not make behavioural or security decisions on it.

## Protocol version rejection

A server that will not serve the stamped version answers `-32022` and names the versions it accepts. The client
retries once. It restamps the request with the first of those versions that this SDK also speaks.

When the rejection names no usable version, or the retry is rejected in turn, the error reaches the caller as a
`RemoteCallFailedException`. Its `error` is the `UnsupportedProtocolVersionError`, which carries `supported` and
`requested`.

## Guide

- **[Client configuration](client/configuration.md)**: client info, capabilities, the logger, and the request-ID
  and progress-token factories.
- **[Connecting and discovery](client/connecting.md)**: attaching a transport and reading `server/discover`.
- **[Typed requests](client/requests.md)**: the per-method calls, mirrored tool parameters over HTTP, and the
  `sendRequest()` escape hatch.
- **[When the server asks for input first](client/input-required.md)**: answering an `InputRequiredResult`.
- **[Progress and timeouts](client/progress-and-timeouts.md)**: streaming progress and request deadlines.
- **[Notification handlers](client/notifications.md)**: reacting to server notifications.
- **[Extensions](client/extensions.md)**: enabling SEP-2133 extensions, their capability advertisement, and the
  outbound gate.
- **[Tasks](client/tasks.md)**: the SEP-2663 tasks extension. It calls tools as tasks and polls them to completion.
- **[Apps](client/apps.md)**: the SEP-1865 MCP Apps extension. It advertises renderable mime types and reads
  `_meta.ui` metadata.
- **[Subscriptions](client/subscriptions.md)**: opening `subscriptions/listen` streams.

## Lifecycle

### Build

`build()` validates the configuration and returns a `Client`. The client info must be set.

### Connect

`connect($transport)` attaches the listener chain, starts the transport, and returns immediately.

### Typed requests

The client correlates inbound responses to awaiting callers by `RequestId`. You can call `discover()` first to learn
the server's identity and capabilities. That call is optional.

### Shutdown

Call `disconnect()`, or call `close()` on the transport directly. The SDK cancels the pending requests with
`TransportAlreadyClosedException`. In-flight notification handlers drain before the close listeners fire.

### Losing the peer

Losing the peer is not the same as shutting down. Behind a `SupervisedTransport`, the client can send a pending
request again to the replacement instead of failing it.
[Retrying a lost request](transports/supervised.md#retrying-a-lost-request) covers this. It is off by default, and
it is limited to methods that read state.

## See also

- **[Getting started](getting-started.md)**: install and a minimal server.
- **[Server API](server.md)**: the symmetric builder reference.
- **[Transports](transports.md)**: `StdioClientTransport` (subprocess launcher), `StreamableHttpClientTransport`
  (one POST per message), and the in-memory paired transport.
- **[Architecture](architecture.md)**: the dispatch kernel and layering.
