# Client API

The `Client` class drives the client side of an MCP session over a `TransportInterface`. Build one with the
fluent `ClientBuilder`, connect it to a transport, run the handshake, then issue typed requests.

```php
use Nexus\Mcp\Client\Client;
use Nexus\Mcp\Client\Transport\StdioClientTransport;

$client = Client::builder()
    ->setClientInfo(name: 'my-client', version: '1.0.0')
    ->build()
;

$transport = new StdioClientTransport(command: [\PHP_BINARY, 'server.php']);
$client->connect($transport);
$client->initialize();

foreach ($client->listTools()->tools as $tool) {
    echo $tool->name, "\n";
}

$transport->close();
```

`connect()` is non-blocking: it attaches the listener chain and starts the transport. There is no
`Client::close()`; shut the session down by closing the transport, which cancels any pending requests with
a `TransportAlreadyClosedException`.

## Client info

Required before `build()`. Sent to the server during `initialize`.

```php
->setClientInfo(
    name: 'my-client',
    version: '1.0.0',
    title: 'My Friendly Client',
    description: 'A short description sent to the server during initialize.',
    websiteUrl: 'https://example.com',
)
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

## Connecting and the handshake

```php
$client->connect($transport);
$result = $client->initialize();
```

`initialize()` sends the `initialize` request, awaits the result, validates the protocol version the server
settled on, then sends `notifications/initialized`. It accepts an optional `ClientCapabilities` and
`ProtocolVersion`; both default to an empty capability set and the latest supported protocol version. It
returns the `InitializeResult` and may be called only once per client. A second `initialize()` throws
`ClientAlreadyInitializedException`, and any non-`ping` request issued before the handshake completes is
rejected with `ClientNotInitializedException`.

Version negotiation follows the spec: the server's response carries the protocol version it chose. Because
the SDK ships against a single revision with no back-compat layer, the client supports exactly that revision.
If the server settles on any other version, `initialize()` withholds `notifications/initialized`, closes the
transport (the spec's "disconnect" on an unsupported version), and throws `UnsupportedProtocolVersionException`.

```php
use Nexus\Mcp\Core\Schema\ClientCapabilities;

$result = $client->initialize(new ClientCapabilities(sampling: []));
```

After the handshake, `getServerInfo()` returns the server's `Implementation` block (name, version, title,
…). It returns `null` before the handshake completes.

```php
$info = $client->getServerInfo();
echo $info?->name, ' ', $info?->version;
```

`getServerCapabilities()` returns the server's negotiated `ServerCapabilities`, or `null` before the
handshake. Use it to check what the server supports before issuing a typed request (see
[Typed requests](#typed-requests)).

```php
if (null !== $client->getServerCapabilities()?->tools) {
    $tools = $client->listTools();
}
```

`connect()` attaches and starts the transport; `disconnect()` is its inverse: it closes the transport and
detaches it (a no-op when not connected), so the client can `connect()` to a new transport afterwards.
Calling `connect()` twice throws `ClientAlreadyConnectedException`, and using the client before `connect()`
throws `ClientNotConnectedException`.
Re-running `initialize()` over a reconnection is only possible after a handshake that did not complete; a
client that already finished `initialize()` stays initialized.

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
| `ping()` | `ping` | `void` |
| `setLoggingLevel(LoggingLevel $level)` | `logging/setLevel` | `void` |

```php
$tools = $client->listTools();
$result = $client->callTool('greet', ['name' => 'Paul']);
$about = $client->readResource('example://about');
$prompt = $client->getPrompt('walkthrough', ['audience' => 'a junior developer']);
$client->ping();
$client->setLoggingLevel(LoggingLevel::Info);
```

`ping()` and `setLoggingLevel()` return `void`. `ping()` is the only typed request permitted before `initialize()`
completes, returning normally when the peer answers and throwing on failure.

Every typed request other than `ping` requires the server to have advertised the matching capability during
the handshake: `tools/*` needs `tools`, `resources/*` needs `resources`, `prompts/*` needs `prompts`,
`completion/complete` needs `completions`, and `logging/setLevel` needs `logging`. Calling one the server did
not advertise throws `ServerCapabilityNotSupportedException` before anything reaches the transport. Check
`getServerCapabilities()` first when you need to branch on what the server supports.

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
`NotificationHandlerInterface`; the dispatch table is keyed by method name.

```php
use Nexus\Mcp\Core\Schema\Notification\LoggingMessageNotification;

->addNotificationHandler(LoggingMessageNotification::method(), $myLoggingHandler)
```

A build-time `notifications/progress` handler receives every progress notification whose token is **not**
claimed by an in-flight `callTool(onProgress:)`. The two coexist: per-call `onProgress` takes its own token,
and the build-time handler sees the rest.

## The escape hatch: `sendRequest()`

For spec methods without a convenience wrapper, or to send a pre-built request, call `sendRequest()` with
the request and the expected `Result` class. It returns the `JsonRpcResultResponse<T>` wrapper.

```php
use Nexus\Mcp\Core\Schema\Request\SubscribeRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\SubscribeRequestParams;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;

$response = $client->sendRequest(
    new SubscribeRequest(new RequestId(1), new SubscribeRequestParams('example://resource')),
    EmptyResult::class,
);
```

You supply the `RequestId` yourself here. The auto-incrementing factory backs the typed methods above, and
the same gates apply: any method other than `ping` is rejected until `initialize()` completes. The capability
gate covers exactly the methods behind the typed requests above, so a `tools/list` against a server without
`tools` throws `ServerCapabilityNotSupportedException` (see [Typed requests](#typed-requests)). Any other
method, including the `resources/subscribe` shown here, passes through ungated.

## Lifecycle

1. **`build()`** validates the configuration (client info must be set) and returns a `Client`.
2. **`connect($transport)`** attaches the listener chain, starts the transport, and returns immediately.
3. **`initialize()`** runs the handshake. Exactly once.
4. **Typed requests** correlate inbound responses to awaiting callers by `RequestId`.
5. **Shutdown** is `($transport)->close()`. Pending requests are cancelled with
   `TransportAlreadyClosedException`; in-flight notification handlers drain before the close listeners fire.

## See also

- **[Getting started](getting-started.md)**: install + minimal server.
- **[Server API](server.md)**: the symmetric builder reference.
- **[Transports](transports.md)**: `StdioClientTransport` (subprocess launcher) and the in-memory paired transport.
- **[Architecture](architecture.md)**: dispatch kernel, layering, spec compliance.
