# Transports

A transport is the bytes-in / bytes-out layer between an MCP server and its client. The SDK ships two
production bindings, stdio and Streamable HTTP, on both the server and the client side, plus an in-memory
pair for tests (`InMemoryTransport::createPair()`).

| Transport | Side | Shape |
| --- | --- | --- |
| [`StdioServerTransport`](#stdioservertransport) | server | Long-lived, newline-delimited JSON over STDIN/STDOUT. Driven by `Server::run()`. |
| [`StdioClientTransport`](#stdioclienttransport) | client | Launches the server as a subprocess and speaks the same framing. |
| [`StreamableHttpServerTransport`](#streamablehttpservertransport) | server | Request-scoped PSR-15 handler. One POST per message. Driven by `Server::listen()`. |
| [`StreamableHttpClientTransport`](#streamablehttpclienttransport) | client | One POST per outbound message, answered by a JSON object or an SSE stream. |
| [`InMemoryTransport`](#inmemorytransport-test-only) | both | Test double pair, no I/O. |

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

    public function onMessage(\Closure $listener): SubscriptionInterface;
    public function onError(\Closure $listener): SubscriptionInterface;
    public function onDrain(\Closure $listener): SubscriptionInterface;
    public function onClose(\Closure $listener): SubscriptionInterface;
}
```

The four `on*` methods are listener registration. The `Server` registers listeners for `onMessage` (dispatch),
`onError` (log), `onDrain` (await in-flight coroutines), and `onClose` (resolve the run-future) once,
before calling `start()`.

`SendContext` carries three slots, all of which a transport is free to ignore:

- `relatedRequestId` ties an out-of-band message (such as a progress notification) to the in-flight request
  that triggered it, so a request-scoped transport can route it onto the right response stream.
- `fromHandler` marks a response a request handler produced, so a request-scoped transport can map it to a
  transport-level status (a handler error rides HTTP 200 with the JSON-RPC error in the body, a protocol
  error gets a real status).
- `headers` carries transport headers the protocol layer computed, currently the `Mcp-Param-{Name}` mirrors
  a `tools/call` derives from its arguments. Stdio ignores them.

Further transport-specific fields can arrive through the same value object without changing the interface
shape.

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

### `onError`

`onError` accepts listeners for `TransportInterface` conformance but never fires (there is no I/O failure
surface for an in-process pair).

## `StreamableHttpServerTransport`

The Streamable HTTP binding is request-scoped: the client sends every JSON-RPC message as its own HTTP POST
to a single MCP endpoint, and the server answers each one with a JSON object or a request-scoped SSE stream.
The SDK does not ship an HTTP server. The transport is a
[PSR-15 `RequestHandlerInterface`](https://www.php-fig.org/psr/psr-15/), so you mount it in whatever host you
already run.

```php
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\Transport\StreamableHttpServerTransport;
use Nyholm\Psr7\Factory\Psr17Factory;

$factory = new Psr17Factory();
$transport = new StreamableHttpServerTransport($factory, $factory);

$server = new ServerBuilder()->setServerInfo('demo', '1.0.0')->build();
$server->listen($transport);   // attaches the dispatcher, does not block

// Then, per inbound HTTP request:
$response = $transport->handle($request);
```

PSR-17 factories are constructor-injected, never discovered. `Server::listen()` is the non-blocking
counterpart to `run()`: it attaches the dispatcher's listeners and starts the transport, then returns, because
the HTTP host owns the loop. Calling `handle()` on a transport that is not running answers `503` rather than
suspending on a response that can never arrive.

[examples/http-server.php](../examples/http-server.php) is a working mount, with
[examples/PsrHttpAdapter.php](../examples/PsrHttpAdapter.php) as the host binding for `amphp/http-server`.
Any PSR-15 host works. What a host must get right is the SSE body: pipe it frame by frame rather than
buffering it, or the progress reports arrive only once the call they describe has finished.

### Response modes

| Mode | Behaviour |
| --- | --- |
| `ResponseMode::Auto` (default) | Buffered JSON, upgraded to SSE the moment a progress notification arrives mid-call. |
| `ResponseMode::Json` | Always buffered. A notification that would need streaming is dropped with a debug log. |
| `ResponseMode::Sse` | Always a stream, opened immediately. |

An SSE response carries `Cache-Control: no-cache`, `Connection: keep-alive`, and `X-Accel-Buffering: no` (which
stops nginx and friends buffering events), plus a `: keep-alive` comment frame whenever a read stays idle past
`keepAliveInterval` (default 15s). Closing the response body is the spec's cancellation signal and retires the
stream.

### Securing the endpoint

The spec requires `Origin` validation and recommends localhost binding and authentication. Those live in
PSR-15 middleware rather than the transport, so you compose only what you need. `SecuredHttpEndpoint` bundles
the recommended stack:

```php
use Nexus\Mcp\Server\Transport\Http\SecuredHttpEndpoint;

$endpoint = new SecuredHttpEndpoint(
    $transport,
    allowedOrigins: ['https://app.example.com'],   // required, or ['*'] to allow any
    responseFactory: $factory,
    streamFactory: $factory,
    allowedHosts: ['mcp.example.com'],             // optional, beyond-spec
    maxBodyBytes: 1_048_576,                       // optional
    toolStore: $tools,                             // required if any tool declares x-mcp-header
    authentication: $bearerMiddleware,             // optional, makes this an OAuth resource server
);
```

Origin allow-listing has no default, so the endpoint cannot be stood up permissively by accident. The
middlewares run outermost-first in this order:

| Middleware | Answers | Notes |
| --- | --- | --- |
| `CorsMiddleware` | `204` to a preflight | Beyond-spec. Reflects an allowed `Origin`, and always emits the `Vary` keys it turns on so a shared cache cannot replay one origin's answer to another. |
| `DnsRebindingProtectionMiddleware` | `403` | The spec's `Origin` MUST. Also carries an opt-in `Host` allow-list. Both match case-insensitively. |
| Your `authentication` middleware | `401` | Added only when you pass one. `BearerAuthenticationMiddleware` is the bundled implementation. It runs before anything reads the body, so an unauthorized request is turned away unparsed. See [authorization](authorization.md). |
| `ParameterHeaderValidationMiddleware` | `400` `-32020` | The spec's server-side `Mcp-Param-{Name}` MUST. Added only when you pass a tool store. |
| `RequestBodySizeLimitMiddleware` | `413` | Added only when you pass a cap. Measures the buffered body, so a streaming body whose size is unknown passes through to the host's own limit. |

To compose your own order, or to add middleware of your own, use `MiddlewarePipeline` directly:

```php
use Nexus\Mcp\Server\Transport\Http\MiddlewarePipeline;

$endpoint = new MiddlewarePipeline($transport, $myAuth, $myRateLimit, $cors);
```

It is re-entrant, so one instance serves concurrent requests: each `handle()` recurses over a fresh immutable
tail rather than mutating a shared cursor.

## `StreamableHttpClientTransport`

Each `send()` is a discrete POST. The response content-type decides how it is read: `application/json` is
buffered and decoded once, `text/event-stream` is parsed frame by frame as it arrives, so progress
notifications surface before the final result.

```php
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Client\Transport\StreamableHttpClientTransport;

$transport = new StreamableHttpClientTransport('https://mcp.example.com/mcp');

$client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
$client->connect($transport);
```

The transport computes the required request-metadata headers from the message body itself, so header and body
cannot disagree: `MCP-Protocol-Version`, `Mcp-Method`, and `Mcp-Name` (base64 sentinel-encoded when the value
is not header-safe, which in practice means a resource URI). It also merges the `Mcp-Param-{Name}` headers the
client mirrored from a `tools/call`, see [Client API](client.md).

Two settings are worth knowing:

- **`readTimeout`** (default 30s) bounds how long a response may stall. It **must exceed the server's SSE
  keep-alive interval**, or a quiet long-lived stream is torn down between keep-alives. amphp's own 10-second
  transfer timeout is disabled outright, since it would sever a healthy stream mid-flight.
- **`client`** accepts any `Amp\Http\Client\DelegateHttpClient`, which is the seam for interceptors, custom
  TLS, or a test double. It defaults to the amphp default client.

`close()` cancels in-flight POSTs rather than awaiting them, because a `subscriptions/listen` stream never
ends on its own.

[examples/http-client.php](../examples/http-client.php) drives the server example over the network, including
the mid-call progress stream and a mirrored `Mcp-Param-Tenant` header.

When one exchange fails while the transport stays healthy (connection refused, TLS failure, an undecodable
buffered body, a read stalled past `readTimeout`), the failure names the request it was carrying, so the
client fails that one caller with an `OutboundRequestFailedException` instead of leaving it awaiting a
response that can no longer arrive. Other in-flight requests are untouched, and a notification, having no
caller, is reported as it stands. Inside an SSE stream a single unreadable frame is reported but does not end
the exchange, since a later frame may still carry the response.

An exchange that *completes* without ever delivering its response (a server that closes the stream early, or
answers `202` to a request) raises nothing to correlate. The client's
[request deadline](client.md#request-timeouts) covers that case, and every other way a peer can go silent.

## See also

- **[Getting started](getting-started.md)**: minimal server with stdio.
- **[Server API](server.md)**: builder reference, request/notification handlers, capability advertisement.
- **[Client API](client.md)**: client builder + typed request reference.
- **[Architecture](architecture.md)**: dispatch kernel internals.
