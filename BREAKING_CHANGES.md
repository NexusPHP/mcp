# Breaking changes

This file is the upgrade guide: what breaks at each version boundary and how to migrate. The policy
for *when* breaking changes may land and how they are communicated lives in
[VERSIONING.md](VERSIONING.md).

## v0.8.0 to Unreleased

### Constructor and entry-point parameters declare their narrow types

Value objects used to accept a wide `string` and narrow it on the property, so a caller learned the
real contract only by reading the class. The narrow type now sits on the parameter, where the call
site can see it. Runtime behaviour is unchanged: the same assertions ran before, one frame later.

What changes is static analysis. PHPStan now reports a plain `string` passed where a
`non-empty-string` is required, across the schema classes and the public surface that feeds them:
`ServerBuilder::setServerInfo()`, `ClientBuilder::setClientInfo()`, `Client::callTool()`,
`Client::getPrompt()`, `Client::readResource()`, the `#[AsTool]` / `#[AsPrompt]` / `#[AsResource]` /
`#[AsResourceTemplate]` / `#[AsServer]` attributes, and the resource read surface
(`ResourceReaderInterface`, `TemplatedResourceReaderInterface`, `ResourceStoreInterface`,
`ResourceTemplateStoreInterface`, and the `\Closure` types `ServerBuilder::addResource()` and
`addResourceTemplate()` accept).

Narrow at your own boundary rather than at the call:

```php
// before: $name came from config typed as string
$builder->addTool($name, ...);
// after
Assert::that($name)->isNonEmptyString('Tool name must be a non-empty string.');
$builder->addTool($name, ...);
```

An attribute argument is a compile-time constant, so `#[AsTool(name: '')]` is now a static error at
the declaration instead of a throw during discovery.

### Resource request params enforce the spec's `uri` format

`ResourceRequestParams` and `ReadResourceRequestParams` hold `uri` to the RFC 3986 absolute-URI
shape the spec's `format: uri` fixes. `Resource` already enforced it on the emit side, so this makes
the two directions symmetric.

A `resources/read` naming an empty, relative, or non-ASCII URI is now refused when the params are
decoded rather than reaching the store. The JSON-RPC error code is unchanged (`-32602`), and only
the message differs, since `ResourceNotFoundException` already mapped to `InvalidParams`. Custom
`ResourceStoreInterface` implementations keyed on non-absolute URIs become unreachable and need
absolute ones. Client side, `Client::readResource()` throws locally instead of sending.

### `ScopeSet` enforces its element type

`ScopeSet` documented `list<non-empty-string>` but checked nothing, so a wrong element type
travelled as far as the token request. The constructor now rejects it. Keyed arrays are still
accepted, since the constructor reindexes.

### `MissingSuggestedDependencyException` moved to `Core`

The exception raised when a suggested package is missing is no longer server-specific: the
client-side `ClientAssertionSigner` raises it for `firebase/php-jwt` just as
`JwksAccessTokenValidator` does. It moved to the cross-cutting namespace:

```php
// before
\Nexus\Mcp\Server\Exception\MissingSuggestedDependencyException
// after
\Nexus\Mcp\Core\Exception\MissingSuggestedDependencyException
```

Update any `catch` around constructing `JwksAccessTokenValidator`, or a `ClientCredentialsGrant`
that signs `private_key_jwt` assertions. Catching `Nexus\Mcp\Core\Exception\McpExceptionInterface`
keeps working either way.

## v0.7.0 to v0.8.0

### The tasks extension identifier moved to a vocabulary class

`TasksServerExtension::IDENTIFIER` no longer exists. The identifier now lives on the extension's
vocabulary class, mirroring the MCP Apps shape:

```php
// before
TasksServerExtension::IDENTIFIER
// after
\Nexus\Mcp\Extension\Tasks\Tasks::IDENTIFIER
```

## v0.6.0 to v0.7.0

### Custom handler registration names the envelope class

`ServerBuilder::addRequestHandler()` / `addNotificationHandler()` and their `ClientBuilder`
counterparts now require the `JsonRpcRequest` / `JsonRpcNotification` subclass that parses the
registered method (the `ClientBuilder` notification variant only for non-spec methods). The class
must declare the same method it is registered for. Previously a vendor-method handler was
unreachable: the parser answered `-32601` before dispatch ever consulted the handler map.

```php
// before
->addRequestHandler('acme/lookup', new MyLookupHandler())
// after
->addRequestHandler('acme/lookup', new MyLookupHandler(), AcmeLookupRequest::class)
```

The `replace*` variants are unchanged, since a replaced spec method keeps its registry envelope
class.

## v0.5.0 to v0.6.0

This boundary is the no-compatibility cut from MCP **2025-11-25** to MCP **2026-07-28**. The SDK
tracks exactly one protocol revision, so everything shaped by the old revision changed in one move.
A v0.5.0 client cannot talk to a server built from this line, and vice versa.

### The initialization handshake is gone

The 2026-07-28 protocol is sessionless: every request is self-describing through its `_meta`
lifecycle fields, which the SDK stamps for you.

