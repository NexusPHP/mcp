# Typed requests

Each method mints a request id, sends the request, and awaits the typed result. The list methods accept
an optional `Cursor` for pagination, and full signatures are in the
[API reference](https://nexusphp.github.io/mcp/).

| Client method | JSON-RPC method | Returns |
| --- | --- | --- |
| `discover()` | `server/discover` | `DiscoverResult` |
| `listTools()` | `tools/list` | `ListToolsResult` |
| `callTool()` | `tools/call` | `CallToolResult\|InputRequiredResult` |
| `listResources()` | `resources/list` | `ListResourcesResult` |
| `listResourceTemplates()` | `resources/templates/list` | `ListResourceTemplatesResult` |
| `readResource()` | `resources/read` | `ReadResourceResult\|InputRequiredResult` |
| `listPrompts()` | `prompts/list` | `ListPromptsResult` |
| `getPrompt()` | `prompts/get` | `GetPromptResult\|InputRequiredResult` |
| `complete()` | `completion/complete` | `CompleteResult` |

An `InputRequiredResult` in a union means the server may ask for input before finishing. See
[When the server asks for input first](input-required.md).

```php
$tools = $client->listTools();
$result = $client->callTool('greet', ['name' => 'Paul']);
$about = $client->readResource('example://about');
$prompt = $client->getPrompt('walkthrough', ['audience' => 'a junior developer']);
```

Once `discover()` has run, every typed request requires the server to have advertised the matching
capability: `tools/*` needs `tools`, `resources/*` needs `resources`, `prompts/*` needs `prompts`, and
`completion/complete` needs `completions`. Calling one the server did not advertise throws
`ServerCapabilityNotSupportedException` before anything reaches the transport. Before discovery, and again
after a `disconnect()`, there are no advertised capabilities to gate against, so requests pass through.
Check `getServerCapabilities()` when you need to branch on what the server supports.

## Mirrored tool parameters over HTTP

A server may annotate a tool parameter with `x-mcp-header` in its `inputSchema`, asking clients to mirror that
argument into an `Mcp-Param-{Name}` HTTP header so gateways can route or rate-limit on it without parsing the
body. Supporting this is mandatory for a client on the Streamable HTTP transport, and the SDK does it for you:

- `listTools()` scans each tool's `inputSchema` and caches its declarations.
- `callTool()` extracts the annotated arguments, encodes them, and sends them as `Mcp-Param-{Name}` headers.
  An argument that is absent or `null` sends no header, which is what the server expects.
- A `-32020 HeaderMismatch` rejection re-lists the tool and retries the call once, so a cached schema that
  has fallen behind the server's recovers on its own.

Consequences worth knowing:

**A tool with invalid declarations disappears from `listTools()`.** The spec requires a client to exclude a
tool it cannot mirror rather than call it unmirrored, since the server would reject the call anyway. The tool
is dropped and a warning is logged naming the tool and the reason, so check your logger if a tool you expect
is missing. Declarations are invalid when they are empty, are not a valid HTTP field-name token, collide
case-insensitively, sit on a `number` parameter, or sit somewhere not reachable through a plain `properties`
chain.

**Only `listTools()` populates the cache.** Calling a tool you never listed sends no mirrored headers, so the
server answers `-32020 HeaderMismatch` and the client recovers by listing and retrying. That costs an extra
round trip each time, so call `listTools()` first when you can. A second mismatch on the retry propagates to
you, as does any other error code. `disconnect()` clears the cache, since it described the server you just
left.

**The re-listing walk is bounded.** It runs inside the `callTool()` you are awaiting, and every page is
answered, so no request deadline can end it. It stops when the server sends a cursor it has already
followed, and at 100 pages either way, logging a warning in both cases. Giving up also forgets the tool's
cached declarations, so the one retry goes out unmirrored rather than carrying the header the server just
rejected. A listing deeper than 100 pages therefore behaves like a tool you never listed.

None of this applies on stdio: that transport may ignore the annotations entirely, so the listing is passed
through untouched and no tool is dropped.

## The escape hatch: `sendRequest()`

Every standard client-to-server method has a typed wrapper above, so `sendRequest()` is for vendor extension
methods (or any pre-built request). Pass the request plus the `*ResultResponse` envelope class to decode the
reply into, and it returns that response. `GenericResultResponse` decodes a bare ack into an `EmptyResult`.
For a vendor reply with its own shape, subclass `JsonRpcResultResponse` with a matching `fromArray()`.

```php
use Nexus\Mcp\Core\Schema\ResultResponse\GenericResultResponse;

// AcmeSnapshotRequest is your own JsonRpcRequest subclass bound to a vendor method
// literal, e.g. "acme/snapshot". stampMeta() supplies the lifecycle `_meta` fields
// (protocol version, client info, declared capabilities) every request must carry.
$response = $client->sendRequest(
    new AcmeSnapshotRequest(
        id: $client->mintRequestId(),
        params: new AcmeSnapshotRequestParams(meta: $client->stampMeta()),
    ),
    GenericResultResponse::class,
);
```

`mintRequestId()` draws from the builder-configured id factory (auto-incrementing by default), so
hand-built requests share the id scheme of the typed methods. Supplying your own `RequestId` is
equally valid. The capability gate covers the methods behind the typed requests above, so a
`tools/list` against a server that advertised no `tools` throws `ServerCapabilityNotSupportedException`
(see [Typed requests](#typed-requests)). A vendor method like `acme/snapshot` passes through ungated unless
an [enabled extension](extensions.md) declared it as an outbound method, in which case a server that did not
advertise the extension is refused the same way. `listen()` also passes ungated: the spec defines no
capability for `subscriptions/listen`, so a server that does not serve it answers `-32601` and the failure
arrives as a remote error rather than a local one.
