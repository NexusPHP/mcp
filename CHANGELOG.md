# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
aims to follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html) from `1.0.0` onward. While
in `0.x`, minor releases may include breaking changes.

## [Unreleased](https://github.com/NexusPHP/mcp/commits/1.x)

### Added

- `EncryptedFileTokenStore` persists tokens to one XChaCha20-Poly1305 encrypted, owner-only file
  (needs `ext-sodium`).
- `AuthorizedHttpClient` takes a `lock` semaphore, so workers sharing a token store can serialise renewals
  across processes.
- `ClientBuilder::setMetaExtrasFactory()` adds per-request `_meta` keys to every outbound request, such as
  the W3C `traceparent`.
- `ClientRegistration` carries `clientSecretExpiresAt`, and a stored registration whose secret has expired is
  registered again instead of presented.

### Changed

- `addRequestHandler()` and `addNotificationHandler()` take the envelope class and the handler, reading the
  method from the class, and extensions declare their classes as a list. See BREAKING_CHANGES.md.
- `ParameterHeaderValidationMiddleware` leaves its decoded envelope on the request under
  `StreamableHttpServerTransport::ENVELOPE_ATTRIBUTE`, so the transport parses a body once.
- `VerifiedAccessToken` requires `expiresAt`, now its second constructor argument, so no validator can hand the
  middleware a token it never checks for expiry. See BREAKING_CHANGES.md.
- `TaskClient` takes `minPollIntervalMs` (default 100) and raises a shorter server-suggested `pollIntervalMs`
  to it.
- `SubscriptionStore` refuses a listen naming more than `maxResourceSubscriptionsPerStream` resource URIs
  (default 256), and delivers a resource update by index rather than by scanning every stream.
- `SecuredHttpEndpoint` caps the request body at 1 MiB by default. Pass `maxBodyBytes: null` to remove the
  cap.
- The SDK's own validation failures are plain `\InvalidArgumentException`s. Only `Assert` raises
  `ExpectationFailedException`, its subclass, so a `catch (\InvalidArgumentException)` still sees both.
- A `resources/read` URI is refused past 8192 bytes at decode, bounding the `data.uri` echo.
- `JwksAccessTokenValidator` takes the resource it protects and refuses a token whose `aud` does not
  name it. See BREAKING_CHANGES.md.
- `SchemaValidatorInterface::validate()` returns `SchemaViolation` objects, and a `tools/call` argument
  failure lists them with their JSON pointers under `data.validation_errors`. See BREAKING_CHANGES.md.

### Fixed

- The streamable HTTP server reads `Accept` as RFC 9110 media ranges, so `*/*` and `application/*` are
  admitted and a `q=0` range is not.
- `InMemoryTaskStore` holds at most `maxRecords` (default 10 000), and below that ceiling `createTask()` reclaims
  in amortised constant time instead of sweeping every record.
- Task fibers are capped by `TasksServerExtension`'s `maxRunningTasks` (default 1024), refusing a further
  task with `-32603` instead of running unbounded.
- An SSE stream whose reader falls behind is abandoned at `maxBufferedBytes` (default 1 MiB) instead of
  buffering without limit.
- OAuth metadata discovery no longer follows redirects, so a hostile origin cannot point a well-known
  probe at an internal host.
- The `Mcp-Param-{Name}` check no longer skips a float or a large integer, and refuses a header whose body
  argument is absent. The client mirrors an integral float as its integer. See BREAKING_CHANGES.md.
- The bearer token is bound to the resource's path, not its whole origin: another path on the same host
  is requested without the credential, and a redirect off the resource is refused. See
  BREAKING_CHANGES.md.
- `ToolAnnotations` accepts `destructiveHint` and `idempotentHint` beside `readOnlyHint: true`, so one
  tool no longer makes a whole `tools/list` undecodable.
- An envelope naming a `method` alongside a `result` or an `error` is refused as an invalid request
  echoing its id, instead of being dropped unanswered.
- A client whose peer answers a pending request with such an envelope now settles the awaiting call
  instead of leaving it to time out.

## [v0.15.0](https://github.com/NexusPHP/mcp/compare/v0.14.0...v0.15.0) - 2026-08-20

