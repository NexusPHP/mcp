# Streamable HTTP transports

The Streamable HTTP binding on both sides. The server is a PSR-15 handler that answers each POST with JSON or a
request-scoped SSE stream. The client sends one POST per message. The shared lifecycle is in
[the transport contract](../transports.md#the-contract).

## `StreamableHttpServerTransport`

The binding is request-scoped. The client sends every JSON-RPC message as its own HTTP POST to a single MCP
endpoint. The server answers each POST with a JSON object or a request-scoped SSE stream. The SDK does not ship
an HTTP server. The transport is a [PSR-15 `RequestHandlerInterface`](https://www.php-fig.org/psr/psr-15/), so
you mount it in whatever host you already run.

```php
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\Transport\StreamableHttpServerTransport;
use Nyholm\Psr7\Factory\Psr17Factory;

$factory = new Psr17Factory();
$transport = new StreamableHttpServerTransport($factory, $factory);

$server = (new ServerBuilder())->setServerInfo('demo', '1.0.0')->build();
$server->listen($transport);   // attaches the dispatcher, does not block

// Then, per inbound HTTP request:
$response = $transport->handle($request);
```

PSR-17 factories are constructor-injected, never discovered. `Server::listen()` is the non-blocking counterpart
to `run()`. It attaches the dispatcher's listeners, starts the transport, and returns, because the HTTP host owns
the loop.

A `handle()` call on a transport that is not running answers `503` rather than suspend on a response that can
never arrive. Closing a running transport settles what is already in flight rather than abandon it. An open SSE
stream reaches end-of-body, and a buffered request still awaiting its response gets that same `503`.

[examples/http-server.php](../../examples/http-server.php) is a working mount, with
[examples/PsrHttpAdapter.php](../../examples/PsrHttpAdapter.php) as the host binding for `amphp/http-server`. Any
PSR-15 host works. What a host must get right is the SSE body. Pipe it frame by frame rather than buffer it, or
the progress reports arrive only once the call they describe has finished.

### Response modes

| Mode | Behaviour |
| --- | --- |
| `ResponseMode::Auto` (default) | Buffered JSON, upgraded to SSE the moment a progress notification arrives mid-call. |
| `ResponseMode::Json` | Always buffered. A notification that would need streaming is dropped with a debug log. |
| `ResponseMode::Sse` | Always a stream, opened immediately. |

An SSE response carries `Cache-Control: no-cache`, `Connection: keep-alive`, and `X-Accel-Buffering: no`, which
stops nginx and friends buffering events. It also emits a `: keep-alive` comment frame whenever a read stays idle
past `keepAliveInterval` (default 15s). Closing the response body is the spec's cancellation signal, and it
retires the stream.

A frame that arrives while `maxBufferedBytes` (default 1 MiB) or more sit unread abandons the stream as a
disconnect would: the request is cancelled, a warning is logged, and the client re-issues the request as the spec
directs for a broken stream. The cap bounds the unread backlog, not the frame size, so a frame that finds the
buffer below the cap always lands. Size it to the slowest reader you expect.

`subscriptions/listen` is the one method whose response mode is not configurable. It always streams, because its
result arrives only when the stream ends.

### Securing the endpoint

The spec requires `Origin` validation and recommends localhost binding and authentication. Those live in PSR-15
middleware rather than the transport, so you compose only what you need. `SecuredHttpEndpoint` bundles the
recommended stack:

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

The `toolStore` must be the one the server serves, or the middleware validates headers against a different set
of tools. `ServerBuilder::getToolStore()` returns it whether you supplied it through `setToolStore()` or the
builder assembled it from `addTool()` and `register()` entries:

```php
$builder = (new ServerBuilder())->setServerInfo('demo', '1.0.0')->register(new WeatherTools());
$server = $builder->build();

$endpoint = new SecuredHttpEndpoint(
    $transport,
    allowedOrigins: ['https://app.example.com'],
    responseFactory: $factory,
    streamFactory: $factory,
    toolStore: $builder->getToolStore(),
);
```

It returns `null` when the server exposes no tools, which is what the `toolStore` argument already means, so an
unconditional call is safe.

#### The middleware order

Origin allow-listing has no default, so the endpoint cannot be stood up permissively by accident. The middlewares
run outermost-first in this order:

| Middleware | Answers | Notes |
| --- | --- | --- |
| `CorsMiddleware` | `204` to a preflight | Beyond-spec. Reflects an allowed `Origin`, and always emits the `Vary` keys it turns on so a shared cache cannot replay one origin's answer to another. |
| `DnsRebindingProtectionMiddleware` | `403` | The spec's `Origin` MUST. Also carries an opt-in `Host` allow-list. Both match case-insensitively. |
| Your `authentication` middleware | `401` | Added only when you pass one. `BearerAuthenticationMiddleware` is the bundled implementation. It runs before anything reads the body, so an unauthorized request is turned away unparsed. See [authorization](../authorization.md). |
| `RequestBodySizeLimitMiddleware` | `413` | Added only when you pass a cap. It runs above every stage below it that reads the body, so an oversized one is refused before it is buffered or decoded. A body whose size the host does not report is read only up to one byte past the cap, so it is held to the same limit without being buffered. |
| `ParameterHeaderValidationMiddleware` | `400` `-32020` | The spec's server-side `Mcp-Param-{Name}` MUST. Added only when you pass a tool store. Bindings are cached, and the cache is dropped whenever the store reports a list change. It buffers the body once and re-seats it, so a host whose body stream cannot rewind still delivers a whole envelope to the transport. |

To compose your own order, or to add middleware of your own, use `MiddlewarePipeline` directly:

```php
use Nexus\Mcp\Server\Transport\Http\MiddlewarePipeline;

$endpoint = new MiddlewarePipeline($transport, $myAuth, $myRateLimit, $cors);
```

It is re-entrant, so one instance serves concurrent requests. Each `handle()` recurses over a fresh immutable tail
rather than mutate a shared cursor.

## `StreamableHttpClientTransport`

Each `send()` is a discrete POST. The response content-type decides how it is read. `application/json` is
buffered and decoded once. `text/event-stream` is parsed frame by frame as it arrives, so progress notifications
surface before the final result.

```php
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Client\Transport\StreamableHttpClientTransport;

$transport = new StreamableHttpClientTransport('https://mcp.example.com/mcp');

$client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
$client->connect($transport);
```

The transport computes the required request-metadata headers from the message body itself, so header and body
cannot disagree: `MCP-Protocol-Version`, `Mcp-Method`, and `Mcp-Name`. The last is base64 sentinel-encoded when
the value is not header-safe, which in practice means a resource URI. The transport also merges the
`Mcp-Param-{Name}` headers the client mirrored from a `tools/call`. See [Client API](../client.md).

`close()` cancels in-flight POSTs rather than await them, because a `subscriptions/listen` stream never ends on
its own.

[examples/http-client.php](../../examples/http-client.php) drives the server example over the network, including
the mid-call progress stream and a mirrored `Mcp-Param-Tenant` header.

### Read timeout

`readTimeout` (default 30s) bounds how long a response may stall. It **must exceed the server's SSE keep-alive
interval**, or a quiet long-lived stream is torn down between keep-alives. amphp's own 10-second transfer timeout
is disabled outright, since it would sever a healthy stream mid-flight.

### The HTTP client seam

`client` accepts any `Amp\Http\Client\DelegateHttpClient`. That is the seam for interceptors, custom TLS, or a
test double. It defaults to the amphp default client.

### Exchange failures

One exchange can fail while the transport stays healthy: a refused connection, a TLS failure, an undecodable
buffered body, a read stalled past `readTimeout`, or a status whose body settles nothing (reported as
`UnexpectedHttpStatusException`). The failure names the request it was carrying, so the client fails that one
caller with an `OutboundRequestFailedException` instead of leaving it to await a response that can no longer
arrive.

A failure status stands only when its body is the JSON-RPC answer to the very request the exchange carries. An
ID-less error envelope, an answer to some other ID, or a `202` to a request all fail the exchange, since no
dispatcher could settle the request from them. Other in-flight requests are untouched. A notification has no
caller, so its failure is reported as it stands. Inside an SSE stream, a single unreadable frame is reported but
does not end the exchange, since a later frame may still carry the response.

An exchange that *completes* without ever delivering its response, such as a server that closes the stream early,
raises nothing to correlate. The client's [request deadline](../client/progress-and-timeouts.md#request-timeouts)
covers that case, and every other way a peer can go silent.

### Aborting one request

The transport implements `AbortableTransportInterface`, so a caller that has given up on a response can stop that
one POST:

```php
$transport->abort($requestId);
```

Each request's exchange runs under its own cancellation composed with the transport's lifetime, so `close()`
still stops everything, and an abort reaches only the named exchange. Aborting a request that was never sent,
already answered, or already aborted does nothing, and an abort is never reported through `onError()`.

`Client` calls it wherever it stops waiting for a response: when a request deadline expires, and when a
`SubscriptionStream` is closed. Telling the server is separate and still happens, through
`notifications/cancelled`. Both matter. The notification asks the server to stop working, and the abort stops the
client reading a stream that a `subscriptions/listen` would otherwise keep open forever.
