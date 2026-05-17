# Transports

A transport is the bytes-in / bytes-out layer between an MCP server and its client. The SDK ships one
production transport today (`StdioServerTransport`) plus an in-memory pair for tests
(`InMemoryTransport::pair()`).

## The contract

Every transport implements
[`Nexus\Mcp\Core\Transport\TransportInterface`](../src/Core/Transport/TransportInterface.php). It is a
small synchronous-looking surface around an async event loop.

```php
interface TransportInterface
{
    public function start(): void;
    public function send(JsonRpcMessage $message, ?SendContext $context = null): void;
    public function close(): void;
    public function sessionId(): ?string;

    public function onMessage(\Closure $listener): SubscriptionInterface;
    public function onClose(\Closure $listener): SubscriptionInterface;
    public function onError(\Closure $listener): SubscriptionInterface;
    public function onDrain(\Closure $listener): SubscriptionInterface;
}
```

The four `on*` methods are listener registration. The `Server` wires listeners for `onMessage` (dispatch),
`onError` (log), `onDrain` (await in-flight coroutines), and `onClose` (resolve the run-future) once,
before calling `start()`.

`sessionId()` returns the transport's session identifier when there is one. Stdio servers run one process
per session, so the stdio transport returns `null`. Streamable HTTP will populate this once the transport
lands.

`SendContext` is currently an empty value object. It is the slot through which streamable-HTTP fields
(`relatedRequestId`, resumption tokens) will arrive without changing the interface shape.

## `StdioServerTransport`

```php
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Nexus\Mcp\Server\Transport\StdioServerTransport;
use Psr\Log\NullLogger;

$transport = new StdioServerTransport(
    stdin: $stream,          // optional ReadableStream; default: new ReadableResourceStream(\STDIN)
    stdout: $writableStream, // optional WritableStream; default: new WritableResourceStream(\STDOUT)
    logger: $psrLogger,      // optional; default: new NullLogger
);
```

The `stdin` / `stdout` parameters take `Amp\ByteStream\ReadableStream` and `WritableStream`
implementations, not raw PHP stream resources. The defaults are `new ReadableResourceStream(\STDIN)` and
`new WritableResourceStream(\STDOUT)` from `amphp/byte-stream`, the wrappers that adapt the live process
streams to the event loop.

Behaviour:

- **Framing**: line-framed JSON-RPC. One envelope per line on STDIN. One per line on STDOUT.
- **Read loop**: spawned by `start()`. Each line is parsed as JSON. Lines that fail to decode are logged
  at `debug` and skipped. Lines that decode but are not JSON objects are logged at `warning` and skipped.
  Valid envelopes are emitted to `onMessage` listeners.
- **Output**: every `send()` writes a single line ending in `\n`. The underlying `WritableResourceStream`
  is flushed per write.
- **EOF**: when STDIN closes, the read loop unwinds. Its `finally` fires `onDrain` listeners (so the
  dispatcher can await its pending coroutines) and then calls `close()`.
- **Close**: idempotent. Fires `onDrain` then `onClose`, transitions to the `Closed` state. Subsequent
  `send()` or `start()` calls throw `TransportAlreadyClosedException`.
- **STDOUT discipline**: MCP servers MUST NOT write anything to STDOUT outside the JSON-RPC stream. Send
  all diagnostic logs to STDERR via the PSR-3 logger you pass in.

### Stdin / stdout substitution

Useful in tests when you want to drive the transport from synthetic streams:

```php
use Amp\ByteStream\BufferedReader;
use Amp\ByteStream\WritableBuffer;

$reader = new BufferedReader(/* … */);
$writer = new WritableBuffer();
$transport = new StdioServerTransport(stdin: $reader, stdout: $writer);
```

## `InMemoryTransport` (test only)

Two linked transports that deliver each other's `send()` calls as `onMessage` events. Useful for
end-to-end server tests without spawning a subprocess.

```php
use Nexus\Mcp\Core\Transport\InMemoryTransport;

[$serverSide, $clientSide] = InMemoryTransport::pair();
```

### Pre-`start()` inbound queueing

Envelopes sent to a transport that has not yet called `start()` are queued. They drain in arrival order
the moment the side starts. This lets a test pre-load the full request sequence before `Server::run()`
wires its listeners and calls `start()` on the server-side transport:

```php
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\Notification\InitializedNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;
use Nexus\Mcp\Core\Schema\Request\InitializeRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\InitializeRequestParams;
use Nexus\Mcp\Core\Transport\InMemoryTransport;
use Nexus\Mcp\Server\Server;

[$serverSide, $clientSide] = InMemoryTransport::pair();

// Capture every server response delivered to the client side.
$received = [];
$clientSide->onMessage(static function (array $envelope) use (&$received): void {
    $received[] = $envelope;
});

// Start the client and pre-load the initialize handshake. `$serverSide` is still Idle.
// Every $clientSide->send() call here queues onto `$serverSide`'s pendingInbound list.
$clientSide->start();
$clientSide->send(new InitializeRequest(
    new RequestId(1),
    new InitializeRequestParams(/* protocolVersion, capabilities, clientInfo */),
));
$clientSide->send(new InitializedNotification(new EmptyNotificationParams()));

// Run the server. `Server::run()` calls `$serverSide->start()`, which drains the queue
// in arrival order into the dispatcher's onMessage listener.
$server = Server::builder()->setServerInfo(name: 'test', version: '0.1.0')->build();
$serverRun = \Amp\async(static fn() => $server->run($serverSide));

// Close to let run() return. close() cascades to the peer.
$clientSide->close();
$serverRun->await();
```

Without the pre-start queue, the test would have to interleave each emission with the server's setup or
risk sending into a transport whose listener chain isn't wired yet.

### Lifecycle cascade

`close()` cascades to the peer. Closing one side closes the other. On each side, the drain listener chain
fires first, then the close listener chain:

```php
$serverSide->close();
// Order of effects:
//   1. $serverSide drainListeners fire (server dispatcher awaits pending coroutines).
//   2. $serverSide transitions to Closed.
//   3. $clientSide->close() is invoked recursively.
//   4. $clientSide drainListeners fire.
//   5. $clientSide transitions to Closed.
//   6. $clientSide closeListeners fire.
//   7. $serverSide closeListeners fire.
```

### Send / start ordering errors

The state machine rejects out-of-order operations with typed exceptions, so wiring mistakes surface
eagerly rather than as silently dropped envelopes:

| Operation | When it throws | Exception |
| --- | --- | --- |
| `send()` | Called before `start()` | `TransportNotStartedException` |
| `send()` | Called after `close()` | `TransportAlreadyClosedException` |
| `start()` | Called twice | `TransportAlreadyStartedException` |
| `start()` | Called after `close()` | `TransportAlreadyClosedException` |

### `sessionId()` and `onError`

`sessionId()` returns `null` (no session concept in-process). `onError` accepts listeners for
`TransportInterface` conformance but never fires (there is no I/O failure surface for an in-process pair).

## Streamable HTTP

Not yet shipped. The transport-interface surface above is intentionally shaped to accommodate it without
breaking changes:

- `sessionId()` is already optional.
- `SendContext` exists as the slot for HTTP-specific fields.
- `onDrain` is symmetric with `onClose` so streaming responses can be flushed cleanly.

## See also

- **[Getting started](getting-started.md)**: minimal server with stdio.
- **[Server API](server.md)**: builder reference, request/notification handlers, capability advertisement.
- **[Architecture](architecture.md)**: dispatch kernel internals.
