# Error handling

Every exception the SDK throws implements the marker interface
[`Nexus\Mcp\Core\Exception\McpExceptionInterface`](../src/Core/Exception/McpExceptionInterface.php), so a
single catch block traps anything the SDK raises:

```php
use Nexus\Mcp\Core\Exception\McpExceptionInterface;

try {
    $client->callTool('do_thing', ['x' => 1]);
} catch (McpExceptionInterface $e) {
    // Any SDK-originated failure: lifecycle, transport, capability gate, or a
    // server-returned error.
    $logger->error('MCP call failed: {error}', ['error' => $e->getMessage()]);
}
```

Exceptions live in three namespaces, all under the same marker: `Nexus\Mcp\Core\Exception\*` (protocol and
transport), `Nexus\Mcp\Server\Exception\*` (server-side handler and lifecycle), and
`Nexus\Mcp\Client\Exception\*` (client-side lifecycle and capability gating). Implementation-detail
exceptions are tagged `@internal`. PHPStan flags external use of them.

## JSON-RPC error codes

Failures become JSON-RPC error responses carrying a numeric code. The SDK models the standard
set in [`ProtocolErrorCode`](../src/Core/Schema/Enum/ProtocolErrorCode.php):

| Code | Name | Meaning |
| --- | --- | --- |
| -32700 | `ParseError` | The inbound line was not valid JSON. |
| -32600 | `InvalidRequest` | The envelope is not a valid JSON-RPC request (bad or empty `id`, wrong shape). |
| -32601 | `MethodNotFound` | No handler is registered for the method. |
| -32602 | `InvalidParams` | The params are invalid, or the named tool / prompt / resource does not exist. |
| -32603 | `InternalError` | An unexpected server-side failure. |

The 2026-07-28 spec adds three codes in the reserved `-320xx` band for lifecycle and header failures:

| Code | Name | Meaning | Emitted by the SDK |
| --- | --- | --- | --- |
| -32020 | `HeaderMismatch` | A request-metadata header disagreed with the message body (Streamable HTTP). | Yes, by `ParameterHeaderValidationMiddleware`, which answers `400` before the transport reads the body. |
| -32021 | `MissingRequiredClientCapability` | The request needs a client capability absent from `_meta.clientCapabilities`. | Yes, when a handler raises `MissingRequiredClientCapabilityException`. Only a handler knows what serving its request needs. |
| -32022 | `UnsupportedProtocolVersion` | The request's `_meta.protocolVersion` is not supported. | Yes, by the server dispatcher, which rejects the request before it reaches a handler. |

Every one of them decodes into the matching `Error` subclass, so a peer's error response is typed whether or
not this SDK is the side that sends it.

The SDK also defines its own code outside the spec's bands, in
[`SdkErrorCode`](../src/Core/Schema/Enum/SdkErrorCode.php):

