# Transports

A transport is the bytes-in / bytes-out layer between an MCP server and its client. The SDK ships one
production transport today (`StdioServerTransport`) plus an in-memory pair for tests
(`InMemoryTransport::createPair()`).

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
    public function getSessionId(): ?string;

    public function onMessage(\Closure $listener): SubscriptionInterface;
    public function onError(\Closure $listener): SubscriptionInterface;
    public function onDrain(\Closure $listener): SubscriptionInterface;
    public function onClose(\Closure $listener): SubscriptionInterface;
}
```

The four `on*` methods are listener registration. The `Server` registers listeners for `onMessage` (dispatch),
`onError` (log), `onDrain` (await in-flight coroutines), and `onClose` (resolve the run-future) once,
before calling `start()`.

`getSessionId()` returns the transport's session identifier when there is one. Stdio servers run one process
per session, so the stdio transport returns `null`. Streamable HTTP will populate this once the transport
lands.

`SendContext` carries `relatedRequestId`, which ties an out-of-band message (such as a progress
notification) to the in-flight request that triggered it. Further transport-specific routing fields can
arrive through the same value object without changing the interface shape.

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
    maxLineBytes: 4_194_304, // optional cap on a single inbound line; default 4 MiB
);
```

The `stdin` / `stdout` parameters take `Amp\ByteStream\ReadableStream` and `WritableStream`
implementations, not raw PHP stream resources. The defaults are `new ReadableResourceStream(\STDIN)` and
`new WritableResourceStream(\STDOUT)` from `amphp/byte-stream`, the wrappers that adapt the live process
streams to the event loop.

Behaviour:

- **Framing**: line-framed JSON-RPC. One envelope per line on STDIN. One per line on STDOUT.
- **Line cap**: each inbound line is capped at `$maxLineBytes` (default 4 MiB). A line that exceeds the
  cap before its `\n` arrives raises a read error and unwinds the loop, so a peer cannot exhaust memory
  with an unterminated stream.
- **Read loop**: spawned by `start()`. Each line is parsed as JSON. Lines that fail to decode are answered
  with a `-32700 ParseError` response. Lines that decode but are not JSON objects (including JSON-RPC
  batches, which the SDK does not accept) are answered with a `-32600 InvalidRequest` response. Valid
  envelopes are emitted to `onMessage` listeners.
- **Output**: every `send()` writes a single line ending in `\n`. The underlying `WritableResourceStream`
  is flushed per write.
- **EOF**: when STDIN closes, the read loop unwinds. Its `finally` fires `onDrain` listeners (so the
  dispatcher can await its pending coroutines) and then calls `close()`.
- **Close**: idempotent. Fires `onDrain` then `onClose`, transitions to the `Closed` state. Subsequent
  `send()` or `start()` calls throw `TransportAlreadyClosedException`. If a concurrent close (e.g. EOF on
  the read loop) lands while a `send()` is suspended in the byte-stream `write()`, the resulting stream
  failure is wrapped into `TransportAlreadyClosedException` (with the original throwable preserved as
  `getPrevious()`) so callers can demote uniformly. The transport also emits a per-message-shape DEBUG
  log on that path carrying the request id, method, and the underlying throwable, so operators retain a
  granular audit trail even though the dispatcher reports the symptom at INFO.
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

## `StdioClientTransport`

```php
use Nexus\Mcp\Client\Transport\StdioClientTransport;
use Psr\Log\NullLogger;

$transport = new StdioClientTransport(
    command: ['php', 'examples/stdio-server.php'],  // argv. No shell interpretation
    workingDirectory: null,                          // optional cwd; defaults to current
    env: null,                                       // optional; null prunes to a safe allowlist
    logger: $psrLogger,                              // optional; default: NullLogger
    maxLineBytes: 4_194_304,                         // optional cap; default 4 MiB
);
```

Launches an MCP server as a subprocess and exchanges line-framed JSON-RPC envelopes over its
STDIN/STDOUT. Subprocess STDERR is forwarded line-by-line through the supplied PSR-3 logger at INFO
level.

