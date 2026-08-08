# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
aims to follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html) from `1.0.0` onward. While
in `0.x`, minor releases may include breaking changes.

## [Unreleased](https://github.com/NexusPHP/mcp/commits/1.x)

### Changed

- `AuthorizedHttpClient` takes an `HttpClientBuilder` and runs credentialed traffic on a client that
  follows no redirect, so a hop off the MCP server's origin is refused before the credential travels
  (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)).
- `JwksAccessTokenValidator` takes the issuer it accepts and refuses a token whose `iss` is absent or
  different, or which carries no `exp` (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)).

### Fixed

- A JSON Schema property name made only of digits is decoded rather than refused, and re-encodes as an
  object. Covers a tool's `inputSchema` and `outputSchema`, an elicitation's `requestedSchema`, and an
  elicit result's `content`, whose keys are those same names. An empty `content` now emits `{}` too.
  A JSON array arriving in one of those object-typed slots is normalised to an object rather than
  refused, since `json_decode` cannot tell it from an object whose names run `0`…`n-1`. Not yet
  covered: a tool's `arguments` still holds string keys, so a tool declaring such a property cannot
  yet be called.
- A server-assigned `inputRequests` / `inputResponses` key made only of digits is decoded rather than
  refused, and re-encodes as an object. The spec puts no format on those keys, so a server numbering
  them from a counter had its whole multi-round-trip exchange rejected. A JSON array in one of those
  slots is normalised to an object, for the same reason as above.
- A tool's `inputSchema` and `outputSchema` emit an empty sub-schema as `{}` rather than `[]`, at any
  nesting depth, so `{"type":"object","properties":{}}` survives a round trip as valid JSON Schema. An
  elicitation's `requestedSchema` does the same, including when it rides on a `tools/call` or
  `tasks/get` result.
- A `_meta` name made only of digits is decoded rather than refused. `json_decode` turns such a key into
  a PHP int, which the guard read as a malformed object.
- A tool can now return an array, string, number or boolean as its structured content, not only an object.
  `CallToolResult` previously refused everything but an object on both construction and decode.
- A peer's tool, prompt, resource or resource-template name is decoded whatever characters it carries.
  The SDK still holds its stricter handle format on the names it authors, in `ServerBuilder::addTool()`
  and friends. A client can now also call a tool whose name sits outside that format.
- A timestamp carrying any number of fractional-second digits is accepted, so a peer emitting
  microseconds (Python's `isoformat()`) or nanoseconds no longer has the whole payload rejected. Only 0
  to 3 digits parsed before. Anything finer than a microsecond is truncated.
- An emitted timestamp now carries microseconds rather than milliseconds, so a value survives a round
  trip. `2026-03-09T12:00:00.500+00:00` becomes `…12:00:00.500000+00:00`, and a sub-millisecond value
  is no longer flattened to `.000`.
- An ISO 8601 parse failure caused by an overflowed date now names the field that carried it, rather
  than reporting a bare `The parsed date was invalid.`
- A client reconnected to a different server no longer answers with the previous server's identity, nor
  refuses a typed call on the previous server's advertisement. `disconnect()` left both in place.
- A stdio server no longer loses in-flight responses when the transport is closed explicitly.
  `Server::run()` could return while handlers were still running, and their sends were refused because
  the transport went `Closed` before draining.
- A stdio transport closed before it was started, or in the same tick it was started, now fires
  `onDrain` before `onClose` like every other close path.
- A `resources/read` handler receives the client's `inputResponses` and `requestState`. Both were parsed
  from the envelope and then dropped, so a resource could ask for input but never see the answer.
- `BearerAuthenticationMiddleware` refuses a token whose `expiresAt` has passed. It took the validator's
  reported expiry on trust, so a custom validator's lapsed token was served. Pass `expiryLeewaySeconds`
  to match a validator configured for clock skew, such as one setting `JWT::$leeway`.
- A PSR-7 host whose request body cannot rewind can serve tool calls. `ParameterHeaderValidationMiddleware`
  consumed the body while peeking at it, so the transport answered every POST with `-32700 Parse error`.
  A side effect: with a tool store registered, `maxBodyBytes` now also caps a body whose size the host
  cannot report, where such a body previously passed through.
- A discovered tool taking a `mixed` or untyped parameter is callable. It advertised `[]` as that
  property's schema, which is not a JSON Schema, so every `tools/call` failed before the executor ran.
- `Tool` accepts boolean sub-schemas in `inputSchema` and `outputSchema`. One such entry previously
  failed the whole `tools/list` page.

## [v0.10.0](https://github.com/NexusPHP/mcp/compare/v0.9.0...v0.10.0) - 2026-08-07

Ships the PHP 8.3 floor ahead of the stable tag, so it has real exposure before 1.0. No breaking changes.

### Changed

- The PHP floor is **8.3**, down from 8.4. Lowering a floor breaks nobody, so there is nothing to
  migrate. CI runs the suite on 8.3, 8.4 and 8.5.
- `Icons` is a pure marker interface and `RequestDeadline::$elapsed` became `readElapsed()`, replacing
  the 8.4-only property hook and asymmetric visibility they relied on.
- A client answers a misrouted request-shaped envelope with `-32600` echoing the id, matching the server.
  JSON-RPC decides request from notification on the `id`, never the method name. An id-less envelope is
  still dropped.

## [v0.9.0](https://github.com/NexusPHP/mcp/compare/v0.8.0...v0.9.0) - 2026-08-06

Closes the extensions backlog with the two ratified OAuth extensions, completing the 2026-07-28
migration. Three breaking changes ride along (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)).

