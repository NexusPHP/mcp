# Client API

The `Client` class drives the client side of an MCP session over a `TransportInterface`. Build one with the
fluent `ClientBuilder`, connect it to a transport, then issue typed requests. Each request is self-contained,
so typed calls can start as soon as the transport is connected.

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

`connect()` is non-blocking: it attaches the listener chain and starts the transport. There is no
`Client::close()`. Shut the session down by closing the transport, which cancels any pending requests with
a `TransportAlreadyClosedException`.

Every request the client sends carries the client's identity in its `_meta` block. The SDK stamps three
namespaced keys onto every outbound request automatically: `io.modelcontextprotocol/protocolVersion`,
`io.modelcontextprotocol/clientInfo`, and `io.modelcontextprotocol/clientCapabilities`. The server reads the
client's identity and capabilities from each request's `_meta`.

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
| `serverInfo` | `Implementation` | The server's name, version, title, … |
| `instructions` | `?string` | Optional model-facing guidance. |
| `ttlMs` / `cacheScope` | `int` / `CacheScope` | Cache hints, inherited from `CacheableResult`. |

```php
$result = $client->discover();
echo $result->serverInfo->name, ' ', $result->serverInfo->version, "\n";
echo 'Protocol versions: ', implode(', ', $result->supportedVersions), "\n";
```

After `discover()`, `getServerInfo()` returns the server's `Implementation` block (name, version, title, …).
It returns `null` before discovery runs.

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
| `readResource(string $uri)` | `resources/read` | `ReadResourceResult` |
| `getPrompt(string $name, ?array $arguments = null)` | `prompts/get` | `GetPromptResult` |
| `complete(PromptReference\|ResourceTemplateReference $ref, array $argument, ?array $context = null)` | `completion/complete` | `CompleteResult` |
| `callTool(string $name, ?array $arguments = null, ?\Closure $onProgress = null)` | `tools/call` | `CallToolResult` |
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

## The escape hatch: `sendRequest()`

Every standard client-to-server method has a typed wrapper above, so `sendRequest()` is for vendor extension
methods (or any pre-built request). Pass the request plus the `*ResultResponse` envelope class to decode the
reply into, and it returns that response. `GenericResultResponse` decodes a bare ack into an `EmptyResult`.
For a vendor reply with its own shape, subclass `JsonRpcResultResponse` with a matching `fromArray()`.

```php
use Nexus\Mcp\Core\Schema\JsonRpc\GenericResultResponse;

// $request is your own JsonRpcRequest subclass bound to a vendor method literal, e.g. "acme/snapshot".
$response = $client->sendRequest($request, GenericResultResponse::class);
```

You supply the `RequestId` yourself when building the request. The auto-incrementing factory backs the typed
methods above. The capability gate covers exactly the methods behind the typed requests above, so a
`tools/list` against a server that advertised no `tools` throws `ServerCapabilityNotSupportedException`
(see [Typed requests](#typed-requests)). A vendor method like `acme/snapshot` passes through ungated.

## Lifecycle

1. **`build()`** validates the configuration (client info must be set) and returns a `Client`.
2. **`connect($transport)`** attaches the listener chain, starts the transport, and returns immediately.
3. **Typed requests** correlate inbound responses to awaiting callers by `RequestId`. Optionally call
   `discover()` first to learn the server's identity and capabilities.
4. **Shutdown** is `($transport)->close()`. Pending requests are cancelled with
   `TransportAlreadyClosedException`. In-flight notification handlers drain before the close listeners fire.

## See also

- **[Getting started](getting-started.md)**: install + minimal server.
- **[Server API](server.md)**: the symmetric builder reference.
- **[Transports](transports.md)**: `StdioClientTransport` (subprocess launcher) and the in-memory paired transport.
- **[Architecture](architecture.md)**: dispatch kernel, layering, spec compliance.
