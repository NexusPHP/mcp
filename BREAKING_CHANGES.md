# Breaking changes

This file is the upgrade guide: what breaks at each version boundary and how to migrate. The policy
for *when* breaking changes may land and how they are communicated lives in
[VERSIONING.md](VERSIONING.md).

## v0.11.0 to Unreleased

### `ServerCapabilities` keeps unknown capabilities and models `logging`

`ServerCapabilities` gained a `logging` slot (the spec's deprecated member) and an `extras` slot for
capabilities outside the named set, mirroring `ClientCapabilities`. `logging` sits before `$prompts`,
so positional construction past the third argument changes meaning: use named arguments. Decoding no
longer drops unknown keys, at the top level or nested inside `prompts`, `resources`, and `tools`, so a
re-encoded capabilities object can now carry keys it previously silently lost.

### A custom resource store signals a registry miss with `ResourceNotRegisteredException`

`CompositeResourceStore` used to fall through to templates on any `ResourceNotFoundException`, so a
reader's deliberate refusal was answered by an overlapping template instead of reaching the client. The
fallback now happens only on `ResourceNotRegisteredException`, which the built-in `ResourceStore` throws
for a URI it does not hold.

```php
// before: any not-found fell through to templates
throw new ResourceNotFoundException($uri);
// after: decline, so templates may answer
throw new ResourceNotRegisteredException($uri);
```

A custom `ResourceStoreInterface` implementation composed with a template store must throw
`ResourceNotRegisteredException` for a URI it does not hold. A `ResourceNotFoundException` is now
authoritative and ends the read. A store used without a template store is unaffected, since both encode
the same `-32602` with `data.uri`. `ResourceNotFoundException`'s message also changed, from
`No resource registered under URI "..."` (now the miss exception's message) to `Resource "..." not found.`

### The transport listener handle is renamed `ListenerHandleInterface`

`Core\Transport\SubscriptionInterface` is now `ListenerHandleInterface`, leaving the word "subscription"
to the spec's `subscriptions/listen` feature. Behaviour is unchanged, and the handle still exposes
`dispose()` alone.

```php
// before
public function onMessage(\Closure $listener): SubscriptionInterface;
// after
public function onMessage(\Closure $listener): ListenerHandleInterface;
```

A transport of your own updates the return type on each `on*` registration method and any
`use Nexus\Mcp\Core\Transport\SubscriptionInterface;` import. Code that only *holds* handles keeps
working unchanged if it never names the type.

### `RequestStateSigner` signatures changed shape

The signed material is now length-prefixed with the binding, so a `requestState` minted by an earlier release
no longer verifies. This applies whether or not you adopt bindings, since the prefix is unconditional.

A server keyed by `generate()` is unaffected, its states never outliving the process that minted them. One
keyed from configuration takes the whole break, and that is the topology the shared key exists for: during a
rolling deploy the fleet is mixed-version, so every state minted by an old instance fails at a new one until
the rollout finishes. A handler already treats `null` from `verify()` as "this server did not mint it", which
is the right answer for a stale state too, so the failure is an error the client can restart from, not a crash.

Bind the state to whoever may resume it, since an unbound one is replayable by any caller the server talks to:

```php
$caller = $context->receiveContext->authInfo?->subject ?? '';

$state = $signer->sign('awaiting-confirmation', $caller);
```

See [Asking the client for input](docs/server/input-required.md) for the checking half, which has to treat a
null `requestState` as a first call rather than passing it to `verify()`.

### `VerifiedAccessToken` narrows `$subject` and `$clientId` to `non-empty-string`

Both are documented `null|non-empty-string`, since a claim naming nobody is indistinguishable from an absent
one. The native types are still `?string`, so what changes for a *consumer* is static analysis: a custom
`AccessTokenValidatorInterface` passing a plain `string` or `?string` is flagged, and the fix is the guard the
shipped validator uses.

One observable behaviour did change, and it is called out here rather than buried, since the taxonomy lists
observable behaviour as breaking. `JwksAccessTokenValidator` now reads an empty or non-string `sub`, `azp`,
`client_id` or `cid` as absent, where an empty one previously reached the handler as `''` and a non-string one
masked every later claim in the fallback chain. A handler branching on `null === $token->subject` sees the
first case join the second rather than arriving as an empty string.

```php
$subject = $claims['sub'] ?? null;

subject: is_string($subject) && '' !== $subject ? $subject : null,
```

### The in-flight dispatch cap is on by default, on both peers

`ServerBuilder` and `ClientBuilder` both default `maxInFlight` to `DEFAULT_MAX_IN_FLIGHT` (1024), where the
server previously defaulted to no cap and the client had no cap available at all. Past the cap a request is
answered `-32000` (`SdkErrorCode::Overloaded`) and a notification is dropped, so a peer that sends faster than
your handlers finish now meets backpressure instead of exhausting memory. A subscription delivery is not
silently dropped: shedding one ends its stream, with `SubscriptionStream::await()` throwing
`SubscriptionDeliveryDroppedException`.

A workload legitimately running more than 1024 handlers at once must say so, and can opt out entirely:

```php
->setMaxInFlightDispatches(4_096) // raise it
->setMaxInFlightDispatches(null)  // restore the old uncapped behaviour
```

Two methods are exempt: `subscriptions/listen` on a server that serves it, so a held-open stream occupies no
slot, though listens arriving faster than the loop starts them are shed, and on both peers the first
`notifications/cancelled` naming a request in flight, which is cancelled on admission. Any other cancellation
meets the cap.

A handler that blocks on a human holds its slot for the whole wait. Return an `InputRequiredResult` or start a
task instead, both of which complete the request and free the slot while the work continues.

## v0.10.0 to v0.11.0

### Schema constructors no longer enforce the identifier-name format

`Tool`, `Resource`, `ResourceTemplate`, `Prompt`, `PromptArgument`, `PromptReference`, `ResourceLink`,
`CallToolRequestParams` and `GetPromptRequestParams` used to refuse a `name` outside
`[A-Za-z0-9._-]{1,128}`. The spec puts no `pattern` on `name`, so that rejected spec-legal payloads: a
peer listing `"name": "Project Files"` failed to decode, taking the whole page with it, and a client
could not call such a tool at all.

The format is now held where the SDK *authors* a name instead, in the four `ServerBuilder::add*` methods
and in `ToolStore`, `PromptStore`, `ResourceStore` and `ResourceTemplateStore`. An empty name still
throws everywhere.

```php
// before: threw ExpectationFailedException
new Tool(name: 'Project Files', inputSchema: ['type' => 'object']);
// after: constructs, because a peer may legally send it
```

Migrate only if you relied on the constructor throwing. A store you implement yourself is not covered:
`ToolStoreInterface` and its siblings are your own contract, so validate there if you want the format.

### `AuthorizedHttpClient` takes an `HttpClientBuilder`

It took a built `DelegateHttpClient`, which meant the redirect behaviour of credentialed traffic belonged
to the caller. An HTTP client strips headers on a redirect only when the authority changes, and an
authority carries no scheme, so a hop from `https` to cleartext on the same host kept the `Authorization`
header and put the token on the network in the open. Nothing the decorator could do after the fact
un-sends it.

```php
// before
new AuthorizedHttpClient($endpoint, $options, $login, HttpClientBuilder::buildDefault());
// after
new AuthorizedHttpClient($endpoint, $options, $login, new HttpClientBuilder());
```

The decorator now derives two clients from the builder: metadata discovery follows redirects, and anything
carrying a credential runs on one that does not, resolving each hop itself and refusing any that leaves the
MCP server's origin before the credential travels.

Configure the transport on the builder as before (`usingPool()`, `intercept()`, `interceptNetwork()`,
`retry()`). To route traffic through a client of your own, short-circuit with an `ApplicationInterceptor`,
shown in [docs/auth/client.md](docs/auth/client.md). `TokenEndpoint`, `MetadataDiscovery`,
`ClientRegistrar` and `JsonHttpExchange` still take a `DelegateHttpClient`, but they are `@internal` and
they do not seal anything themselves: composing them by hand puts the redirect behaviour of their
credentials back in your own hands.

### `JwksAccessTokenValidator` requires the issuer it accepts

The validator took a key set and nothing else, so it verified the signature but never checked who signed.
A key set that serves more than one issuer left audience as the only tenant boundary, and a token minted
with no `exp` was accepted forever, because `JWT::decode` enforces expiry only when the claim is present.

Name the issuer as the second constructor argument:

```php
// before
new JwksAccessTokenValidator($keys);
// after
new JwksAccessTokenValidator($keys, 'https://auth.example.com');
```

Use the exact `iss` string your authorization server mints, which for Keycloak is the realm URL
(`https://kc.example.com/realms/mcp`) rather than the JWKS URL. Tokens whose `iss` is absent or different
are now refused, as are tokens carrying no `exp`. If your provider issues tokens without `exp`, that is
the thing to fix: nothing revokes them.

## v0.9.0 to v0.10.0

No breaking changes on the supported surface, and nothing to migrate.

The PHP floor moved from 8.4 down to 8.3. [VERSIONING.md](VERSIONING.md) lists *raising* the floor as
breaking, because it strands installs. Lowering it only widens the constraint, so every environment
that could install v0.9.0 can install this release.

One observable behaviour did change, and it is called out here rather than buried because the taxonomy
lists observable behaviour as breaking. A client now answers a misrouted request-shaped envelope with
`-32600` instead of dropping it silently. This is not a covered-surface break: `ClientMessageDispatcher`
is `@internal`, and the behaviour it replaces was a JSON-RPC violation, so the only way to depend on the
old shape was to rely on the SDK failing to reply where the spec obliges one. Peers that already handled
the compliant server-side behaviour need no change, because the client now matches it.

## v0.8.0 to v0.9.0

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