The dependency floors are now tested: a prefer-lowest CI leg proved the declared minimums wrong, so
the constraints rise to the oldest versions the suite passes on. `RequestBodySizeLimitMiddleware`
holds a body of unreported size to its cap, the first-run error messages say what to do next, and a
stdio server spawned on Windows inherits its search path (see
[BREAKING_CHANGES.md](BREAKING_CHANGES.md) for `SafeDisplay`'s now-private caps).

### Changed

- Dependency floors are raised to the oldest versions the suite passes on (`amphp/amp` `^3.1.1`,
  `nexusphp/assert` `^1.4`, `revolt/event-loop` `^1.0.8`), and `conflict` blocks the broken
  `amphp/pipeline` `<1.2.1` and `league/uri-interfaces` `<7.6`.
- `RequestBodySizeLimitMiddleware` holds a body of unreported size to the cap, reading at most one
  byte past it, instead of passing it through to the host's own limit.
- `SafeDisplay`'s `MAX_LENGTH` and `MAX_CAUSE_LENGTH` constants are private. See BREAKING_CHANGES.md.
- The tool-not-found, missing-`inputSchema.type`, and unadvertised-capability messages name the fix
  alongside the problem.

### Fixed

- `StdioClientTransport`'s environment allowlist matches names case-insensitively, so a subprocess
  spawned on Windows inherits `Path` instead of starting with no search path.

## [v0.14.0](https://github.com/NexusPHP/mcp/compare/v0.13.0...v0.14.0) - 2026-08-18

Subscription streams are now budgeted per authorized peer alongside the server-wide cap, and a store
reused across transports serves live streams again. A concurrent `close()` blocks until the close
settles on every transport, and `ClientBuilder` refuses registration after `build()`. The extension
contracts move under `Server\Extension` and `Client\Extension` (see
[BREAKING_CHANGES.md](BREAKING_CHANGES.md)).

### Changed

- `ClientBuilder` refuses every registration after `build()`, matching `ServerBuilder`.
- `SubscriptionStoreInterface` gains `reopen()`, called by `Server` on attach, so a store reused on a
  new transport serves live streams again. See BREAKING_CHANGES.md.
- A concurrent `close()` on the HTTP transports and `InMemoryTransport` now blocks until the close
  settles, matching stdio, and `TransportInterface::close()` documents the guarantee.
- The extension contracts move into `Server\Extension` and `Client\Extension`, mirroring
  `Core\Extension`. See BREAKING_CHANGES.md.

### Security

- `SubscriptionStore` budgets streams per authorized peer (`maxSubscriptionsPerPeer`, default 256), so
  one OAuth client cannot exhaust the server-wide subscription cap. See BREAKING_CHANGES.md.

## [v0.13.0](https://github.com/NexusPHP/mcp/compare/v0.12.0...v0.13.0) - 2026-08-14

Peer-visible diagnostics now speak one documented grammar, from schema validation through argument
binding, and datetime fields are held to RFC 3339. The exception surface shrinks with it: twenty-eight
message-only classes collapse into two shared ones, the one breaking change riding along (see
[BREAKING_CHANGES.md](BREAKING_CHANGES.md)). Registration refuses magic-method handlers and duplicate
discovered entries, and extension notifications gain the client-side capability gate.

### Changed

- Twenty-eight message-only exception classes are replaced by `Nexus\Mcp\Core\Exception\LogicException`
  (SDK misuse) and `Nexus\Mcp\Core\Exception\RuntimeException` (flow diagnostics), with messages
  unchanged. See BREAKING_CHANGES.md for the list.

### Fixed

- An extension-owned notification from a server that did not advertise the extension is dropped with
  a warning, matching the request-side gate.
- A tool call refused with a header mismatch keeps that error when the binding refresh also fails,
  chaining the refresh failure as `previous`.
- `readAppResource()` refuses a `ui://` read that returned zero contents, instead of handing the host
  an empty result where it expected a document.
- A discovery attribute on a magic method throws `LogicException` at registration, where
  `#[AsTool]` on `__construct` previously registered a tool that re-ran the constructor on the live
  handler.
- A malformed error response whose recovered id matches a pending client request now fails that request
  with the parse diagnostic, instead of leaving the caller to wait out its deadline.
- Datetime fields are validated against the RFC 3339 grammar before parsing, where timezone names,
  colon-less and hour-only offsets, a space before the offset, and single-digit date or time fields
  previously parsed.
- A discovered handler's binding failure names the argument instead of the parameter's PHP class
  name, and the owning tool, prompt, or resource wraps it with its identity, matching the
  schema-validation stage's messages.
- A discovered handler parameter typed `object` or `\stdClass` now receives the decoded arguments as
  an object, instead of failing the call with a `TypeError`.
- Schema-validation diagnostics for tool arguments and `structuredContent` follow the documented
  message conventions, and report up to eight violations instead of stopping at the first.
- `ServerBuilder::register()` refuses a discovered entry whose key an earlier source already declared,
  throwing `LogicException` naming both sources, instead of silently overwriting.
- A resource template variable name longer than 32 characters is refused at registration, instead of
  compiling to a pattern PCRE rejects so the template silently never matches.
- A tool declaring an `outputSchema` whose non-error result carries no `structuredContent` now fails the
  call like a non-conforming result, instead of passing unvalidated.
- Resource-template matching prefers the template with the most literal characters, so an exact
  `db://literal` is reachable behind an earlier `db://{table}`. Ties keep registration order.
- A discovered `#[AsResourceTemplate]` naming a template variable `uri` is refused at registration,
  instead of the variable silently shadowing the `$uri` parameter's request URI.
- The stdio client's spawn log names only the subprocess binary and its argument count, keeping
  credentials passed in argv out of log records.
- `WWW-Authenticate` parameter values are stripped of the control octets RFC 7230 forbids as they are
  parsed, so a hostile challenge cannot smuggle terminal escapes into logs and exception messages.
- `CompleteRequestParams` normalises a `context` carrying no resolved arguments to null, so the property
  agrees with the encoders that already omitted it.
- `ClientCapabilities` and `ServerCapabilities` keep an empty array nested inside a vendor capability as
  `[]` when encoding. The capability slot itself still encodes as `{}` when empty.
- `Icon`'s constructor applies the same `sizes` list guard as its decoder, so an icon the SDK cannot
  re-read is refused at construction.
- A `close()` re-entered from a listener or a concurrent fiber during the drain no longer fires
  `onDrain` and `onClose` twice on the HTTP transports and `InMemoryTransport`.
- A malformed JSON line on the stdio transports, and a malformed or non-object body on the Streamable
  HTTP server, now reach the `onError` listeners. Before, only the stdio non-object arm did.
- A JSON-RPC version-mismatch error names the offending method when the envelope carries one.
- `SupervisedTransport`'s explicit `close()` fires `onDrain` before `onClose`, and a close before
  `start()` fires both instead of neither, releasing a caller blocked on the close signal.
- A second `start()` on the stdio client, or one after `close()`, is refused before a subprocess is
  spawned, instead of spawning one only to kill it.
- The SSE parser's frame budget restarts at every frame boundary, so a keep-alive-only stream is no
  longer torn down once the comments accumulate past the cap.
- The SSE parser no longer loses a chunk-final carriage return when the next chunk is empty, which split
  one multi-line frame into two.
- A readable stream whose `close()` throws no longer costs the stdio transports their drain: the failure
  is logged and the drain proceeds.
- A fault thrown by an `InMemoryTransport` message listener stays on the receiving side's `onError`,
  instead of surfacing through the peer's `send()`.
- `ElicitResult` accepts an empty string inside a `string[]` content value, matching the spec's
  unconstrained item type, instead of failing the whole retry with `-32602`.

## [v0.12.0](https://github.com/NexusPHP/mcp/compare/v0.11.0...v0.12.0) - 2026-08-12

Lifecycle correctness on both peers: close/drain ordering, in-flight caps that hold under pressure,
subscription accounting, and task expiry and stall guarantees. The schema side now honours the spec's
open capability set and the empty `{}` sub-schemas the decoder collapses to `[]`. Seven breaking
changes ride along (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)).