### Added

- The OAuth client credentials extension (`io.modelcontextprotocol/oauth-client-credentials`, SEP-1046)
  under `Nexus\Mcp\Extension\Auth\ClientCredentials`, authenticating with HTTP Basic or an RFC 7523
  client assertion.
- The enterprise-managed authorization extension
  (`io.modelcontextprotocol/enterprise-managed-authorization`, SEP-990) under
  `Nexus\Mcp\Extension\Auth\Enterprise`, exchanging a sign-on assertion for an ID-JAG and redeeming it
  at the resource authorization server.
- A public grant-strategy seam. `AuthorizedHttpClient` takes a `grantStrategy:`, so an OAuth grant the
  SDK does not model can be written by a consumer. See
  [docs/client/auth-extensions.md](docs/client/auth-extensions.md#writing-your-own-grant).
- `AuthorizationServerMetadata` reads the four `*_supported` discovery fields, and
  `TokenEndpointAuthMethod` gained `private_key_jwt`.
- The three referee scenarios for these extensions, run via `composer conformance:extensions:client`.

### Changed

- `AuthorizationOptions::$redirectUri` is nullable, for grants that never visit an authorization
  endpoint. A client built with a user authorization is still held to carrying one.
- An unattended grant is rerun on expiry whether or not the token carried a refresh token.
- `MissingSuggestedDependencyException` moved from `Nexus\Mcp\Server\Exception` to
  `Nexus\Mcp\Core\Exception` (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)).
- Constructor parameters carry the narrow type their property always had. Nothing changes at runtime,
  but PHPStan now reports a plain `string` passed where a `non-empty-string` is required
  (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)).
- `ResourceRequestParams` and `ReadResourceRequestParams` hold `uri` to the RFC 3986 shape the spec's
  `format: uri` fixes, matching what `Resource` already enforced (see
  [BREAKING_CHANGES.md](BREAKING_CHANGES.md)).
- `ScopeSet` rejects an element that is not a non-empty string.
- A schema payload missing a value now fails at the decoder as `must be a non-empty string`. Messages sit
  outside the compatibility promise, but tests pinning them need updating.

## [v0.8.0](https://github.com/NexusPHP/mcp/compare/v0.7.0...v0.8.0) - 2026-08-05

Ships the second official extension, MCP Apps (SEP-1865). One breaking change rides along (see
[BREAKING_CHANGES.md](BREAKING_CHANGES.md)).

### Added

- The MCP Apps extension (`io.modelcontextprotocol/ui`, SEP-1865) under `Nexus\Mcp\Extension\Apps`. It
  defines no JSON-RPC methods: `AppsServerExtension` advertises the capability, and the typed `_meta.ui`
  value objects model the tool-to-view link and sandbox configuration.
- The client half: `AppsClientExtension` advertises renderable `mimeTypes`, and the `AppClient` facade
  resolves `_meta.ui` metadata, filters listings to UI-enabled tools, and reads `ui://` resources. The
  `ui/*` postMessage family is host-side and deliberately unmodelled.

