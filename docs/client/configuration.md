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