### Added

- `ClientBuilder::setMaxInFlightDispatches()` caps how many inbound messages a client processes at once.
- `RequestStateSigner::sign()` and `verify()` take an optional binding, so a state minted for one caller
  does not verify for another.

### Changed

- `Core\Transport\SubscriptionInterface` is renamed `ListenerHandleInterface`
  (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)).
- The in-flight dispatch cap is on by default on both peers, at `DEFAULT_MAX_IN_FLIGHT` (1024). See
  [BREAKING_CHANGES.md](BREAKING_CHANGES.md).
- `RequestStateSigner` signatures changed shape, so a state minted before this release no longer verifies.
- `VerifiedAccessToken::$subject` and `$clientId` are `non-empty-string`, and an empty or non-string identity
  claim reads as absent rather than reaching a handler or masking a later one.
  See [BREAKING_CHANGES.md](BREAKING_CHANGES.md).

### Fixed

- A `close()` racing an in-progress close on the stdio transports now blocks until that close settles,
  instead of returning early while `send()` was still accepted.
- An empty `{}` sub-schema, which `json_decode(..., true)` renders as PHP `[]`, no longer fails every
  `tools/call` with a bare internal error: the default validator restores it in every sub-schema position.
- `#[InputSchema(definition: [])]` is refused at construction instead of being read differently per level.
- A completion answering more than the spec's 100-value cap is truncated with `hasMore: true` and a
  `total`, instead of shipping an over-long list.