### Changed

- The tasks extension identifier moved from `TasksServerExtension::IDENTIFIER` to
  `Nexus\Mcp\Extension\Tasks\Tasks`, so the client half no longer imports a server-namespace class.

## [v0.7.0](https://github.com/NexusPHP/mcp/compare/v0.6.0...v0.7.0) - 2026-08-05

Introduces the SEP-2133 extensions framework and the first extension built on it, tasks (SEP-2663).
Task status is delivered by polling only, which SEP-2663 makes conformant.

### Added

- The SEP-2133 extensions framework. An extension declares its capability identifier, settings, and the
  methods it owns, enabled via `ServerBuilder::enableExtension()` / `ClientBuilder::enableExtension()`.
  Extensions are disabled by default and every declaration is validated at enable time.
- Extension capability negotiation on both sides. A server answers an extension-owned request from a
  client that did not declare the extension with `-32021`. A client refuses an extension's outbound
  methods against a server that did not advertise it.
- The tasks extension (`io.modelcontextprotocol/tasks`, SEP-2663) under `Nexus\Mcp\Extension\Tasks`. It
  serves `tasks/get`, `tasks/update` and `tasks/cancel`, and brokers `tools/call` into long-running
  tasks. The `TaskClient` facade calls tools as tasks, polls at the server-suggested interval, and
  answers `input_required` rounds through a resolver.
- `RequestHandlerDecoratorInterface`, the seam for wrapping the handler that serves a spec-registry
  method at `build()` time. Treat its shape as provisional while `0.x` lasts.
- `Client::stampMeta()` and `Client::mintRequestId()` are public, so hand-built `sendRequest()` requests
  carry the same lifecycle `_meta` and id scheme the typed methods use.
- `ResultType::Task` and the `TaskHandleResult` marker, and the `Mcp-Name` header mirrors `params.taskId`
  on the tasks methods.

### Changed

- `ServerBuilder::addRequestHandler()` / `addNotificationHandler()` and their `ClientBuilder`
  counterparts take the envelope class that parses the registered method
  (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)).

### Fixed

- Vendor-method handlers registered via `addRequestHandler()` / `addNotificationHandler()` were
  unreachable: the parser answered `-32601` before dispatch consulted them.
- A vendor or extension handler returning a result outside the spec's typed pairs was refused as an
  internal error. Non-spec methods now wrap any result in the generic response envelope.
- A request parsed by a user-registered class without `RequestParams`-shaped params crashed into a
  `-32603`. It is now rejected as `-32600` with the echoed id.

## [v0.6.0](https://github.com/NexusPHP/mcp/compare/v0.5.0...v0.6.0) - 2026-08-03

The tracked MCP specification moves from **2025-11-25** to **2026-07-28** with no compatibility layer,
so this boundary is breaking throughout. [BREAKING_CHANGES.md](BREAKING_CHANGES.md) is the upgrade
guide. The entries below are the inventory.

### Added

- The Streamable HTTP server transport. `StreamableHttpServerTransport` is a PSR-15 handler, and
  `SecuredHttpEndpoint` wraps it in CORS, DNS-rebinding protection, a body size limit, header
  validation, and an optional authentication slot. Responses stream as SSE or buffer as JSON.
- The Streamable HTTP client transport. `StreamableHttpClientTransport` POSTs one envelope per exchange
  and parses SSE frame by frame, so progress notifications arrive mid-call. Requests are bounded by a
  progress-aware deadline set via `ClientBuilder::setRequestTimeout()`.
- The OAuth 2.1 client half. `AuthorizedHttpClient` discovers metadata on a `401`, resolves a client
  identifier, runs the PKCE (S256) authorization-code exchange through `UserAuthorizationInterface`,
  binds the token to the MCP server it was issued for, and replays the request.
- The OAuth 2.1 resource-server half. `BearerAuthenticationMiddleware` validates bearer tokens, binds
  the audience, and enforces scopes. `ProtectedResourceMetadataHandler` serves the RFC 9728 document.
  The validated token reaches handlers as `$context->receiveContext->authInfo`.
