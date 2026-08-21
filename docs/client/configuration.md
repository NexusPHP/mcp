# Client configuration

Everything `ClientBuilder` takes before `build()`.

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

Optional. Defaults to an empty `ClientCapabilities`. Stamped into every request's `_meta`, so the server can read
what the client supports.

```php
use Nexus\Mcp\Core\Schema\ClientCapabilities;

->setClientCapabilities(new ClientCapabilities(elicitation: []))
```

## Logger

Optional. Defaults to `Psr\Log\NullLogger`. Transport errors and uncaught notification-handler exceptions are
logged here.

```php
->setLogger($psrLogger)
```

## In-flight dispatch cap

On by default at `ClientBuilder::DEFAULT_MAX_IN_FLIGHT` (1024). It bounds what the *server* can make this client
do. Without a cap, a server that emits notifications faster than your listeners return accumulates one coroutine
per message until the process runs out of memory.

```php
->setMaxInFlightDispatches(64)   // tighter
->setMaxInFlightDispatches(null) // uncapped, at that risk
```

### Shedding

Past the cap, a server-to-client request is answered `-32000` (`SdkErrorCode::Overloaded`), and a notification is
dropped without a reply. A client cannot answer a notification, so dropping is the only backpressure available to
it. The first drop logs a warning that names the method. The log is throttled, so it cannot become the flood.

A subscription delivery is the exception. Shedding one [ends its stream](subscriptions.md), and `await()` throws
`SubscriptionDeliveryDroppedException`. Call `listen()` again to resubscribe.

### Sizing the cap

Size it by inbound messages, not by operations. One `tools/call` that reports progress spends a slot per
progress notification, so a handful of concurrent calls can occupy far more than a handful of slots.

`notifications/cancelled` is exempt only when it frees work. The first one that names a request in flight is
admitted past the cap and cancelled on admission. Any other cancellation meets the cap.

## Request-ID and progress-token factories

Optional. Both default to a monotonically-incrementing factory: `1`, `2`, … for request IDs, and `progress-1`,
`progress-2`, … for progress tokens. Override either when you need a different ID scheme, for example UUIDs.

```php
->setRequestIdFactory(static fn(): string => Uuid::v4()->toRfc4122())
->setProgressTokenFactory(static fn(): string => Uuid::v4()->toRfc4122())
```

Each factory is a `\Closure(): (int|non-empty-string)`. It must return a value unique among the requests in
flight at the same time.