- `Icon` decodes any RFC 3986 URI `src` instead of aborting a whole listing on an unusual scheme. The
  http/data restriction moved to the authoring surfaces (stores and builders).
  See [BREAKING_CHANGES.md](BREAKING_CHANGES.md).
- `ServerCapabilities` now keeps unknown capabilities (top level in a new `extras` slot, nested keys in
  place) and models the deprecated `logging` member, honouring the spec's open-set rule.
  See [BREAKING_CHANGES.md](BREAKING_CHANGES.md).
- `awaitTask()`'s stall ceiling now counts polls that sent no answers, so a resolver answering outside
  the offered set can no longer defeat it, and a key re-issued after a `working` round is offered again.
- `InMemoryTaskStore` now fails a task that has not settled by `createdAt + ttlMs` and sweeps it,
  instead of retaining a parked task forever.
- `HeaderMismatchError` now carries `data` like its sibling errors, instead of silently dropping it on
  construction and decode.
- Building a `-32021` or `-32022` error without its `data` payload now fails with a message naming the
  code and the keys it requires, instead of a generic decode error.
- A prompt or template completion registered under an all-digit name no longer fails `build()`.
- A discovered tool's nullable parameter now accepts the `null` its advertised schema permits, at the top
  level and inside an expanded object, instead of answering `-32602`.
- A method-level `#[InputSchema(...)]` without `definition` now merges its constraints over the inferred
  schema instead of being silently discarded.
- `ResourceStore` refuses an entry whose key differs from its resource's URI at construction, the same
  check its three sibling stores already run.
- A `subscriptions/listen` acknowledgement no longer promises a `listChanged` type no registered store can
  produce, matching what `server/discover` advertises.
- A stdio transport closed from the main context before its read loop's first turn now drains that loop
  before `close()` returns, the same as every other close path.
- A resource reader's own `ResourceNotFoundException` ends the read instead of being answered by an
  overlapping resource template. A registry miss still falls through, signalled by the new
  `ResourceNotRegisteredException` (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)).
- An authorization caller that stops waiting for the coordinator's lock now returns the permit it is
  eventually handed, so later token operations no longer depend on collection timing to proceed.
- `SubscriptionStore` counts a stream whose acknowledgement is still in flight against `maxSubscriptions`,
  so the limit holds against a peer that stops reading.
- `subscriptions/listen` no longer occupies an in-flight dispatch slot on a server that serves it, and
  listens arriving faster than the loop starts them are shed with `-32000`.
- The first `notifications/cancelled` naming a request in flight is no longer shed by the in-flight
  dispatch cap, and is cancelled on admission when the cap is reached.
- `StreamableHttpServerTransport` no longer leaks a request's sink when an `onMessage` listener throws.
- `StreamableHttpServerTransport` no longer strands an in-flight request whose response or notification
  cannot be encoded or built, and settles those still in flight when `close()` runs.