- `Client::initialize()` no longer exists. Call `connect($transport)` and start issuing requests.
  Server identity and capabilities arrive via `Client::discover()` (`server/discover`), which also
  populates `getServerInfo()` / `getServerCapabilities()`.
- `notifications/initialized` is gone with it, as is `UnsupportedProtocolVersionException` at
  handshake time. A protocol-version mismatch is now a per-request error
  (`-32022 UnsupportedProtocolVersion`).
- Sessions are gone: `TransportInterface::getSessionId()` was removed and no `Mcp-Session-Id`
  header exists.
- `ping` was removed from the protocol. `Client::ping()` no longer exists. Liveness is the
  transport's concern (the Streamable HTTP transport uses SSE keep-alives).

### Roots, sampling, and logging are removed

SEP-2577 deprecates all three and SEP-2596 says new implementations should not adopt deprecated
features, so this SDK omits them entirely.

- `Client::setLoggingLevel()` and the `logging/setLevel` method are gone. Servers no longer emit
  `notifications/message`. The `LoggingLevel` enum survives only to round-trip the deprecated
  `_meta` `logLevel` field, readable server-side as `$context->meta->logLevel`.
- `sampling/createMessage` and `roots/list` no longer exist as server-to-client requests. There are
  no client-side handlers to register for them. A server that needs client input uses the
  input-required flow below.
- The `sampling`, `roots`, and `elicitation` client capabilities are no longer declared by the
  client, and the server's `logging` capability slot is gone.

### Servers never send requests, results can ask for input instead

SEP-2322 (MRTR) replaces server-initiated requests with the `InputRequiredResult` flow. The
`ServerRequest` union is gone from the schema. Its replacement, `InputRequest`, is a payload inside
a result, not a dispatchable request.

- `Client::callTool()`, `readResource()`, and `getPrompt()` now return a union with
  `InputRequiredResult`. Branch on the type: when the server needs input first, collect the answers
  described by `$result->inputRequests` and call the same method again with `inputResponses:` and
  the echoed `requestState:`. See [docs/client/input-required.md](docs/client/input-required.md).
- Server-side tool executors, prompt renderers, and resource readers may return
  `InputRequiredResult` to ask for input. See
  [docs/server/input-required.md](docs/server/input-required.md).
- `Client::sendRequest()` now takes the expected response class
  (`sendRequest($request, ReadResourceResultResponse::class)`) plus an optional `SendContext` and
  per-request timeout.

### Cacheable results require cache hints

SEP-2549 makes `ttlMs` and `cacheScope` required on `ReadResourceResult` and every `*/list` result.

- Constructing `ReadResourceResult` (and `ListToolsResult`, `ListPromptsResult`,
  `ListResourcesResult`, `ListResourceTemplatesResult` directly) now requires
  `ttlMs: <int>` and `cacheScope: CacheScope::Public|Private`.
- Stores the builder assembles from `add*()` entries default to `ttlMs: 0` /
  `CacheScope::Private`. Change the defaults with `ServerBuilder::setTtlMs()` /
  `setCacheScope()`, or per feature by constructing the store yourself.

### Resource subscriptions became `subscriptions/listen`

SEP-2575 removes `resources/subscribe` / `resources/unsubscribe` in favour of one filtered listen
stream.

- Client side: `Client::listen(SubscriptionFilter $notifications, \Closure $onNotification)`
  returns a `SubscriptionStream`. The filter names which notification types the subscription wants
  (`toolsListChanged`, `promptsListChanged`, `resourcesListChanged`, `resourceSubscriptions`).
- Server side: register a `SubscriptionStore` via `ServerBuilder::setSubscriptionStore()`.
  `list_changed` notifications now exist and fire when the built-in stores are mutated at runtime,
  and the matching `listChanged` capability flags are advertised only when genuinely deliverable.
- The `resources.subscribe` capability now means "the server honours `resourceSubscriptions` on a
  listen filter", not the removed RPC pair.

### Streamable HTTP requests carry required headers

SEP-2243 applies to the (new) Streamable HTTP transport only. Stdio framing is unchanged.

- Every HTTP request carries `MCP-Protocol-Version` and `Mcp-Method`, plus `Mcp-Name` for
  `tools/call`. The SDK's transports add and validate these for you, so this only affects
  hand-rolled HTTP callers.
- A tool argument annotated `x-mcp-header` in its `inputSchema` is mirrored into an
  `Mcp-Param-{Name}` header by the client and validated against the body by the server. A mismatch
  is refused with `-32020 HeaderMismatch`.

### Error codes

- New protocol codes: `-32020 HeaderMismatch`, `-32021 MissingRequiredClientCapability`,
  `-32022 UnsupportedProtocolVersion` (see `ProtocolErrorCode`).
- `-32042 UrlElicitationRequired` was removed with the elicitation reshape.
- Reading an unknown resource now answers `-32602` with the requested URI in `error.data.uri`
  (SEP-2164) instead of an empty `contents` list.
