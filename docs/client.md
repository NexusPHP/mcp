# Client API

The `Client` class drives the client side of an MCP session over a `TransportInterface`. Build one with the
fluent `ClientBuilder`, connect it to a transport, then issue typed requests. Each request is self-contained,
so typed calls can start as soon as the transport is connected.

The SDK ships two client transports: `StdioClientTransport`, which launches the server as a subprocess, and
`StreamableHttpClientTransport`, which POSTs to a remote MCP endpoint. Everything below is identical on both,
apart from [mirrored tool parameters](#mirrored-tool-parameters-over-http), which are HTTP-only.

```php
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Client\Transport\StdioClientTransport;

$client = new ClientBuilder()
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

## Client info

Required before `build()`. Stamped into every request's `_meta`.

```php
->setClientInfo(
    name: 'my-client',
    version: '1.0.0',
    title: 'My Friendly Client',
    description: 'A short description carried in every request.',
    websiteUrl: 'https://example.com',
)
```

## Client capabilities

Optional. Defaults to an empty `ClientCapabilities`. Stamped into every request's `_meta` so the server can
read what the client supports.

```php
use Nexus\Mcp\Core\Schema\ClientCapabilities;

->setClientCapabilities(new ClientCapabilities(elicitation: []))
```

## Logger

Optional. Defaults to `Psr\Log\NullLogger`. Transport errors and uncaught notification-handler exceptions
are logged here.

```php
->setLogger($psrLogger)
```

## Request-id and progress-token factories

Optional. Both default to a monotonically-incrementing factory (`1`, `2`, … for request ids;
`progress-1`, `progress-2`, … for progress tokens). Override either when you need a different id scheme,
for example UUIDs.

```php
->setRequestIdFactory(static fn(): string => Uuid::v4()->toRfc4122())
->setProgressTokenFactory(static fn(): string => Uuid::v4()->toRfc4122())
```

Each factory is a `\Closure(): (int|non-empty-string)` and must return a value unique among concurrently
in-flight requests.

## Connecting and discovery

```php
$client->connect($transport);
$result = $client->discover();
```

`discover()` sends a `server/discover` request and records the server's advertised info and capabilities. It
is optional: a client may call it to learn the server's identity and capabilities, but no discovery is
required before issuing typed requests. After `connect()`, the client can call `listTools()` / `callTool()` /
the other typed methods directly.

`discover()` returns a `DiscoverResult`:

| Field | Type | Notes |
| --- | --- | --- |
| `supportedVersions` | `list<string>` | The protocol revisions the server speaks. |
| `capabilities` | `ServerCapabilities` | Advertised server capabilities. |
| `instructions` | `?string` | Optional model-facing guidance. |
| `ttlMs` / `cacheScope` | `int` / `CacheScope` | Cache hints, inherited from `CacheableResult`. |
| `meta` | `ResultMetaObject` | Carries the server's `Implementation` under `serverInfo`, when the server sends one. |

The server's identity rides the result `_meta` rather than the result body, so `serverInfo` is nullable: a
server may decline to identify itself. The value is self-reported and unverified, so treat it as display and
logging material, never as a behavioural or security signal.

```php
$result = $client->discover();
echo $result->meta->serverInfo?->name ?? '(anonymous)', "\n";
echo 'Protocol versions: ', implode(', ', $result->supportedVersions), "\n";
```

After `discover()`, `getServerInfo()` returns the server's `Implementation` block (name, version, title, …).
It returns `null` before discovery runs, and also when the server identified itself on neither leg.

```php
$info = $client->getServerInfo();
echo $info?->name, ' ', $info?->version;
```

`getServerCapabilities()` returns the server's advertised `ServerCapabilities`, or `null` before discovery.
Use it to check what the server supports before issuing a typed request (see
[Typed requests](#typed-requests)).

```php
if (null !== $client->getServerCapabilities()?->tools) {
    $tools = $client->listTools();
}
```

`connect()` attaches and starts the transport. `disconnect()` is its inverse: it closes the transport and
detaches it (a no-op when not connected), so the client can `connect()` to a new transport afterwards.
Calling `connect()` twice throws `ClientAlreadyConnectedException`, and using the client before `connect()`
throws `ClientNotConnectedException`.

```php
$client->disconnect();
```

## Typed requests

Each method mints a request id, sends the request, and awaits the typed result. The list methods accept an
optional `Cursor` for pagination.

| Method | JSON-RPC method | Returns |
| --- | --- | --- |
| `listTools(?Cursor $cursor = null)` | `tools/list` | `ListToolsResult` |
| `listResources(?Cursor $cursor = null)` | `resources/list` | `ListResourcesResult` |
| `listResourceTemplates(?Cursor $cursor = null)` | `resources/templates/list` | `ListResourceTemplatesResult` |
| `listPrompts(?Cursor $cursor = null)` | `prompts/list` | `ListPromptsResult` |
| `readResource(string $uri)` | `resources/read` | `ReadResourceResult\|InputRequiredResult` |
| `getPrompt(string $name, ?array $arguments = null)` | `prompts/get` | `GetPromptResult\|InputRequiredResult` |
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

### When the server asks for input first

`callTool()`, `readResource()` and `getPrompt()` can answer with an `InputRequiredResult` instead of the
result you asked for: the server needs something from the user before it can finish. Branch on the type
rather than assuming the happy path.

```php
$result = $client->callTool('book_flight', ['destination' => 'Cebu']);

if ($result instanceof InputRequiredResult) {
    // $result->inputRequests is a map of field name to InputRequest describing what to collect.
    // $result->requestState, when present, is opaque and must be echoed back verbatim.
}
```

To answer a tool call, call it again with the collected values and the `requestState` it handed you:

```php
$answered = $client->callTool(
    name: 'book_flight',
    arguments: ['destination' => 'Cebu'],
    inputResponses: ['seat' => new ElicitResult(action: ElicitAction::Accept, content: ['seat' => '14C'])],
    requestState: $result->requestState,
);
```

Keep `arguments` the same as the first call. The server is resuming that request, not being given a new
one, and `requestState` must go back exactly as it arrived: it is opaque, and a server is entitled to
reject a modified one.

`readResource()` and `getPrompt()` take no such arguments, so answering those two means building the
request yourself and sending it through `sendRequest()`:

```php
$response = $client->sendRequest(
    new ReadResourceRequest(
        id: $requestId,
        params: new ReadResourceRequestParams(
            uri: 'file:///report.csv',
            inputResponses: ['passphrase' => new ElicitResult(action: ElicitAction::Accept, content: ['passphrase' => 'hunter2'])],
            requestState: $result->requestState,
            meta: $meta,
        ),
    ),
    ReadResourceResultResponse::class,
);
```

### Streaming progress from `callTool`

Pass an `onProgress` callback to receive the server's `notifications/progress` for that one call. The SDK
mints a fresh `progressToken` into the request's `_meta`, routes matching notifications to your callback for
the duration of the call, and disposes the listener once the response resolves.

```php
$result = $client->callTool(
    name: 'count_down',
    arguments: ['count' => 5],
    onProgress: static function (float $progress, ?float $total, ?string $message): void {
        printf("  %g%s%s\n", $progress, null !== $total ? "/{$total}" : '', null !== $message ? " {$message}" : '');
    },
);
```

The callback signature is `\Closure(float $progress, ?float $total, ?string $message): void`.

### Request timeouts

Every request carries a deadline, so a peer that goes silent releases the caller instead of blocking it for
the life of the process. Two bounds apply, both configurable at build time and both disabled by passing
`null`:

| Setting | Default | Bounds |
| --- | --- | --- |
| `setRequestTimeout(?float)` | `60.0` | Seconds the peer may stay silent. **Each progress notification restarts it.** |
| `setMaxRequestTimeout(?float)` | `600.0` | Seconds the request may run in total, however much progress arrives. |

```php
$client = new ClientBuilder()
    ->setClientInfo(name: 'my-client', version: '1.0.0')
    ->setRequestTimeout(30.0)
    ->setMaxRequestTimeout(300.0)
    ->build()
;
```

When a deadline elapses the client frees the request's correlation slot, sends `notifications/cancelled` so
the peer can stop working on a result nobody will read, and throws `RequestTimeoutException` naming the
request and the deadline that fired. A response arriving afterwards has no awaiter left and is discarded as
an orphan.

In the other direction, an inbound `notifications/cancelled` cancels the request the client is serving under
that id, and the response is then suppressed, the same as on the server. Register your own
`notifications/cancelled` handler to replace that behaviour.

The idle timer restarting on progress is what makes a long tool call safe under a short default: a call that
reports progress stays alive indefinitely, up to the ceiling. That only applies to a call that asked for
progress, since only then does the SDK mint a token to match notifications against:

```php
// Survives well past 60s as long as the server keeps reporting.
$client->callTool('reindex', $args, onProgress: static fn(float $done): null => null);
```

A long call that reports nothing needs a wider deadline of its own. `sendRequest()` takes a per-request
override for exactly that:

```php
$response = $client->sendRequest($request, CallToolResultResponse::class, timeout: 900.0);
```

### Mirrored tool parameters over HTTP

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

## Notification handlers

Register handlers for server-to-client notifications at build time. A handler implements
`NotificationHandlerInterface`. The dispatch table is keyed by method name.

```php
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;

->addNotificationHandler(ToolListChangedNotification::getMethod(), $myHandler)
```

A build-time `notifications/progress` handler receives every progress notification whose token is **not**
claimed by an in-flight `callTool(onProgress:)`. The two coexist: per-call `onProgress` takes its own token,
and the build-time handler sees the rest.

## Subscriptions

`listen()` opens a `subscriptions/listen` stream and routes every notification the server tags with that
stream's id to the given callback. It returns as soon as the request is away, so the caller is not blocked
for the life of the stream.

```php
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;

$stream = $client->listen(
    new SubscriptionFilter(toolsListChanged: true, resourcesListChanged: true),
    static function (JsonRpcNotification $notification): void {
        // Only what this stream asked for arrives here.
    },
);

$stream->close();
```

The filter names what the stream wants. The server honours the intersection of the requested set and what it
supports, and MUST NOT push a type that was not asked for.

- **Routing is per stream, and most-specific wins.** A notification carrying a subscription id goes to that
  stream's callback and to nothing else. One arriving untagged, or naming a stream this client does not hold,
  falls through to the build-time notification handler for its method. This mirrors how a per-call
  `onProgress` claims its token ahead of the build-time `notifications/progress` handler.
- **`close()` ends the stream** by sending the `notifications/cancelled` the spec requires and retiring the
  correlation slot. It is idempotent, and the server answers an abrupt close with nothing.
- **`await()` blocks until the *server* tears the stream down**, returning the empty result the spec calls
  graceful closure. A stream the client closed carries no response, so do not await one.
- **No deadline applies.** A listen request legitimately never returns, so it is exempt from the request
  timeouts that bound every other call.

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

## Lifecycle

1. **`build()`** validates the configuration (client info must be set) and returns a `Client`.
2. **`connect($transport)`** attaches the listener chain, starts the transport, and returns immediately.
3. **Typed requests** correlate inbound responses to awaiting callers by `RequestId`. Optionally call
   `discover()` first to learn the server's identity and capabilities.
4. **Shutdown** is `disconnect()`, or `($transport)->close()` directly. Pending requests are cancelled with
   `TransportAlreadyClosedException`. In-flight notification handlers drain before the close listeners fire.

## See also

- **[Getting started](getting-started.md)**: install + minimal server.
- **[Server API](server.md)**: the symmetric builder reference.
- **[Transports](transports.md)**: `StdioClientTransport` (subprocess launcher),
  `StreamableHttpClientTransport` (one POST per message), and the in-memory paired transport.
- **[Architecture](architecture.md)**: dispatch kernel, layering, spec compliance.
