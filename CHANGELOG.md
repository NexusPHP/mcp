# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
aims to follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html) from `1.0.0` onward. While
in `0.x`, minor releases may include breaking changes.

## [Unreleased](https://github.com/NexusPHP/mcp/commits/1.x)

### Changed

- The PHP floor is **8.3**, down from 8.4, so the SDK installs into anything still receiving PHP
  security fixes. Lowering a floor breaks nobody, so this needs no migration. The 8.4-only syntax it
  used is gone: asymmetric visibility, the `Icons` interface property hook, paren-less
  `new Foo()->bar()`, and `array_any` / `array_all`. `Icons` is now a pure marker interface, its
  implementors each declaring the `icons` property that `SchemaConformanceTest` already holds to the
  spec. `RequestDeadline::$elapsed` became `readElapsed()`, and the CS preset moved to `Nexus83`. CI
  runs the suite on 8.3, 8.4, and 8.5.
- A client now answers a misrouted request-shaped envelope instead of dropping it. JSON-RPC 2.0 §4.1
  splits request from notification on the envelope's `id` and never on the method it names, so an
  envelope carrying an `id` is a request whatever method it names and §5 obliges a reply. The client
  dispatcher applied that rule to its malformed-envelope arm but not to its misrouted-method arm, so
  a peer sending `notifications/cancelled` with an `id` waited on a reply that never came. It now
  receives `-32600` echoing the id, matching what the server dispatcher already did. An envelope
  carrying no `id` is still dropped and logged.

## [v0.9.0](https://github.com/NexusPHP/mcp/compare/v0.8.0...v0.9.0) - 2026-08-06

This release closes the extensions backlog with the two ratified OAuth extensions, client
credentials (SEP-1046) and enterprise-managed authorization (SEP-990), completing the final block of
the 2026-07-28 migration. Both are unattended machine grants that run in place of the
authorization-code round trip, so the client's grant-strategy seam ships as public API alongside
them: an OAuth grant this SDK does not model is a class you can write yourself. Three breaking
changes ride along (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)). Constructor parameters now
declare the narrow types their properties always carried, which changes nothing at runtime but
surfaces in a consumer's static analysis. Resource request params hold `uri` to the RFC 3986 shape
the spec's `format: uri` fixes. And `ScopeSet` enforces its element type rather than only
documenting it. With the extensions block complete, v1.0.0 is gated only on the component split.

### Added

- The OAuth client credentials extension (`io.modelcontextprotocol/oauth-client-credentials`,
  SEP-1046) under `Nexus\Mcp\Extension\Auth\ClientCredentials`: `ClientCredentialsGrant` runs the
  unattended machine-to-machine grant with either a `ClientSecretCredential` (HTTP Basic) or a
  `PrivateKeyJwtCredential` (RFC 7523 client assertion, signed through the suggested
  `firebase/php-jwt` package), enforcing the `token_endpoint_auth_methods_supported` discovery
  signal. `ClientCredentialsClientExtension` and `ClientCredentialsServerExtension` declare the
  settings-free capability.
- The enterprise-managed authorization extension
  (`io.modelcontextprotocol/enterprise-managed-authorization`, SEP-990) under
  `Nexus\Mcp\Extension\Auth\Enterprise`: `IdentityAssertionGrant` exchanges the sign-on's identity
  assertion for an ID-JAG at the enterprise IdP (RFC 8693) and redeems it at the resource
  authorization server (RFC 7523 JWT-bearer), with `IdentityAssertionProviderInterface` as the
  consumer's seam to its own sign-on. `EnterpriseAuthorizationClientExtension` and
  `EnterpriseAuthorizationServerExtension` declare the settings-free capability.
- A grant-strategy seam in the client authorization subsystem: `AuthorizedHttpClient` accepts a
  `grantStrategy:` argument (with a now-nullable user authorization) that replaces the
  authorization-code round trip, and a strategy that renews by fresh grant is rerun when its
  token expires instead of sending the request bare.
- `AuthorizationServerMetadata` reads `token_endpoint_auth_methods_supported`,
  `token_endpoint_auth_signing_alg_values_supported`, `grant_types_supported`, and
  `authorization_grant_profiles_supported`, and `TokenEndpointAuthMethod` gained the
  `private_key_jwt` case.
- The three referee scenarios for these extensions (`auth/client-credentials-basic`,
  `auth/client-credentials-jwt`, `auth/enterprise-managed-authorization`) run via
  `composer conformance:extensions:client` and score into a new client-extensions badge.