- `JwksAccessTokenValidator`, a JWT validator riding the suggested `firebase/php-jwt` package.
- `subscriptions/listen` on both sides. `Client::listen()` opens a notification stream, and the server
  serves it from a `SubscriptionStore`. The built-in stores became runtime-mutable and fire
  `list_changed` notifications.
- The input-required flow. `callTool()`, `readResource()` and `getPrompt()` can answer with an
  `InputRequiredResult`, and the caller answers by calling again with `inputResponses:` and the echoed
  `requestState:`.
- Completion registration grew `ServerBuilder::addPromptCompletion()` /
  `addResourceTemplateCompletion()` and the repeatable `#[AsCompletion]` attribute.
- Stdio client supervision. A subprocess that exits unexpectedly is respawned behind the same transport
  under a restart budget, with lost read-only requests optionally retried.
- Server-side lifecycle guards and the new protocol error codes (`-32020 HeaderMismatch`,
  `-32021 MissingRequiredClientCapability`, `-32022 UnsupportedProtocolVersion`).
- `ServerBuilder` configuration: `setPageSize()`, `setTtlMs()`, `setCacheScope()`,
  `setMaxInFlightDispatches()`, `setServerInfoDisclosure()`, and the `get*Store()` accessors.
  `ClientBuilder::setClientCapabilities()` declares the client's capabilities.
- Tool schemas accept full JSON Schema 2020-12 (SEP-2106), every top-level keyword preserved verbatim.
- Streamable HTTP examples and a dockerised Keycloak end-to-end example (`examples/keycloak-e2e/`).

### Changed

- `Client::discover()` (`server/discover`) replaces the `initialize` handshake, and the SDK stamps the
  per-request `_meta` lifecycle fields on every outbound request.
- Every result carries the required `resultType` discriminator, and cacheable results require the
  SEP-2549 `ttlMs` and `cacheScope` hints.
- `Client::sendRequest()` takes the expected response-envelope class, an optional `SendContext`, and an
  optional per-request timeout.
- The `ServerRequest` marker is renamed `InputRequest`, and elicitation is remodelled as bare
  `InputRequest` / `InputResponse` bodies riding `InputRequiredResult`.
- A misrouted envelope is decided by its `id` alone, never the method name, uniformly across every
  parse-failure arm.
- Reading an unknown resource answers `-32602` with the requested URI in `error.data.uri` (SEP-2164)
  instead of an empty `contents` list.

### Removed

- The `initialize` / `notifications/initialized` handshake and `Client::initialize()`. Sessions go with
  it: no session id on `TransportInterface`, no `Mcp-Session-Id` header.
- `ping` and `Client::ping()`, removed by the 2026-07-28 revision.
- Roots, Sampling and the Logging emission path, deprecated by SEP-2577 and omitted per SEP-2596. The
  `LoggingLevel` enum survives only to round-trip the deprecated `_meta` `logLevel` field.
- `resources/subscribe` and `resources/unsubscribe`, replaced by `subscriptions/listen`.
- The task methods from core (they return as the tasks extension), `Tool.execution`, the
  `ElicitationCompleteNotification`, and the `UrlElicitationRequiredError` (`-32042`) mechanism.

### Fixed

- A handler argument-binding failure on an attribute-discovered method is reported as `-32602` naming
  the offending argument, instead of surfacing as an internal error.
- `Resource::$size` is typed `int` per the spec's `integer`, and `NumberSchema` bounds plus
  `ElicitResult` numeric content accept floats per the spec's `number`.

## [v0.5.0](https://github.com/NexusPHP/mcp/compare/v0.4.0...v0.5.0) - 2026-06-01

### Added

