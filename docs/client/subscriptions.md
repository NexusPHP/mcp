# Subscriptions

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
- **A delivery shed at the [in-flight dispatch cap](configuration.md#in-flight-dispatch-cap) ends the
  stream.** A lost delivery cannot be detected from the ones that follow it, so the client fails the stream
  rather than leaving it silently stale: `await()` throws `SubscriptionDeliveryDroppedException`, and
  `listen()` again resubscribes.
- **A restart does not spend the stream.** Behind a `SupervisedTransport`, an open stream is re-sent to each
  replacement peer under the same subscription id, so the callback keeps firing and `await()` resumes rather
  than settling. A server that *refuses* the subscription has answered it, so that still ends the stream on
  any transport. See [Subscriptions across a restart](../transports/supervised.md#subscriptions-across-a-restart) for the
  full list of what ends a stream for good.

[`examples/subscriptions.php`](../../examples/subscriptions.php) runs both halves in one process: a
filtered stream, a runtime tool addition, and a published resource update.