- The client's grant-strategy seam is public API, so an OAuth grant the SDK does not model can be
  written by a consumer. `GrantStrategyInterface` and `GrantContext` are supported surface, along
  with the types a grant reads: `DiscoveredResource`, `AuthorizationServerMetadata`,
  `ProtectedResourceMetadata`, `ResourceIdentifier`, and `ScopeSet`. See
  [docs/client/auth-extensions.md](docs/client/auth-extensions.md#writing-your-own-grant).

### Changed

- `GrantContext` exposes the two authorization-server calls a grant is built from as
  `resolveRegistration()` and `requestToken()`, rather than the `ClientRegistrar` and
  `TokenEndpoint` collaborators that answer them. Those two stay `@internal` and keep changing
  freely, and the built-in authorization-code grant runs on the same public surface a third-party
  grant does.
- `AuthorizationOptions::$redirectUri` is nullable, for grants that never visit an authorization
  endpoint. A client built with a user authorization is still held to carrying one, now refused at
  construction rather than at the first challenge, and Dynamic Client Registration refuses to run
  without one instead of registering a null redirect URI.
- An unattended grant is rerun on expiry whether or not the token carried a refresh token. The
  registration a refresh resolves is not the one such a strategy authenticates with, and the rerun
  reuses the discovery that just checked the spent token rather than discovering again without a
  challenge.
- `AuthorizationOptions` refuses a pre-registered client whose token endpoint authentication is
  `private_key_jwt`, which no built-in grant can present an assertion for.
- The enterprise grant validates its IdP token endpoint when constructed and takes its own
  `allowInsecureLoopback` flag, and a non-JSON error body from the IdP is reported as a failed
  exchange naming the status rather than as a malformed response.
- `MissingSuggestedDependencyException` moved from `Nexus\Mcp\Server\Exception` to
  `Nexus\Mcp\Core\Exception`. It is raised by a client-side signer as well as by the server's JWKS
  validator, so the server namespace filed it by its first consumer rather than by what it means
  (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)).
- Constructor parameters carry the narrow type their property always had, and are promoted, so the
  contract reads at the call site instead of on a separate property declaration. The same narrowing
  reaches the entry points that feed those constructors: the builders' `set*Info()`, the typed
  `Client` calls, the discovery attributes, and the resource read surface. No runtime behaviour
  changes, but PHPStan now reports a plain `string` passed where a `non-empty-string` is required
  (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)). A new
  `PromotableConstructorPropertyRule` keeps the shape from drifting back.
- `ResourceRequestParams` and `ReadResourceRequestParams` hold `uri` to the RFC 3986 absolute-URI
  shape the spec's `format: uri` fixes, matching what `Resource` already enforced when emitting one.
  An empty or malformed URI is refused when the params decode rather than at the store, keeping the
  `-32602` code it already produced.
- `ScopeSet` rejects an element that is not a non-empty string instead of documenting the type and
  checking nothing.
- Decoding a schema payload reports a missing value as `must be a non-empty string` where it
  previously said `must be a string`, and an empty value now fails at the decoder rather than one
  frame later in the constructor. Exception messages sit outside the compatibility
  promise ([VERSIONING.md](VERSIONING.md)), but tests pinning them will need updating.

## [v0.8.0](https://github.com/NexusPHP/mcp/compare/v0.7.0...v0.8.0) - 2026-08-05

This release ships the second official extension, MCP Apps (SEP-1865). It is metadata and
negotiation only: the extension defines no JSON-RPC methods, and the `ui/*` postMessage family is
the browser host's side of the spec, so a PHP server or client is complete without it. The
conformance referee carries no apps scenarios at its current pin, so the extension ships without
a conformance claim. One breaking change rides along: the tasks extension identifier moved to a
vocabulary class (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)).

### Added

- The MCP Apps extension (`io.modelcontextprotocol/ui`, SEP-1865) under `Nexus\Mcp\Extension\Apps`.
  The extension defines no JSON-RPC methods: `AppsServerExtension` advertises the capability slot,
  and the typed `_meta.ui` value objects (`UiToolMeta`, `UiResourceMeta`, `UiResourceCsp`,
  `UiResourcePermissions`) model the tool-to-view link and the sandbox configuration on both the
  `resources/list` descriptor and the `resources/read` contents. `UiResource` composes a `Resource`
  that enforces the `ui://` scheme and the `text/html;profile=mcp-app` mime type at construction.
