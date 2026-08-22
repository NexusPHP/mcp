# Error handling

Every exception the SDK throws implements the marker interface
[`Nexus\Mcp\Core\Exception\McpExceptionInterface`](../src/Core/Exception/McpExceptionInterface.php). A single
catch block traps anything the SDK raises:

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

Exceptions live in three namespaces under the same marker. `Nexus\Mcp\Core\Exception\*` holds the protocol and
transport failures. `Nexus\Mcp\Server\Exception\*` holds the server-side handler and lifecycle failures.
`Nexus\Mcp\Client\Exception\*` holds the client-side lifecycle and capability-gating failures.
Implementation-detail exceptions are tagged `@internal`, and PHPStan flags external use of them.

## JSON-RPC error codes

Failures become JSON-RPC error responses that carry a numeric code. The SDK models the standard set in
[`ProtocolErrorCode`](../src/Core/Schema/Enum/ProtocolErrorCode.php):

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

Every one of them decodes into the matching `Error` subclass. A peer's error response is typed whether or not this
SDK is the side that sends it.

The SDK also defines its own code outside the spec's bands, in
[`SdkErrorCode`](../src/Core/Schema/Enum/SdkErrorCode.php):

| Code | Name | Meaning |
| --- | --- | --- |
| -32000 | `Overloaded` | The peer shed the request at one of its dispatch budgets. See `setMaxInFlightDispatches()` on the [server](server/configuration.md#in-flight-dispatch-cap) and the [client](client/configuration.md#in-flight-dispatch-cap). |

## Server side: handler failures become error responses

When a request handler throws, the server's dispatcher converts the exception into a JSON-RPC error response. The
exception never escapes.

### Protocol exceptions pin a code

An exception that implements
[`JsonRpcProtocolExceptionInterface`](../src/Core/Exception/JsonRpcProtocolExceptionInterface.php) pins its code
through `getErrorCode()`. `InvalidParamsException` maps to -32602 and `MethodNotFoundException` to -32601. The
not-found exceptions map to -32602 too, because the named entity is treated as an invalid parameter. Those are
`ToolNotFoundException`, `PromptNotFoundException`, `ResourceNotFoundException`,
`ResourceNotRegisteredException`, and `InvalidCursorException`.

### The `data` slot

Any of them may also carry an `errorData` payload. It becomes the error response's `data` slot, and the slot is
omitted when the payload is null. Three producers ship with the SDK:

- `ResourceNotFoundException` and `ResourceNotRegisteredException` carry the `data.uri` the spec's
  resource-not-found example shows.
- A `tools/call` argument failure carries the `data.validation_errors` list described under
  [Diagnostic message conventions](#diagnostic-message-conventions).
- `MissingRequiredClientCapabilityException` carries the `data.requiredCapabilities` its code requires.

Only the last is required. The spec defines `data` as "defined by the sender", so the URI echo is this SDK's
choice. The SDK echoes the URI whole, so a client can match it against the URI it sent. That is safe, because
decode already confines `params.uri` to printable ASCII by the RFC 3986 grammar and to 8192 bytes. The echo
carries no control bytes, and it is never longer than the URI the peer itself sent.

### Bounded messages

An error `message` that quotes a peer-supplied value is bounded. Every byte outside printable ASCII (`\x20-\x7E`)
is rendered as `\xNN` before it leaves the server, so non-ASCII text comes back escaped too. An `é` reads as
`\xc3\xa9`. A short identifier, such as a tool name, a cursor, or a protocol version, is cut to 80 bytes with a
trailing `...`. A URI or a whole nested cause is cut to 256.

The spec asks that a `message` stay "a concise single sentence". An unbounded echo is both a response amplifier and
a way to put terminal escapes into whatever renders the error. The same treatment reaches a `data` slot the request
grammar does not already confine. `UnsupportedProtocolVersionError`'s `data.requested` is capped and escaped,
because `_meta` accepts any non-empty string as a protocol version.

### What the policy does not cover

The policy covers the values this SDK composes, and it stops there. A protocol exception thrown by a handler of
your own reaches the peer with both its `message` and its `errorData` unchanged. The SDK never rewrites an error
you meant to send. The tasks extension stores the same two values on the task record, where a later `tasks/get`
returns them.

A handler that interpolates request arguments into either value inherits none of the bounding above. Apply it
yourself with [`SafeDisplay`](../src/Core/SafeDisplay.php): `SafeDisplay::sanitise()` for a short identifier, and
`SafeDisplay::sanitiseCause()` for a URI or a composed message.

### Missing client capabilities

A handler that needs a client capability the request did not declare raises
`MissingRequiredClientCapabilityException` with the `ClientCapabilities` it wanted. That answers -32021. The
Streamable HTTP transport pins it to `400`, even though handler-raised errors otherwise ride `200`.

### Everything else

Any other `\Throwable` from a handler becomes a generic -32603 `InternalError`, so a handler bug never leaks a
stack trace or an internal message to the client. The dispatcher logs the original throwable on the server side.

`ToolOutputValidationException` is the exception to that rule. A tool whose `structuredContent` fails its
`outputSchema` is logged on the server side and returned as a normal `CallToolResult` with `isError: true`. The
malformed structured data is never sent.

Tool authors signal a *tool-level* failure by returning a `CallToolResult` with `isError: true`, not by throwing.
A thrown exception is a protocol error.

## Client side: lifecycle, capability, and remote errors

The typed `Client` methods throw before they send when the call is out of order or unsupported. They surface
server-returned errors as exceptions:

| Exception | Thrown when |
| --- | --- |
| `LogicException` | A request is issued before `connect()`, or `connect()` is called twice. |
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

See [examples/capability-aware-client.php](../examples/capability-aware-client.php) for a runnable demonstration
of the capability gate.

## Transport errors

Out-of-order transport operations throw typed exceptions, so misuse surfaces early rather than as silently dropped
envelopes. `TransportNotStartedException` means a send before `start()`. `TransportAlreadyStartedException` means a
double `start()`. `TransportAlreadyClosedException` means use after `close()`. See
[docs/transports.md](transports.md) for the per-transport state machine.

## Diagnostic message conventions

Every `Assert::that(...)` chain and bare `\InvalidArgumentException` in `Core/Schema/` follows a fixed shape.
Consumers can parse the messages programmatically, and non-PHP clients can recognise the structure.

### Field labels

Each message identifies its target with the JSON field name in double quotes, optionally scoped by a parent key.

#### Envelope fields

Top-level request, result, notification, and error-response fields use a dotted path from the JSON-RPC envelope
key:

```text
'"params.name" must be a string, {type} given.'
'"result.completion.values" must be a list, non-list array given.'
'"params._meta" must be an object, {type} given.'
'"error.code" must be an integer, {type} given.'
```

#### Classes with one wrapping field

Schema classes with a single canonical wrapping field use that field as the label:

| Class                                                                                          | Label                  |
|------------------------------------------------------------------------------------------------|------------------------|
| `ServerCapabilities`, `ClientCapabilities`                                                     | `"capabilities"`       |
| `Annotations`, `ToolAnnotations`                                                               | `"annotations"`        |
| `Icon` (array item under `icons`)                                                              | `"icons"`              |
| `PromptArgument` (array item under `arguments`)                                                | `"arguments"`          |
| `MetaObject` and its `MetaObject\*` subclasses                                                 | `"_meta"`              |
| `RequestId`                                                                                    | `"id"`                 |
| `ProtocolVersion`                                                                              | `"protocolVersion"`    |
| `Cursor`                                                                                       | `"cursor"`             |
| `ElicitRequestedSchema`                                                                        | `"requestedSchema"`    |
| `EnumOption` (array item under `oneOf`)                                                        | `"oneOf"`              |

#### Multi-context classes

A class referenced under several keys drops the prefix entirely. `Implementation` sits under both `serverInfo`
and `clientInfo`, so its messages start with the field name directly:

```text
'"name" must be a string, {type} given.'
```

#### Classes without a wrapping field

These use the lowercased, space-separated form of their class name as the prefix: `text content`,
`image content`, `embedded resource`, `resource link`, `boolean schema`, `number schema`, `tool`, `prompt`,
`resource template`, `prompt message`, and so on.

#### Requests and notifications

`*Request` and `*Notification` classes have no label. Their messages start with the field name directly:

```text
'"id" must be an int or non-empty string, {type} given.'
'missing the required "params" key.'
```

### Envelope-kind wrapper

The `JsonRpcMessageParser` prefixes every decode failure with one wrapper per envelope kind, so the inner message
never repeats it:

```text
Invalid success response: "result" is missing the required "content" key.
Invalid error response: "error.code" must be an integer, {type} given.
Invalid "tools/call" request: "params" is missing the required "name" key.
Invalid "notifications/progress" notification: "params" is missing the required "progressToken" key.
```

The four kinds (request, notification, success response, error response) are the only omitted top scope.
Everything below the envelope keeps its scope in the inner message: `params`, `result`, the `error` object, and
nested objects.

### Rules

1. JSON field names are double-quoted (`"name"`, `"capabilities.tasks.cancel"`).
2. `Assert::that(...)->values()` and `->keys()` chains prepend `each` to the message. The message stays singular to
   agree with it: `each "params.stopSequences" entry must be a string`, not `entries must be strings`.
3. Type mismatches use the PHP idiom `<type> given.` (`int given.`, `array given.`).
4. Required-key checks mirror the matching type-mismatch's scope, drop the envelope kind (the wrapper above supplies
   it), and read `is missing`. Envelope-root fields stay bare: `'missing the required "id" key.'`. Payload and
   deeper fields keep their scope: `'"params" is missing the required "name" key.'` and
   `'"error.data" is missing the required "elicitations" key.'`.
5. Value mismatches against a constant use Assert's lazy `{value}` and `{other}` template tokens instead of
   `\sprintf`, so the comparand renders through `var_export` at exception-render time.

### Schema violations

Tool argument and `structuredContent` conformance failures follow the same shape. The server's
`ValidationErrorFormatter` renders each leaf schema violation with the dotted data path double-quoted and the
`<type> given.` idiom. At the root the path is bare, because the `Invalid arguments for tool "x": ...` wrapper
supplies the scope.

An argument failure also lists each violation under `data.validation_errors` with its RFC 6901 pointer. The list
is capped at eight entries and sanitised like the message, since an undeclared key name or an `enum` value is the
peer's own text.

`ArgumentBinder`'s failures speak the same grammar, and the owning store wraps them with the same feature
identity.

### Reusable validators

`Core/Validation/` exposes five field-format validators. Each takes the value plus a `$context` label that becomes
the message prefix:

| Validator                                               | Purpose                                                    |
|---------------------------------------------------------|------------------------------------------------------------|
| `IdentifierNameValidator::validate($name, $context)`    | 1-128 chars from `[A-Za-z0-9._-]`, authoring only          |
| `IconSrcValidator::validate($icons, $context)`          | HTTP/HTTPS URL or base64 `data:` URI, authoring only       |
| `Rfc3986UriValidator::validate($uri, $context)`         | RFC 3986 absolute URI                                      |
| `Rfc6570UriTemplateValidator::validate($uri, $context)` | RFC 6570 URI Template                                      |
| `Iso8601DateTimeValidator::parse($value, $context)`     | ISO 8601 datetime parse                                    |

The validator templates have no hardcoded field noun. Callers pass the full label they want in the emitted
message, for example `'"params.name"'`, `'tool "name"'`, `'resource link "uri"'`, or
`'resource template "uriTemplate"'`.

## See also

- **[Client API](client.md)**: each typed method documents the exceptions it can throw.
- **[Server API](server.md)**: handler registration and capability advertisement.
- **[Transports](transports.md)**: the transport state machine and its exceptions.
- **[Best practices](best-practices.md)**: degrading gracefully and advertising capabilities honestly.
