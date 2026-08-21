# Server configuration

Everything `ServerBuilder` takes before any feature is registered.

## Server info

Required before `build()`.

```php
->setServerInfo(
    name: 'my-server',
    version: '1.0.0',
    title: 'My Friendly Server',
    description: 'A short description the server advertises via server/discover.',
    websiteUrl: 'https://example.com',
)
```

The server stamps this identity onto the `_meta` of every result it sends, under the
`io.modelcontextprotocol/serverInfo` key. That is where the spec asks servers to identify themselves. The identity
is self-reported and unverified, so clients are told to treat it as display and logging material rather than as a
behavioural signal.

### Disclosure

`setServerInfoDisclosure()` controls how much of the identity travels, through
`Nexus\Mcp\Server\ServerInfoDisclosure`:

| Case | `server/discover` | Every other result |
| --- | --- | --- |
| `Full` (default) | The whole block | The whole block |
| `NameAndVersion` | The whole block | Only `name` and `version` |
| `None` | Nothing | Nothing |

`NameAndVersion` suits a server with icons and descriptions. The client collects those once at discovery rather
than on every response. `build()` requires `setServerInfo()` under all three cases. `None` simply never sends what
it validated.

```php
->setServerInfo(name: 'my-server', version: '1.0.0')
->setServerInfoDisclosure(ServerInfoDisclosure::NameAndVersion)
```

A handler that sets `serverInfo` on the result's `_meta` itself keeps what it set. The stamp fills an empty slot
rather than overwriting one. That is what lets a proxy forward the identity of the server it fronts, and it is
also how `server/discover` keeps the full block while other results are trimmed.

## Instructions

Optional. Advertised to the client through `server/discover`. Use it to give models guidance about how to use the
server.

```php
->setInstructions('Use the search_docs tool before answering questions about Nexus.')
```

## Logger

Optional. Defaults to `Psr\Log\NullLogger`. Logs go to whatever PSR-3 logger you provide. MCP servers MUST NOT
write to STDOUT outside of the JSON-RPC stream. Target STDERR or a file.

```php
->setLogger($psrLogger)
```

## In-flight dispatch cap

On by default at `ServerBuilder::DEFAULT_MAX_IN_FLIGHT` (1024). Without a cap, a peer that sends faster than the
handlers finish accumulates one coroutine per message until the process runs out of memory.

```php
->setMaxInFlightDispatches(64)   // tighter
->setMaxInFlightDispatches(null) // uncapped, at that risk
```

The default is high enough that a legitimate workload should not reach it.

### Shedding

Past the cap, a request is answered `-32000` (`SdkErrorCode::Overloaded`), and a notification is dropped without
a reply, because JSON-RPC 2.0 §4.1 forbids answering one. Shedding happens before the request ID is claimed, so
the server holds no state for a shed request, and a retry is never rejected as a duplicate.

### Exemptions

Two methods are exempt.

`subscriptions/listen` occupies no slot, and no number of full slots refuses it, on a server that serves it. It
opens a stream rather than being processed (see [Subscriptions](subscriptions.md)). A listen answers instead to a
separate budget of the same size over the listens admitted and not yet started, so listens that arrive faster than
the loop can start them are shed rather than queued without limit. The two budgets are independent, so a listen
can be refused while every slot is free.

`notifications/cancelled` is admitted past the cap only when it frees work: the first one that names a request
in flight, which is cancelled on admission. Any other cancellation meets the cap like any notification, so a flood
of them cannot occupy the memory the cap exists to bound.

### Sizing the cap

A handler that waits on a human holds its slot for the whole wait. Return an
[`InputRequiredResult`](input-required.md) or start a [task](tasks.md) instead. Both complete the request and free
the slot while the work continues.

Pick a number from what your handlers cost, not from the request rate. The cap counts handlers that run
concurrently, and it releases as each one finishes. The budget is shared, so a registered notification handler
occupies a slot for as long as it runs. A notification whose method has no handler costs nothing.

### Over Streamable HTTP

Over Streamable HTTP, a shed request carries `503 Service Unavailable` under the default `ResponseMode::Auto` and
under `ResponseMode::Json`. Under `ResponseMode::Sse` it carries `200` with the error in a stream frame, as every
dispatcher-produced error does there. An SSE response commits its status when the stream opens, before any frame
exists. A shed `subscriptions/listen` carries `200` in every mode, since the transport streams that method
whatever it is set to. Front a proxy that keys on `503` with `Auto` or `Json`.

This composes with `RequestBodySizeLimitMiddleware`, which caps a single body rather than concurrency.