- The client half of MCP Apps: `AppsClientExtension` advertises the renderable `mimeTypes` in the
  per-request `_meta` capabilities, and the `AppClient` facade resolves tool and resource `_meta.ui`
  metadata (tolerating the deprecated flat `ui/resourceUri` key on read, never emitting it), filters
  a listing to UI-enabled tools paired with their resolved metadata (`AppTool`), and reads `ui://`
  resources verified against the facade's accepted mime types
  (`InvalidUiResourceContentsException` on drift). The `ui/*` postMessage family is host-side and
  deliberately unmodelled.

### Changed

- The tasks extension identifier moved from `TasksServerExtension::IDENTIFIER` to the new
  vocabulary class `Nexus\Mcp\Extension\Tasks\Tasks`, mirroring the MCP Apps shape, so the client
  half no longer imports a server-namespace class.

## [v0.7.0](https://github.com/NexusPHP/mcp/compare/v0.6.0...v0.7.0) - 2026-08-05

This release introduces the SEP-2133 extensions framework and the first official extension built on
it, tasks (SEP-2663). Two notes for consumers. Task status updates are delivered by polling only:
SEP-2663 makes `notifications/tasks` optional, so this is a conformant shape rather than a partial
one, and a later release may add push delivery. And `RequestHandlerDecoratorInterface` ships in the
same release that first consumes it, so treat its shape as provisional while `0.x` lasts.

### Added

- The SEP-2133 extensions framework. An extension is a first-class object
  (`ServerExtensionInterface` / `ClientExtensionInterface`) declaring its capability identifier,
  settings, and the methods it owns with their envelope classes and handlers, enabled via
  `ServerBuilder::enableExtension()` and `ClientBuilder::enableExtension()`. Extensions are
  disabled by default, and every declaration is validated at enable time: identifier grammar,
  settings shape, class-to-method identity, and collisions against the specification, other
  extensions, and builder-registered handlers.
- Extension capability negotiation on both sides. The server advertises enabled extensions in the
  `server/discover` capabilities and answers an extension-owned request from a client whose
  per-request `_meta` capabilities did not declare the extension with `-32021`, naming the missing
  member (`extensions.{identifier}`). The client advertises enabled extensions in every request's
  `_meta`, refuses an extension's declared outbound methods against a server that did not
  advertise it, and answers an extension-owned inbound request from such a server with `-32601`.
- `Client::stampMeta()` and `Client::mintRequestId()` are public, so hand-built `sendRequest()`
  requests can carry the same lifecycle `_meta` and id scheme the typed methods use.
- The tasks extension (`io.modelcontextprotocol/tasks`, SEP-2663) under
  `Nexus\Mcp\Extension\Tasks`. `TasksServerExtension` serves `tasks/get`, `tasks/update`, and
  `tasks/cancel`, and brokers `tools/call` into long-running tasks per the given
  `ToolTaskPolicy` map: a task-supporting tool answers a declaring client with a flat
  `CreateTaskResult` and runs in a background fiber whose outcome settles the store record. Tool
  errors settle as `completed` with `result.isError`, protocol errors as `failed`, and terminal
  states are sticky with TTL retention anchored at the terminal transition.
  `TasksClientExtension` advertises the capability and gates the outbound `tasks/*` methods, and
  the `TaskClient` facade calls tools as tasks (continuation parameters included, so an
  `InputRequiredResult` round can be re-issued through the facade), polls at the server-suggested
  interval, answers `input_required` rounds through a resolver, throws `StalledTaskException`
  past its stall ceiling, and aborts on a caller-supplied `Amp\Cancellation`.
- `RequestHandlerDecoratorInterface`, the extension framework's seam for wrapping the handler
  that serves a spec-registry method at `build()` time. Decorator groups compose with the
  last-enabled extension outermost, and the tasks extension uses it to broker `tools/call`.
- `ResultType::Task` and the `TaskHandleResult` marker, routing extension-defined task handles
  through `ResultResponseFactory` on the send path.
- The SEP-2243 `Mcp-Name` routing header now mirrors `params.taskId` on the tasks methods, on
  both the client build path and the server validation path.

### Changed

- `ServerBuilder::addRequestHandler()` / `addNotificationHandler()` and their `ClientBuilder`
  counterparts take the envelope class that parses the registered method, and the server variant
  requires request classes to implement the `ClientRequest` marker
  (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)). On the client, a spec method keeps its
  registry envelope class and a different class is refused.