Behaviour:

- **Launch**: `start()` invokes `Amp\Process\Process::start(...)` with the supplied command. The first
  array element is the executable. The rest are arguments. There is no shell interpretation, so pass
  arguments separately to avoid quoting bugs.
- **Environment**: `env: null` (the default) passes a pruned allowlist of safe names (`PATH`, `HOME`,
  `TERM`, …) drawn from the parent, dropping everything else (such as secrets) and skipping exported
  shell-function values. An empty array (`env: []`) inherits the full parent environment. A non-empty array
  is passed verbatim.
- **Framing**: same line-framed JSON-RPC as the server transport. Outbound writes go to the subprocess's
  stdin. Inbound lines come from its stdout.
- **STDERR**: a second pump runs in parallel and forwards every stderr line to the logger as
  `info('Subprocess stderr: {line}', ['line' => $line])`. Each line is sanitised first (non-printable bytes
  escaped to `\xNN`, capped at 80 bytes) so a hostile subprocess cannot smuggle control sequences into the
  logs. Errors during the stderr pump log a warning but do not affect transport state.
- **Close**: closes the subprocess's stdin (signalling EOF), then sends `SIGKILL` if the subprocess is
  still running. `SIGTERM` would be preferable but `amphp/process` runs subprocesses behind a shell
  wrapper that ignores `SIGTERM`, so `SIGKILL` is the only signal guaranteed to terminate the child.

## `InMemoryTransport` (test only)

Two linked transports that deliver each other's `send()` calls as `onMessage` events. Useful for
end-to-end server tests without spawning a subprocess.

```php
use Nexus\Mcp\Core\Transport\InMemoryTransport;

[$serverSide, $clientSide] = InMemoryTransport::createPair();
```

### Pre-`start()` inbound queueing

Envelopes sent to a transport that has not yet called `start()` are queued. They drain in arrival order
the moment the side starts. This lets a test pre-load the full request sequence before `Server::run()`
wires its listeners and calls `start()` on the server-side transport:

```php
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Transport\InMemoryTransport;
use Nexus\Mcp\Server\ServerBuilder;

[$serverSide, $clientSide] = InMemoryTransport::createPair();

// Capture every server response delivered to the client side.
$received = [];
$clientSide->onMessage(static function (array $envelope) use (&$received): void {
    $received[] = $envelope;
});

// Start the client and pre-load a request. `$serverSide` is still Idle.
// Every $clientSide->send() call here queues onto `$serverSide`'s pendingInbound list.
$clientSide->start();

$meta = new RequestMetaObject(
    protocolVersion: new ProtocolVersion(version: ProtocolVersion::LATEST_VERSION),
    clientInfo: new Implementation(name: 'client', version: '1.0.0'),
    clientCapabilities: new ClientCapabilities(),
);
$clientSide->send(new DiscoverRequest(id: new RequestId(id: 1), params: new EmptyRequestParams(meta: $meta)));

// Run the server. `Server::run()` calls `$serverSide->start()`, which drains the queue
// in arrival order into the dispatcher's onMessage listener.
$server = new ServerBuilder()->setServerInfo(name: 'test', version: '0.1.0')->build();
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

### `getSessionId()` and `onError`

`getSessionId()` returns `null` (no session concept in-process). `onError` accepts listeners for
`TransportInterface` conformance but never fires (there is no I/O failure surface for an in-process pair).

## Streamable HTTP

Not yet shipped. The transport-interface surface above is intentionally shaped to accommodate it without
breaking changes:

- `getSessionId()` is already optional.
- `SendContext` exists as the slot for HTTP-specific fields.
- `onDrain` is symmetric with `onClose` so streaming responses can be flushed cleanly.

## See also

- **[Getting started](getting-started.md)**: minimal server with stdio.
- **[Server API](server.md)**: builder reference, request/notification handlers, capability advertisement.
- **[Client API](client.md)**: client builder + typed request reference.
- **[Architecture](architecture.md)**: dispatch kernel internals.
