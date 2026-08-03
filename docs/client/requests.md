# Typed requests

Each method mints a request id, sends the request, and awaits the typed result. The list methods accept an
optional `Cursor` for pagination.

| Method | JSON-RPC method | Returns |
| --- | --- | --- |
| `listTools(?Cursor $cursor = null)` | `tools/list` | `ListToolsResult` |
| `listResources(?Cursor $cursor = null)` | `resources/list` | `ListResourcesResult` |
| `listResourceTemplates(?Cursor $cursor = null)` | `resources/templates/list` | `ListResourceTemplatesResult` |
| `listPrompts(?Cursor $cursor = null)` | `prompts/list` | `ListPromptsResult` |
| `readResource(string $uri, ?array $inputResponses = null, ?string $requestState = null)` | `resources/read` | `ReadResourceResult\|InputRequiredResult` |
| `getPrompt(string $name, ?array $arguments = null, ?array $inputResponses = null, ?string $requestState = null)` | `prompts/get` | `GetPromptResult\|InputRequiredResult` |
| `complete(PromptReference\|ResourceTemplateReference $ref, array $argument, ?array $context = null)` | `completion/complete` | `CompleteResult` |
| `callTool(string $name, ?array $arguments = null, ?\Closure $onProgress = null, ?array $inputResponses = null, ?string $requestState = null)` | `tools/call` | `CallToolResult\|InputRequiredResult` |
| `discover()` | `server/discover` | `DiscoverResult` |

```php
$tools = $client->listTools();
$result = $client->callTool('greet', ['name' => 'Paul']);
$about = $client->readResource('example://about');
$prompt = $client->getPrompt('walkthrough', ['audience' => 'a junior developer']);
```

Once `discover()` has run, every typed request requires the server to have advertised the matching
capability: `tools/*` needs `tools`, `resources/*` needs `resources`, `prompts/*` needs `prompts`, and
`completion/complete` needs `completions`. Calling one the server did not advertise throws
`ServerCapabilityNotSupportedException` before anything reaches the transport. Before discovery there are no
advertised capabilities to gate against, so requests pass through. Check `getServerCapabilities()` when you
need to branch on what the server supports.

## Mirrored tool parameters over HTTP

A server may annotate a tool parameter with `x-mcp-header` in its `inputSchema`, asking clients to mirror that
argument into an `Mcp-Param-{Name}` HTTP header so gateways can route or rate-limit on it without parsing the
body. Supporting this is mandatory for a client on the Streamable HTTP transport, and the SDK does it for you:

- `listTools()` scans each tool's `inputSchema` and caches its declarations.
- `callTool()` extracts the annotated arguments, encodes them, and sends them as `Mcp-Param-{Name}` headers.
  An argument that is absent or `null` sends no header, which is what the server expects.
- A `-32020 HeaderMismatch` rejection re-lists the tool and retries the call once, so a cached schema that
  has fallen behind the server's recovers on its own.

Two consequences worth knowing:

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

None of this applies on stdio: that transport may ignore the annotations entirely, so the listing is passed
through untouched and no tool is dropped.

## The escape hatch: `sendRequest()`

Every standard client-to-server method has a typed wrapper above, so `sendRequest()` is for vendor extension
methods (or any pre-built request). Pass the request plus the `*ResultResponse` envelope class to decode the
reply into, and it returns that response. `GenericResultResponse` decodes a bare ack into an `EmptyResult`.
For a vendor reply with its own shape, subclass `JsonRpcResultResponse` with a matching `fromArray()`.

```php
use Nexus\Mcp\Core\Schema\ResultResponse\GenericResultResponse;

// $request is your own JsonRpcRequest subclass bound to a vendor method literal, e.g. "acme/snapshot".
$response = $client->sendRequest($request, GenericResultResponse::class);
```

You supply the `RequestId` yourself when building the request. The auto-incrementing factory backs the typed
methods above. The capability gate covers the methods behind the typed requests above, so a
`tools/list` against a server that advertised no `tools` throws `ServerCapabilityNotSupportedException`
(see [Typed requests](#typed-requests)). A vendor method like `acme/snapshot` passes through ungated, and so
does `listen()`: the spec defines no capability for `subscriptions/listen`, so a server that does not serve
it answers `-32601` and the failure arrives as a remote error rather than a local one.