### Fixed

- Vendor-method handlers registered via `addRequestHandler()` / `addNotificationHandler()` were
  unreachable: the parser answered `-32601` before dispatch ever consulted them. Registered
  envelope classes now feed the message parser on both sides.
- A vendor or extension handler returning a result outside the spec's typed pairs was refused as
  an internal error. Non-spec methods now wrap any result in the generic response envelope.
- A request parsed by a user-registered class without `RequestParams`-shaped params crashed into a
  `-32603` internal error in production. The server now rejects it as `-32600` with the echoed id.

## [v0.6.0](https://github.com/NexusPHP/mcp/compare/v0.5.0...v0.6.0) - 2026-08-03

The tracked MCP specification moves from **2025-11-25** to **2026-07-28** with no compatibility
layer, so this boundary is breaking throughout. [BREAKING_CHANGES.md](BREAKING_CHANGES.md) is the
upgrade guide. The entries below are the inventory.

### Added

- The Streamable HTTP server transport. `StreamableHttpServerTransport` is a PSR-15 handler, and
  `SecuredHttpEndpoint` wraps it in the recommended middleware: CORS for browser clients,
  DNS-rebinding Origin protection with opt-in Host allow-listing, a request-body size limit
  answering `413`, `Mcp-Param-{Name}` header validation against tool-call arguments, and an
  optional authentication slot. Responses stream as SSE with keep-alives or buffer as JSON per
  response mode, and `Server::listen()` attaches the dispatcher without blocking, for hosts that
  drive the transport per request.
- The Streamable HTTP client transport. `StreamableHttpClientTransport` POSTs one envelope per
  exchange, parses SSE responses frame by frame so progress notifications arrive mid-call, mirrors
  `x-mcp-header` tool arguments into `Mcp-Param-{Name}` headers, retries a call once after a
  `HeaderMismatch` answer by re-listing the tool, and retries once with a protocol version the
  server named as supported. Outbound requests are bounded by a progress-aware deadline configured
  via `ClientBuilder::setRequestTimeout()` / `setMaxRequestTimeout()`. An exchange whose answer
  cannot settle the request it carries fails it with `UnexpectedHttpStatusException` instead of
  leaving the caller waiting.
- The OAuth 2.1 client half. `AuthorizedHttpClient` decorates the HTTP client the transport already
  takes: on a `401` it discovers the protected-resource and authorization-server metadata, resolves
  a client identifier through pre-registration, a Client ID Metadata Document, or Dynamic Client
  Registration, runs the PKCE (S256) authorization-code exchange through a caller-supplied
  `UserAuthorizationInterface`, validates the response `iss`, binds the token to the MCP server it
  was issued for, and replays the request. Scope selection follows the challenge, then
  `scopes_supported`, then the caller's `defaultScopes`. An insufficient-scope answer steps the
  scopes up under a capped budget or is reported via `InsufficientScopeException` per
  `InsufficientScopePolicy`. `offline_access` and refresh tokens are opt-in. Tokens and client
  registrations persist through `TokenStoreInterface` / `ClientRegistrationStoreInterface`, and
  everything that writes a token is serialised behind one cancellable lock.
- The OAuth 2.1 resource-server half. `BearerAuthenticationMiddleware` validates bearer tokens
  through an `AccessTokenValidatorInterface`, binds the token audience to the server's canonical
  URI, and enforces required scopes. `ProtectedResourceMetadataHandler` serves the RFC 9728
  metadata document at its two well-known paths. The validated `VerifiedAccessToken` reaches
  handlers as `$context->receiveContext->authInfo`.
- `JwksAccessTokenValidator`, a shipped JWT validator riding the suggested `firebase/php-jwt`
  package. It checks signature and expiry against a caller-supplied key set and maps the claim
  spellings the common providers use (`scope`/`scp`, `azp`/`client_id`/`cid`). Constructing it
  without the package installed throws `MissingSuggestedDependencyException` naming the install
  command.
- `subscriptions/listen` on both sides. `Client::listen(SubscriptionFilter, \Closure)` opens a
  notification stream and returns a `SubscriptionStream` handle. The server serves it from a
  `SubscriptionStore` registered via `ServerBuilder::setSubscriptionStore()`. The built-in stores
  became runtime-mutable (`addTool()` / `removeTool()` and siblings on the mutable store
  interfaces), fire `list_changed` notifications onto open streams, and the matching `listChanged`
  capability flags are advertised only when genuinely deliverable.
