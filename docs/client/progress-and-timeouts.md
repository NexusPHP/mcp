# Progress and timeouts

Long-running calls stream progress while they run, and every request is bounded by a deadline.

## Streaming progress from `callTool`

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

## Request timeouts

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
