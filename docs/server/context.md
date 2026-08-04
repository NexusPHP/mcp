# What `ServerContext` exposes to a handler

Every handler closure receives a `ServerContext` as its last argument.

| Property | Purpose |
| --- | --- |
| `$context->requestId` | The originating `RequestId`. |
| `$context->cancellation` | An `Amp\Cancellation` token. Pass it to any `await()` so client `notifications/cancelled` can interrupt long-running work. |
| `$context->meta` | The request's `_meta` object: the client's `protocolVersion` and `clientCapabilities`, the optional `clientInfo` and `logLevel`, plus `progressToken`. Read client capabilities per request, never inferred from a prior one. |
| `$context->receiveContext` | What the transport knew about the delivery. Over Streamable HTTP that is `request` (the PSR-7 `ServerRequestInterface`) and `authInfo` (the `VerifiedAccessToken` an authentication middleware verified, see [authorization](../auth/server.md#reading-the-token-in-a-handler)). Both are `null` over stdio. |
| `$context->inputResponses` | The client's answers to a prior `InputRequiredResult`, keyed by the identifiers that result assigned, or `null` on a first call. |
| `$context->requestState` | The opaque continuation token that result carried, echoed back unchanged, or `null` on a first call. |

`$context->meta->logLevel` is the revision's per-request replacement for the removed `logging/setLevel`
method: the client's requested minimum level for this request, as a `LoggingLevel` case, or `null` when
the request carried none. The SDK parses and re-encodes the field but attaches no behaviour to it. The
[PSR-3 logger](configuration.md#logger) logs at whatever level it is configured with, so honouring the
request is a handler's own choice.

## Reporting progress

`reportProgress(float $progress, ?float $total = null, ?string $message = null)` emits a
`notifications/progress` tied to the request being handled:

```php
executor: static function (?array $args, ServerContext $context): CallToolResult {
    $steps = ['fetch', 'transform', 'store'];

    foreach ($steps as $i => $step) {
        runStep($step);
        $context->reportProgress(progress: (float) ($i + 1), total: (float) count($steps), message: $step);
    }

    return new CallToolResult(content: [new TextContent(text: 'Done.')]);
},
```

The spec has `progress` increase with every notification, and `total` and `message` are optional. When the
original request carried no `progressToken` in its `_meta`, the call is a no-op, so a handler reports
unconditionally and the client decides whether progress flows by supplying the token. The client-side
counterpart is [streaming progress from `callTool`](../client/progress-and-timeouts.md#streaming-progress-from-calltool).

## Cancellation

Over stdio, an inbound `notifications/cancelled` naming an in-flight request fires that request's
`$context->cancellation`. A handler that threaded the token into its awaits is interrupted with a
`CancelledException`, and whatever the handler was going to answer is dropped: the spec forbids responding
to a request the client cancelled, and the dispatcher enforces that whether the handler threw or ran to
completion. A cancellation naming an id the server does not know is ignored, as the spec asks, since the
request may simply have finished first.

```php
executor: static function (?array $args, ServerContext $context): CallToolResult {
    $rows = fetchSlowly($query, $context->cancellation);

    return new CallToolResult(content: [new TextContent(text: renderRows($rows))]);
},
```

Over Streamable HTTP the client closes the response stream instead, and an inbound
`notifications/cancelled` is ignored there: the client numbers requests in its own id space, not the one
the server dispatches under (see [subscriptions](subscriptions.md) for the same rule on listen streams).
The client-side half is [request timeouts](../client/progress-and-timeouts.md#request-timeouts), whose
elapsed deadline is what sends `notifications/cancelled`.