- The input-required flow (multi round-trip requests). `callTool()`, `readResource()`, and
  `getPrompt()` can answer with an `InputRequiredResult` describing the input the server needs, and
  the caller answers by calling again with `inputResponses:` and the echoed `requestState:`.
  Server-side executors, renderers, and readers may return `InputRequiredResult` to ask.
- Completion registration grew a fluent surface and attribute sugar:
  `ServerBuilder::addPromptCompletion()` / `addResourceTemplateCompletion()` take a closure or a
  `CompletionProviderInterface` per argument, and `#[AsCompletion]` (repeatable) marks a method as
  a provider discovered through `ServerBuilder::register()`. Registering any completion advertises
  the capability.
- Stdio client supervision. A stdio subprocess that exits unexpectedly is respawned behind the same
  transport under a time-bounded restart budget. Read-only requests lost to the crash are retried
  against the replacement peer when `ClientBuilder::setRetryLostRequests()` allows, and open
  subscription streams are re-opened against it.
- Server-side lifecycle guards: a handler can refuse a request needing an undeclared client
  capability (`MissingRequiredClientCapabilityException`), the per-request `_meta` protocol version
  is gated, and the new protocol error codes (`-32020 HeaderMismatch`,
  `-32021 MissingRequiredClientCapability`, `-32022 UnsupportedProtocolVersion`) are modelled on
  `ProtocolErrorCode`.
- `ServerBuilder` configuration: `setPageSize()`, `setTtlMs()`, and `setCacheScope()` set the list
  pagination and cache hints for every store assembled from `add*()` entries. `getToolStore()`,
  `getPromptStore()`, `getResourceStore()`, `getResourceTemplateStore()`, and
  `getCompletionStore()` expose the assembled stores. `setMaxInFlightDispatches()` caps concurrent
  dispatches, and `setServerInfoDisclosure()` controls how much identity `server/discover` reveals.
- `ClientBuilder::setClientCapabilities()` declares the client's capabilities, carried on every
  request's `_meta`.
- Tool schemas accept full JSON Schema 2020-12 (SEP-2106): every top-level keyword (`$defs`,
  `allOf`, conditionals, `additionalProperties`) is preserved verbatim through listing and
  validation.
- Streamable HTTP examples (`examples/http-server.php`, `examples/http-client.php`) and a
  dockerised Keycloak end-to-end example (`examples/keycloak-e2e/`) walking discovery, anonymous
  Dynamic Client Registration, PKCE, and the token exchange against a real authorization server.

### Changed

- `Client::discover()` (`server/discover`) replaces the `initialize` handshake as the way to learn
  the server's identity and capabilities, and the SDK stamps the per-request `_meta` lifecycle
  fields (protocol version, client info, client capabilities) on every outbound request.
- Every result carries the required `resultType` discriminator, and cacheable results
  (`ReadResourceResult` plus the four list results) require the SEP-2549 `ttlMs` and `cacheScope`
  hints.
- `Client::sendRequest()` takes the expected response-envelope class, an optional `SendContext`,
  and an optional per-request timeout.
- The `ServerRequest` marker interface is renamed `InputRequest`, and elicitation is remodelled as
  bare `InputRequest` / `InputResponse` bodies riding `InputRequiredResult`. The concrete
  `*ResultResponse` envelopes moved to the `Core\Schema\ResultResponse` namespace.
- A misrouted envelope is decided by its `id` alone, never the method name: an id-less envelope is
  a notification and goes unanswered, an id-carrying one is answered, uniformly across every
  parse-failure arm.
- Reading an unknown resource answers `-32602` with the requested URI in `error.data.uri`
  (SEP-2164) instead of an empty `contents` list.

### Removed

- The `initialize` / `notifications/initialized` handshake, `Client::initialize()`, and
  handshake-time version negotiation. Sessions are gone with it: `TransportInterface` carries no
  session id and no `Mcp-Session-Id` header exists.
- `ping` and `Client::ping()`, removed by the 2026-07-28 revision.
- Roots, Sampling, and the Logging emission path, deprecated by SEP-2577 and omitted by this
  greenfield SDK per SEP-2596: `roots/list`, `sampling/createMessage`, `logging/setLevel`,
  `Client::setLoggingLevel()`, `notifications/message`, and the matching capability slots. The
  `LoggingLevel` enum survives only to round-trip the deprecated `_meta` `logLevel` field.