## [v0.11.0](https://github.com/NexusPHP/mcp/compare/v0.10.0...v0.11.0) - 2026-08-10

Closes a family of security defects where peer-supplied bytes reached a renderer unbounded and
unescaped, in a JSON-RPC error, a log record, or an exception message. The rest is decode correctness
for names and keys the 2026-07-28 schema permits and this SDK refused. Three breaking changes ride
along (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)).

### Added

- `SafeDisplay` is now public API, for bounding and escaping a peer value your own handler quotes back.

### Changed

- `AuthorizedHttpClient` takes an `HttpClientBuilder` and runs credentialed traffic on a client that
  follows no redirect, so a hop off the MCP server's origin is refused before the credential travels
  (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)).
- `JwksAccessTokenValidator` takes the issuer it accepts and refuses a token whose `iss` is absent or
  different, or which carries no `exp` (see [BREAKING_CHANGES.md](BREAKING_CHANGES.md)).

### Fixed

- Every peer value this SDK quotes back in a JSON-RPC error, a log record, or a client-side exception is
  bounded and escaped. `error.data.uri` and `RemoteCallFailedException::$error` still carry it whole.
- A malformed envelope is no longer copied whole into the log record reporting it. The reason and the
  request id ride the logged exception as before.
- A parsed OAuth scope is held to the RFC 6749 `scope-token` grammar, and a segment that is not one is
  dropped.
- A resource-template variable that percent-decodes out of the segment it matched is refused rather than
  handed to the reader. `files://%2E%2E%2Fetc` resolved where `files://../etc` never matched.
- A client reconnected to a new transport is no longer driven by the old one. `disconnect()` left its five
  listeners attached, so a stale error, reconnect, or message could still reach the live connection.
- A header-mismatch retry no longer re-lists tools without bound. The walk stops on a repeated cursor or
  at 100 pages, and a tool it never reaches is retried unmirrored rather than with the rejected header.
- A JSON Schema property name made only of digits is decoded rather than refused, and re-encodes as an
  object. Covers a tool's `inputSchema` and `outputSchema`, an elicitation's `requestedSchema`, and an
  elicit result's `content`, whose keys are those same names. An empty `content` now emits `{}` too.
  A JSON array arriving in one of those object-typed slots is normalised to an object rather than
  refused, since `json_decode` cannot tell it from an object whose names run `0`…`n-1`.
- A tool or prompt argument name made only of digits is decoded rather than refused, and re-encodes as
  an object. Covers `tools/call` and `prompts/get` `arguments` plus `completion/complete`'s
  `context.arguments`, whose names are the schema property names widened above, so a tool declaring
  such a property can now be called as well as listed. `ParameterHeaderValidationMiddleware` no longer
  drops those arguments before checking them against the `Mcp-Param-*` headers.
- A server-assigned `inputRequests` / `inputResponses` key made only of digits is decoded rather than
  refused, and re-encodes as an object. The spec puts no format on those keys, so a server numbering
  them from a counter had its whole multi-round-trip exchange rejected. A JSON array in one of those
  slots is normalised to an object, for the same reason as above.
- A tool's `inputSchema` and `outputSchema` emit an empty sub-schema as `{}` rather than `[]`, at any
  nesting depth, so `{"type":"object","properties":{}}` survives a round trip as valid JSON Schema. An
  elicitation's `requestedSchema` does the same, including when it rides on a `tools/call` or
  `tasks/get` result, as do an elicit result's `content` and a `tasks/get` result's `result` and
  `error`. Those last two also take a key made only of digits, matching the rest of the class.
- A `_meta` name made only of digits is decoded rather than refused. `json_decode` turns such a key into
  a PHP int, which the guard read as a malformed object. A name set running `0`…`n-1` stays refused: it
  decodes identically to a JSON array, so the two cannot be told apart.
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
- `SecuredHttpEndpoint` applies `maxBodyBytes` before the parameter-header middleware rather than after,
  so an oversized body is refused without first being buffered and JSON-decoded.
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
  [docs/auth/extension-grants.md](docs/auth/extension-grants.md#writing-your-own-grant).
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