- An online [API reference](https://nexusphp.github.io/mcp-sdk/) for the public `Nexus\Mcp\` API,
  published to GitHub Pages on every push to `1.x`.

### Fixed

- `#[InputSchema]`'s `definition` is not a full schema override. Only `type`, `$schema`, `properties`
  and `required` reach the advertised `inputSchema`, which the annotation now documents.
- A discovered tool returning an array the adapter cannot map throws `UnsupportedReturnValueException`
  naming the handler method, instead of a bare `ExpectationFailedException`.
- A JSON-RPC error response whose `error.data` is a non-object JSON value no longer fails to decode.
  Previously the client discarded it without rejecting the correlated request, leaving `await()`
  unresolved.
- A peer-supplied `jsonrpc` value carrying control characters is escaped in the version-mismatch error
  message, stopping a peer from forging newlines in plain-text sinks.
- A failed transport send during `Client::sendRequest()` no longer leaves the correlated request
  registered, which slowly grew the correlation map.

## [v0.4.0](https://github.com/NexusPHP/mcp/compare/v0.3.0...v0.4.0) - 2026-05-30

### Added

- Attribute-based registration. Mark methods with `#[AsTool]`, `#[AsPrompt]`, `#[AsResource]` or
  `#[AsResourceTemplate]` and a class with `#[AsServer]`, then register via
  `ServerBuilder::register(object ...$sources)`. A tool's `inputSchema` and a prompt's `arguments` are
  inferred from the signature and `@param` docblocks, overridable with `#[InputSchema]`.
- `#[AsServer]` supplies server identity and instructions, filling only the gaps an explicit
  `setServerInfo()` / `setInstructions()` left. More than one throws `DuplicateServerMetadataException`.
- A variadic parameter on an `#[AsTool]` method maps to an array input and is spread back into the call.
- `ServerBuilder::register()` rejects a source carrying no discovery attribute with
  `MissingDiscoveryAttributeException`.
- An `#[AsTool]` parameter typed as an instantiable class is expanded into an object schema built from
  its constructor. Expansion is one level deep.
- `ServerBuilder::setToolStore()`, `setPromptStore()`, `setResourceStore()` and
  `setResourceTemplateStore()` swap in custom store implementations.

### Changed

- Public accessors follow a verb-first naming scheme: `getMethod()`, `getErrorCode()`,
  `getSessionId()`, `getDisplayName()`, `InMemoryTransport::createPair()`.
- Mutation testing no longer times out while covering the shutdown coroutine drain.

### Removed

- The static `Server::builder()` / `Client::builder()` factories. Construct the builders directly.

## [v0.3.0](https://github.com/NexusPHP/mcp/compare/v0.2.0...v0.3.0) - 2026-05-25

### Added

- `Client::getServerCapabilities()` returns the capabilities negotiated during the handshake, or `null`
  before it completes.
- Typed client requests are gated on the server's advertised capabilities, throwing
  `ServerCapabilityNotSupportedException` before the request reaches the transport. `ping` is never
  gated.

### Changed

- `StdioClientTransport` prunes the subprocess environment by default. `env` changed from
  `array $env = []` to `?array $env = null`: `null` passes a safe allowlist, `[]` still inherits the
  full parent environment, and a non-empty array is passed verbatim.

### Fixed

- Closing a `StdioClientTransport` whose subprocess is still running no longer fatals on builds without
  `pcntl`, such as Windows.

## [v0.2.0](https://github.com/NexusPHP/mcp/compare/v0.1.0...v0.2.0) - 2026-05-24

### Fixed

- `sampling/createMessage` no longer rejects a `temperature` outside `0.0` to `2.0` or a negative
  `maxTokens`. The MCP schema bounds neither field.
- A request envelope with a malformed `id` returns `-32600` Invalid Request instead of `-32602`,
  matching JSON-RPC 2.0.
- Shutdown drains handler coroutines spawned while an earlier batch is still being awaited.

### Removed

- `HandlerRegistry::methods()`, an unused accessor.

## [v0.1.0](https://github.com/NexusPHP/mcp/releases/tag/v0.1.0) - 2026-05-23

### Added

- MCP server runtime: `ServerBuilder` plus `Server`, with tools, prompts, resources (static and RFC 6570
  templated), completions, logging and ping handlers against MCP spec 2025-11-25.
- MCP client runtime: `ClientBuilder` plus `Client`, with the `initialize` handshake, typed request
  methods, and the `sendRequest` escape hatch.
- Tool I/O JSON Schema validation backed by `opis/json-schema`, pluggable via
  `SchemaValidatorInterface`. A tool returning `structuredContent` with no content blocks gets a
  serialised-JSON `TextContent` mirror.
- Stdio transport for both server and client, plus an in-memory paired transport for tests.
- JSON-RPC 2.0 envelope and MCP schema types under `Nexus\Mcp\Core`.
- Every SDK exception implements `McpExceptionInterface`, so consumers can catch all SDK errors in one
  block.