- `resources/subscribe` and `resources/unsubscribe`, replaced by `subscriptions/listen`.
- The task methods from core (they return as the `io.modelcontextprotocol/tasks` extension),
  `Tool.execution` with its `ToolExecution` / `TaskSupport` types, the
  `ElicitationCompleteNotification`, the `elicitationId` field, and the
  `UrlElicitationRequiredError` (`-32042`) mechanism.

### Fixed

- A handler argument-binding failure on an attribute-discovered method is reported as a JSON-RPC
  `-32602` Invalid Params error naming the offending argument, instead of surfacing as an internal
  error.
- `Resource::$size` is typed `int` per the spec's `integer`, and `NumberSchema` bounds plus
  `ElicitResult` numeric content accept floats per the spec's `number`.

## [v0.5.0](https://github.com/NexusPHP/mcp/compare/v0.4.0...v0.5.0) - 2026-06-01

### Added

- An online [API reference](https://nexusphp.github.io/mcp-sdk/) for the public `Nexus\Mcp\` API, generated
  from the source with ApiGen and published to GitHub Pages on every push to `1.x`.

### Fixed

- The `#[InputSchema]` attribute's `definition` parameter no longer claims to be a full schema override in
  its PHPDoc. Only the `type`, `$schema`, `properties`, and `required` keys of a supplied `definition` reach
  the advertised tool `inputSchema`, which the annotation now documents.
- A discovered tool that returns an array the adapter cannot map (a non-empty list whose items are not all
  content blocks, or an integer-keyed array that is not valid structured content) now throws
  `UnsupportedReturnValueException` naming the handler method, instead of a bare `ExpectationFailedException`,
  matching every other unsupported-return path.
- A JSON-RPC error response whose `error.data` is a non-object JSON value (string, number, list, or boolean)
  no longer fails to decode. The spec types `Error.data` as `unknown`, so `Error::$data` and the response
  parser now accept any JSON value instead of rejecting a non-object. Previously the client discarded such a
  response without rejecting the correlated request, leaving the caller's `await()` unresolved.
- A peer-supplied `jsonrpc` version value carrying control characters is now escaped (non-printable bytes
  rendered as `\xNN`) in the version-mismatch error message instead of being emitted raw, consistent with how
  the method-name fields are already rendered. This stops a peer from forging newlines in plain-text sinks
  that record the rejection.
- A failed transport send during `Client::sendRequest()` or `Client::initialize()` no longer leaves the
  correlated request registered. Previously, if `send()` threw after the request was registered (for example,
  writing to an already-closed transport), the pending-request entry was never freed, slowly growing the
  correlation map. The client now releases the registration before propagating the send failure.

## [v0.4.0](https://github.com/NexusPHP/mcp/compare/v0.3.0...v0.4.0) - 2026-05-30

### Added

- Attribute-based registration. Mark methods with `#[AsTool]`, `#[AsPrompt]`, `#[AsResource]`, or
  `#[AsResourceTemplate]`, and a class with `#[AsServer]`, then register the object via
  `ServerBuilder::register(object ...$sources)`. A tool's `inputSchema` and a prompt's `arguments` are
  inferred from the method signature and `@param` docblocks (overridable per parameter with
  `#[InputSchema]`). A `ServerContext` parameter is injected, and a plain return value (string, content
  block, or schema object) is adapted to the matching result.
- `#[AsServer]` supplies the server identity and instructions. An explicit `setServerInfo()` /
  `setInstructions()` call wins per field and the attribute fills only the gaps, regardless of call order.
  More than one `#[AsServer]` across registered sources throws `DuplicateServerMetadataException`.
- A variadic parameter on an `#[AsTool]` method maps to an array input and is spread back into the call. The
  same parameter on a prompt, resource, or resource template throws `UnsupportedVariadicParameterException`.
- `ServerBuilder::register()` rejects a source that carries no `#[AsServer]` and no attribute-marked method
  with `MissingDiscoveryAttributeException`, catching typo'd attribute names and objects passed in by mistake.
- An `#[AsTool]` parameter typed as an instantiable class is expanded into an object input schema built from
  the class constructor, and the handler receives a constructed instance. Expansion is one level deep: a
  nested object, a list of objects, an interface, or an abstract class is not expanded and throws.
- `ServerBuilder::setToolStore()`, `setPromptStore()`, `setResourceStore()`, and `setResourceTemplateStore()`
  swap in a custom store implementation, replacing the in-memory one built from the matching `addTool()` /
  `addPrompt()` / `addResource()` / `addResourceTemplate()` entries. These mirror the existing
  `setCompletionStore()`.

### Changed

- Mutation testing no longer times out while covering the shutdown coroutine drain.
- Public accessors now follow a verb-first naming scheme: `Request`/`Notification::getMethod()` (was
  `method()`), the protocol exceptions' `getErrorCode()` (was `errorCode()`),
  `TransportInterface::getSessionId()` (was `sessionId()`), `BaseMetadata`/`Tool::getDisplayName()` (was
  `displayName()`), and `InMemoryTransport::createPair()` (was `pair()`).

### Removed

- The static `Server::builder()` / `Client::builder()` factories. Construct the builders directly with
  `new ServerBuilder()` / `new ClientBuilder()`.

## [v0.3.0](https://github.com/NexusPHP/mcp/compare/v0.2.0...v0.3.0) - 2026-05-25

### Added

- `Client::getServerCapabilities()` returns the `ServerCapabilities` negotiated during the handshake, or
  `null` before it completes.
- The client now gates typed requests on the server's advertised capabilities: calling a method whose
  capability the server did not advertise (e.g. `tools/list` without a `tools` capability) throws
  `ServerCapabilityNotSupportedException` before the request reaches the transport. `ping` is never gated.

### Fixed

- Closing a `StdioClientTransport` whose subprocess is still running no longer fatals on PHP builds
  without the `pcntl` extension (such as Windows). The transport now terminates the subprocess via
  `Process::kill()` instead of the `SIGKILL` constant.

### Changed

- `StdioClientTransport` now prunes the spawned subprocess environment by default instead of inheriting the
  full parent environment. The `env` constructor argument changed from `array $env = []` to
  `?array $env = null`: `null` (default) passes a safe allowlist (`PATH`, `HOME`, `TERM`, …) drawn from the
  parent and skips exported shell-function values. An empty array still inherits the full parent
  environment. A non-empty array is passed verbatim.

## [v0.2.0](https://github.com/NexusPHP/mcp/compare/v0.1.0...v0.2.0) - 2026-05-24

### Fixed

- Sampling requests (`sampling/createMessage`) no longer reject a `temperature` value outside `0.0` to
  `2.0`. The MCP schema sets no bound on the field, so the previous range rejected spec-valid input.
- Sampling requests (`sampling/createMessage`) no longer reject a negative `maxTokens`. The MCP schema
  sets no minimum on the field.
- A request envelope with a malformed `id` (wrong type or empty string) now returns a `-32600` Invalid
  Request error instead of `-32602` Invalid Params, matching JSON-RPC 2.0.
- Shutdown now drains handler coroutines spawned while an earlier batch of in-flight work is
  still being awaited, instead of returning before they complete.

### Removed

- `HandlerRegistry::methods()`, an unused accessor returning the registered method names.

## [v0.1.0](https://github.com/NexusPHP/mcp/releases/tag/v0.1.0) - 2026-05-23

### Added

- MCP server runtime: `ServerBuilder` plus `Server`, with tools, prompts, resources (static and RFC
  6570 templated), completions, logging, and ping handlers against MCP spec 2025-11-25.
- MCP client runtime: `ClientBuilder` plus `Client`, with the `initialize` handshake, typed request
  methods (`listTools`, `listResources`, `listResourceTemplates`, `listPrompts`, `readResource`,
  `getPrompt`, `complete`, `callTool` with streaming progress, `ping`, `setLoggingLevel`), and the
  `sendRequest` escape hatch.
- Tool I/O JSON Schema validation: `tools/call` arguments are checked against the tool's `inputSchema`
  and a result's `structuredContent` against its `outputSchema`, backed by `opis/json-schema` and
  pluggable via `SchemaValidatorInterface` / `ServerBuilder::setSchemaValidator()`.
- A tool returning `structuredContent` with no content blocks gets a serialised-JSON `TextContent`
  mirror for backwards compatibility.
- Stdio transport for both server and client, plus an in-memory paired transport for tests.
- JSON-RPC 2.0 envelope and MCP schema types under `Nexus\Mcp\Core`.
- Every SDK exception implements `McpExceptionInterface`, so consumers can catch all SDK errors in one
  block.