| Code | Name | Meaning |
| --- | --- | --- |
| -32000 | `Overloaded` | The peer shed the request at one of its dispatch budgets. See `setMaxInFlightDispatches()` on the [server](server/configuration.md#in-flight-dispatch-cap) and the [client](client/configuration.md#in-flight-dispatch-cap). |

## Server side: handler failures become error responses

When a request handler throws, the server's dispatcher converts the exception into a JSON-RPC error
response rather than letting it escape:

- Exceptions implementing
  [`JsonRpcProtocolExceptionInterface`](../src/Core/Exception/JsonRpcProtocolExceptionInterface.php) pin a
  code via `getErrorCode()`. `InvalidParamsException` maps to -32602, `MethodNotFoundException` to -32601, and
  the not-found exceptions (`ToolNotFoundException`, `PromptNotFoundException`, `ResourceNotFoundException`,
  `ResourceNotRegisteredException`, `InvalidCursorException`) map to -32602 (the named entity is treated as
  an invalid parameter).
- Any of them may also carry an `errorData` payload, which becomes the error response's `data` slot and is
  omitted when null. `ResourceNotFoundException` and `ResourceNotRegisteredException` use it for the
  `data.uri` the spec's resource-not-found example shows, and `MissingRequiredClientCapabilityException` for the `data.requiredCapabilities` its
  code requires. Only the latter is required: `data` is "defined by the sender", so the URI echo is this
  SDK's choice and is deliberately left uncapped so a client can match it against the URI it sent. That is
  safe because `params.uri` is already confined to printable ASCII by the RFC 3986 grammar, so the echo
  carries no control bytes and is never longer than the URI the peer itself sent.
- An error `message` quoting a peer-supplied value is bounded, and every byte outside printable ASCII
  (`\x20-\x7E`) is rendered as `\xNN` before it leaves the server, so non-ASCII text comes back escaped
  too (an `é` reads as `\xc3\xa9`). A short identifier (a tool name, a cursor, a protocol version) is cut
  to 80 bytes with a trailing `...`, and a URI or a whole nested cause to 256. The spec asks that a
  `message` stay "a concise single sentence", and an unbounded echo is both a response amplifier and a way
  to put terminal escapes into whatever renders the error. The same treatment reaches a `data` slot the
  request grammar does not already confine: `UnsupportedProtocolVersionError`'s `data.requested` is capped
  and escaped, because `_meta` accepts any non-empty string as a protocol version.
- The policy covers the values this SDK composes, and stops there. A protocol exception thrown by a
  handler of your own reaches the peer with both its `message` and its `errorData` unchanged, so that the
  SDK never rewrites an error you meant to send. The tasks extension stores the same two on the task
  record, where a later `tasks/get` returns them. A handler that interpolates request arguments into
  either inherits none of the bounding above, and should apply it with
  [`SafeDisplay`](../src/Core/SafeDisplay.php): `SafeDisplay::sanitise()` for a short identifier,
  `SafeDisplay::sanitiseCause()` for a URI or a composed message.
- A handler that needs a client capability the request did not declare raises
  `MissingRequiredClientCapabilityException` with the `ClientCapabilities` it wanted. That answers -32021,
  and the Streamable HTTP transport pins it to `400` even though handler-raised errors otherwise ride `200`.
- Any other `\Throwable` from a handler becomes a generic -32603 `InternalError`, so a handler bug never
  leaks a stack trace or internal message to the client. The original throwable is logged server-side at
  the dispatcher.
- `ToolOutputValidationException` is special: a tool whose `structuredContent` fails its `outputSchema` is
  logged server-side and returned as a normal `CallToolResult` with `isError: true`, so malformed
  structured data is never sent.

Tool authors signal a *tool-level* failure (as opposed to a protocol error) by returning a
`CallToolResult` with `isError: true`, not by throwing.

## Client side: lifecycle, capability, and remote errors

The typed `Client` methods throw before sending when the call is out of order or unsupported, and surface
server-returned errors as exceptions:

| Exception | Thrown when |
| --- | --- |
| `ClientNotConnectedException` | A request is issued before `connect()`. |
| `ClientAlreadyConnectedException` | `connect()` is called twice. |
| `ServerCapabilityNotSupportedException` | A typed request targets a capability the server did not advertise via `server/discover` (for example `complete()` against a server with no completions). |
| `RemoteCallFailedException` | The server answered with a JSON-RPC error response. The decoded `Error` (code, message, data) is available on the exception. |
| `TransportAlreadyClosedException` | The transport closed while a request was in flight (also raised on send-after-close). |
| `OutboundRequestFailedException` | The transport could not carry the request to completion (connection refused, TLS failure, a stalled read, an HTTP status whose body settles nothing), so no response can arrive. The underlying fault is the exception's `previous`. |
| `UnexpectedHttpStatusException` | An HTTP exchange answered with a status carrying no JSON-RPC payload that settles the message it was sent for (a `502` from a proxy, an id-less error envelope, a `202` to a request). Arrives as the `previous` of `OutboundRequestFailedException`, with the status and the leading bytes of the body on it. |
| `RequestTimeoutException` | The request's deadline elapsed before the peer answered. See [request timeouts](client/progress-and-timeouts.md#request-timeouts) for the two bounds and how progress notifications extend them. |

```php
use Nexus\Mcp\Client\Exception\ServerCapabilityNotSupportedException;
use Nexus\Mcp\Core\Exception\RemoteCallFailedException;

try {
    $result = $client->complete($ref, ['name' => 'arg', 'value' => 'a']);
} catch (ServerCapabilityNotSupportedException) {
    // Degrade gracefully: the server has no completions.
} catch (RemoteCallFailedException $e) {
    // The server ran the method but returned an error.
    $logger->warning('Server error {code}: {message}', [
        'code' => $e->error->code,
        'message' => $e->error->message,
    ]);
}
```

See [examples/capability-aware-client.php](../examples/capability-aware-client.php) for a runnable
demonstration of the capability gate.

## Transport errors

Out-of-order transport operations throw typed exceptions, so misuse surfaces eagerly rather than
as silently dropped envelopes: `TransportNotStartedException` (send before `start()`),
`TransportAlreadyStartedException` (double `start()`), and `TransportAlreadyClosedException` (use after
`close()`). See [docs/transports.md](transports.md) for the per-transport state machine.

## See also

- **[Client API](client.md)**: each typed method documents the exceptions it can throw.
- **[Server API](server.md)**: handler registration and capability advertisement.
- **[Transports](transports.md)**: the transport state machine and its exceptions.
- **[Best practices](best-practices.md)**: degrading gracefully and advertising capabilities honestly.
