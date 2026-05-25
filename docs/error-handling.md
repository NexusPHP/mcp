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

## Server side: handler failures become error responses

When a request handler throws, the server's dispatcher converts the exception into a JSON-RPC error
response rather than letting it escape:

- Exceptions implementing
  [`JsonRpcProtocolExceptionInterface`](../src/Core/Exception/JsonRpcProtocolExceptionInterface.php) pin a
  code via `getErrorCode()`. `InvalidParamsException` maps to -32602, `MethodNotFoundException` to -32601, and
  the not-found exceptions (`ToolNotFoundException`, `PromptNotFoundException`, `ResourceNotFoundException`,
  `InvalidCursorException`) map to -32602 (the named entity is treated as an invalid parameter).
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
| `ClientNotInitializedException` | A request is issued before `initialize()` completes. |
| `ClientAlreadyConnectedException` / `ClientAlreadyInitializedException` | `connect()` / `initialize()` is called twice. |
| `ServerCapabilityNotSupportedException` | A typed request targets a capability the server did not advertise during `initialize` (for example `complete()` against a server with no completions). `ping` is never gated. |
| `UnsupportedProtocolVersionException` | The server negotiates a protocol revision the SDK does not speak. The client withholds `notifications/initialized`, closes the transport, then throws. |
| `RemoteCallFailedException` | The server answered with a JSON-RPC error response. The decoded `Error` (code, message, data) is available on the exception. |
| `TransportAlreadyClosedException` | The transport closed while a request was in flight (also raised on send-after-close). |

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
