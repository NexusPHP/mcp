# Roadmap

This document describes where the SDK is today, what is queued for the next few minor releases, and how
the package plans to track upcoming MCP spec revisions and PHP language versions. It is intentionally
forward-looking: see [docs/architecture.md](docs/architecture.md) for the canonical description of what
already ships.

## Current capabilities

The SDK targets MCP spec **2025-11-25** and is published on Packagist (latest **v0.5.0**, pre-stable). It
ships a symmetric server and client over stdio, sharing one protocol kernel under `Nexus\Mcp\Core`.

Protocol surface (both sides):

- [x] Tools (`tools/list`, `tools/call`, with streaming progress on the client).
- [x] Prompts (`prompts/list`, `prompts/get`).
- [x] Resources, static and RFC 6570 templated (`resources/list`, `resources/templates/list`,
  `resources/read`).
- [x] Completions (`completion/complete`).
- [x] Logging (`notifications/message` + `logging/setLevel`).
- [x] Ping.
- [x] The `initialize` / `notifications/initialized` handshake.

Composition and transport:

- [x] Server composition (`ServerBuilder`) and client composition (`ClientBuilder`) over a shared
  dispatch kernel (`MessageDispatcherInterface`), handler-registry shape, and transport contract.
  `PendingOutboundRequests` correlates inbound responses to awaiting senders by `RequestId`.
- [x] Stdio transport on both sides (`StdioServerTransport`, `StdioClientTransport`) plus an in-memory
  transport for tests, with an end-to-end stdio client / server example.
- [x] Attribute-based registration: `#[AsTool]` / `#[AsResource]` / `#[AsPrompt]` /
  `#[AsResourceTemplate]` on methods plus class-level `#[AsServer]`, registered via
  `ServerBuilder::register()`. Tool input schemas (JSON Schema 2020-12) are generated from PHP
  signatures and docblocks.
- [x] Tool-call argument and result validation against their JSON Schemas (opis/json-schema by default,
  pluggable via `ServerBuilder::setSchemaValidator()`), and structured tool results mirrored into a text
  content block.

Quality and project gates:

- [x] PHPStan level 10 + strict rules. Infection at 100% MSI, 100% MCC, 100% covered-code MSI.
- [x] Architecture-boundary enforcement (StructArmed) and dependency-declaration checks
  (composer-dependency-analyser) guarding the eventual component split.
- [x] Documentation (getting-started, server, client, transports, architecture, error-handling,
  best-practices, design-rationale, attribute-discovery) plus README and community-health files
  (CONTRIBUTING, CODE_OF_CONDUCT, SECURITY, CHANGELOG, VERSIONING, DEPENDENCY_POLICY).

Server-initiated requests and the HTTP transport are not implemented yet. They
land with the 2026-07-28 migration below, where `RequestBoundSender::sendRequest()` (a stub today) is
implemented.

## MCP 2026-07-28 migration

The next MCP spec revision, dated **2026-07-28**, reshapes the protocol significantly. Its release
candidate is published (the `2026-07-28-RC` tag, 2026-05-29, content frozen 2026-05-21) and the final
spec is due 2026-07-28. The SDK builds this migration against the frozen release candidate
(`schema/draft/`), re-syncing field-level fix-ups as they land. The work is staged foundation-first: the
schema and protocol layer, then the Streamable HTTP transport that carries it, then the extension
framework. The **v1.0.0 release is reserved for the final dated `2026-07-28` spec**, so the SDK does not
ship a stable major against a draft that can still shift.

**No backward-compatibility layer with 2025-11-25.** The migration ships as the v1.0.0 major version
bump, and major versions are the SDK's contract for breaking changes. v0.x consumers have already
accepted that minor versions may break BC, so anyone pinning a 2025-11-25-shaped v0.x release
understands the migration path is "bump major, port code." Compatibility shims that let a single
release speak both spec revisions would carry permanent maintenance overhead for a one-time porting
exercise consumers have already opted into.

The bundle is large because the spec batches a lot of interlocking changes. Subsections below group
them by what they affect.

### Sessionless and stateless protocol

The `initialize` / `notifications/initialized` handshake and the per-session HTTP `Mcp-Session-Id`
header both disappear. Each request becomes self-describing via required per-request `_meta` fields
(`protocolVersion`, `clientInfo`, `clientCapabilities`). New methods replace the removed handshake:
`server/discover` for capability discovery and `subscriptions/listen` for the new mailbox-style
subscription primitive that replaces today's `resources/subscribe` / `unsubscribe`. The `ping` keepalive
utility (SEP-2575, changelog item 5) is removed from the protocol entirely.

- [x] Delete `initialize` request + `notifications/initialized`, reshape lifecycle around per-request
  `_meta`.
- [x] Delete `ping` (`PingRequest`, `PingRequestHandler`, the method-registry entry, and the
  initialization gate's `ping` allowance). The RC removes the method along with the session it kept alive.
- [x] Implement `server/discover` request method.
- [x] Add the `subscriptions/listen` schema classes: the request and its params, the `SubscriptionFilter`
  opt-in object, and `SubscriptionsAcknowledgedNotification` with its params, registered in the method registry.
- [ ] Serve `subscriptions/listen` server-side: a handler that returns the empty result response (leaving
  the dispatcher's result-send path unchanged) and emits `SubscriptionsAcknowledgedNotification` as the
  first stream message, tagged with the server-minted `io.modelcontextprotocol/subscriptionId` `_meta` key.
  Requires a subscription store and a list-changed / resource-updated fanout source to feed the stream.
- [x] Delete `resources/subscribe` / `resources/unsubscribe` (none of these are implemented today, so
  this is a non-action verified by the migration).
- [x] Represent the lifecycle/capability and header-mismatch error responses: `ProtocolErrorCode` carries
  `HeaderMismatch` (`-32020`), `MissingRequiredClientCapability` (`-32021`), and `UnsupportedProtocolVersion`
  (`-32022`), each with a matching `Error` subclass (typed `data` where the spec defines it: `supported` +
  `requested`, `requiredCapabilities`, none) routed through `ErrorFactory` and the generic
  `JsonRpcErrorResponse` envelope.
- [x] Emit `UnsupportedProtocolVersionError` from the request-validation path: the dispatcher gates every
  inbound `ClientRequest` (including `server/discover`) on `_meta.protocolVersion`, answering `-32022` with
  `data.supported` / `data.requested` when the version is not in `ProtocolVersion::SUPPORTED_VERSIONS` (the
  single set `server/discover` also advertises). A missing or malformed required `_meta` field is already
  rejected as `-32602` at the parser.
- [ ] Emit `MissingRequiredClientCapabilityError` (`-32021`) when processing a request requires a client
  capability absent from `_meta.clientCapabilities`. Held with elicitation serving: the only capability the
  server can require is `elicitation`, needed only when it issues an `input_required` (`InputRequiredResult`),
  and that emission path is not built yet. `HeaderMismatchError` is emitted by the Streamable HTTP header
  layer (below), not this path.

### Stdio client restart on unexpected server exit

The lifecycle "Unexpected Termination" rule: when the stdio server process exits unexpectedly, the client
SHOULD restart it, retry the now-lost in-flight requests against the fresh process, and re-establish any
active `subscriptions/listen` streams. The client already surfaces the loss (a subprocess exit closes the
transport and `PendingOutboundRequests::cancelAll` rejects every in-flight request) and already supports
manual reconnect, but it does not restart the process or replay subscriptions. Supervision stays out of the
dumb-pipe transport and lands as a `SupervisedTransport` decorator that owns the subprocess command, tells
an unexpected crash from an intentional close via the captured exit code, and respawns. The
subscription-replay step depends on `subscriptions/listen` above, so this builds after it.

- [ ] Capture the subprocess exit code (`Process::join()`) so an unexpected exit is distinguishable from an
  intentional close.
- [ ] `SupervisedTransport` decorator that respawns the subprocess on unexpected exit and re-emits the
  listener chain to an unchanged `Client`.
- [ ] Re-establish active `subscriptions/listen` streams after restart.
- [ ] Optional opt-in retry of the lost in-flight requests against the fresh process.

### Multi round-trip requests (MRTR)

Every result envelope gains a `resultType` discriminator (`"complete"` vs `"input_required"`). Servers
that need to ask the client for input mid-request (elicitation) return an `InputRequiredResult`.
`JsonRpcResultResponse` is an abstract envelope base with one concrete, self-decoding `*ResultResponse`
per spec method (nine in all). Only `tools/call`, `prompts/get`, and `resources/read` accept
`InputRequiredResult`.

- [x] Add `InputRequiredResult` (an `inputRequests` map plus an opaque `requestState`).
- [x] Add `InputResponseRequestParams` (an abstract base over `RequestParams` carrying an
  `inputResponses` map + an opaque `requestState`). The `tools/call`, `prompts/get`, and `resources/read` params
  carry both fields. The client retries by re-issuing the original request, so there is no
  `tasks/input_response` method.
- [x] Model the nine per-method `*ResultResponse` envelopes: an abstract `JsonRpcResultResponse` base
  plus `Arrayable` leaves that decode themselves through `fromArray` (the parser delegates with the
  awaited response class). The base's `resultType` discriminator gates `input_required`: only the three
  eligible envelopes accept it. `GenericResultResponse` carries results with no dedicated envelope
  (`EmptyResult`), and `ResultResponseFactory` picks the typed envelope on the send path.
- [ ] Model `SubscriptionsListenResult` (its empty-ack result landed in the schema) and carry it through
  `GenericResultResponse` (today `EmptyResult`-only). No dedicated `*ResultResponse` is planned: whether the
  spec wants one for the lone request method that lacks one is the open question in upstream issue #2989, so
  this is held until that resolves.
- [x] Delete `UrlElicitationRequiredError` (-32042) entirely.

### Tool schema relaxation (SEP-2106)

`Tool.inputSchema` and `outputSchema` accept full JSON Schema 2020-12 through an open
`[key: string]: unknown` slot. Every top-level keyword (`additionalProperties`, `$defs`, `allOf`,
conditionals) is preserved verbatim. `inputSchema` requires a `type: "object"` root (tool arguments are
always JSON objects). `outputSchema` carries no such requirement.

This step also resolved two attribute-discovery findings blocked on it:

- `#[InputSchema(definition: ...)]` advertises its schema verbatim, and the attribute's PHPDoc no longer
  claims a narrower override.
- A `definition` can describe a nested-DTO constructor, so `ArgumentBinder::construct` raises a clean
  `UnsupportedNestedParameterException` instead of leaking a raw `\TypeError` when a constructor
  parameter is itself an unexpandable class.

- [x] Relax tool-schema validation to preserve all top-level JSON Schema 2020-12 keywords (`inputSchema`
  requires a `type: "object"` root, `outputSchema` does not).
- [x] Preserve `#[InputSchema(definition: ...)]` verbatim once the schema root accepts arbitrary keywords.
- [x] Raise a clean SDK exception (not `\TypeError`) for an unconstructable nested-DTO constructor
  parameter reached via the `definition` bypass.

### Deterministic tool ordering

The revision adds a SHOULD that servers return `tools/list` in a deterministic order (the same ordering
across requests when the tool set is unchanged) so clients can cache the list and improve LLM
prompt-cache hit rates. The SDK already satisfies this by construction: the tool store preserves
registration order. The migration verifies the property rather than adding new work.

- [x] Verify deterministic `tools/list` ordering holds after the migration.

### Optional-string field validation consistency

Optional `?: string` spec fields decode uniformly: each narrows to `non-empty-string` and rejects `""`
at the constructor, so `Implementation.description` / `title` / `websiteUrl` and
`DiscoverResult.instructions` all treat an empty string as absent. The spec types each as `?: string`, but
the decode boundary collapses `""` to null rather than carrying two encodings of "no value".

- [x] Choose a single optional-string validation rule and align the affected schema classes
  (`Implementation`, `DiscoverResult`, and any peers) on it.

### Diagnostic message audit

Exception and `Assert::that(...)` messages follow the convention documented in the "Diagnostic message
conventions" section of `docs/architecture.md`. The parser owns one wrapper prefix per envelope kind
(`Invalid success response:`, `Invalid error response:`, `Invalid "{method}" request:`,
`Invalid "{method}" notification:`), and the inner detail it wraps omits the envelope kind: it is
field-named and scoped to mirror the matching type-mismatch path (`"error.code" must be an integer,
{type} given.`, `"params" is missing the required "name" key.`), with envelope-root fields left bare
(`missing the required "id" key.`). The envelope decode paths (both response sides, request and
notification parsing) and the schema `fromArray` messages are aligned, and the convention docs cover the
parser-wrapper layer.

- [x] Align the `Core/Validation/` validators (`Rfc3986UriValidator`, `Rfc6570UriTemplateValidator`,
  `IdentifierNameValidator`) on the convention's `{value} given.` value-mismatch idiom.

### Deprecation cleanup

The 2026-07-28 spec deprecates Roots, Sampling, and Logging (SEP-2577), and SEP-2575 additionally removes
`logging/setLevel` outright (log level moves to the per-request `_meta` `io.modelcontextprotocol/logLevel`
field). The SDK implements no compatibility window and deletes all three from core at the migration cut.

**Deleting these features is explicitly allowed, including the parts still present in the schema.** The
`notifications/message` notification and the `sampling/*` and `roots/*` defs
remain in `schema.json` through their deprecation window, but a def staying in the schema does not oblige
an SDK to implement it. The RC changelog's Deprecated section states these features "remain part of the
specification but ... new implementations should not adopt them," and SEP-2577 repeats "new
implementations should not add support for them" (suggesting `stderr` or OpenTelemetry over Logging,
provider APIs over Sampling, and tool parameters or resource URIs over Roots). The SEP-2596 minimum
12-month grace window protects existing implementations that already shipped these features, not a
greenfield SDK with zero published tags. Omitting them is conformant. This is settled, not an open
spec-divergence question.

- [x] Delete Roots (`roots/list`, `notifications/roots/list_changed`, `Root`, `ListRootsResult`, the capability slot).
- [x] Delete the residual server-side Logging emission path: `notifications/message`
  (`LoggingMessageNotification`), the `logging` capability slot, `LoggingLevelGate`, and the `log()`
  helper on `ServerContext`. Retain the `LoggingLevel` enum and `RequestMetaObject.logLevel` as a
  round-trip-only mirror of the still-present (deprecated) `_meta.io.modelcontextprotocol/logLevel`
  field. The client intentionally never populates it: `stampMeta` leaves `logLevel` null, since adopting
  the deprecated client opt-in is out of scope.
- [x] Delete Sampling from core (`sampling/createMessage`, `CreateMessageRequest`,
  `CreateMessageResult`, `SamplingMessage`, the capability slot). Sampling is not shipped, not even as an
  optional extension.
- [x] Rename the `ServerRequest` marker interface to `InputRequest`, matching the union the 2026-07-28
  spec renamed. With Roots and Sampling gone, only `ElicitRequest` implements it. Touches the marker, its
  implementers, the conformance `@see` map, and tests. `ClientRequest` is unchanged.
- [x] Re-model `ElicitRequest` off `JsonRpcRequest`. It is now a bare `Request` (`method` + `params`, no
  `jsonrpc`/`id` envelope) in `Schema\Elicitation`, surfaced through the MRTR `InputRequiredResult` flow
  rather than as a standalone JSON-RPC request, and `InputRequiredResult.inputRequests` decodes off the
  `InputRequest` marker. The `ENVELOPE_SPEC_DRIFT` conformance guard (migration scaffolding) is removed.
- [x] Re-model `ElicitResult` off `Result`. It is now a standalone `Arrayable` (`action` plus optional
  `content`) in `Schema\Elicitation` implementing the new `InputResponse` marker, carrying neither the
  `resultType` discriminator nor `_meta`, and `InputResponseRequestParams.inputResponses` decodes off the
  `InputResponse` marker. The `RESULT_SPEC_DRIFT` conformance guard (migration scaffolding) is removed.

### TTL on list results

A new `CacheableResult` base class lets servers tell clients how long a list result stays fresh
(`ttlMs` + `cacheScope`). Replaces the `*ListChanged` notification pattern as the primary cache-busting
mechanism. Today's stores need a way to surface TTL on their list outputs.

- [x] Add `CacheableResult` abstract base class under `Core/Schema/`.
- [x] Extend it on `ListToolsResult`, `ListPromptsResult`, `ListResourcesResult`,
  `ListResourceTemplatesResult`, `ReadResourceResult`, and `DiscoverResult`.
- [x] Plumb `?int $ttlMs` + `?string $cacheScope` through the per-feature stores and the
  `server/discover` handler.

### Streamable HTTP transport

The HTTP transport is built against the 2026-07-28 stateless shape, after the schema and protocol layer
it carries. The revision deletes every legacy complication: protocol-level sessions (`Mcp-Session-Id`),
the standalone GET SSE stream, resumable streams (`Last-Event-ID`), server-initiated JSON-RPC requests,
and batching. What remains is a POST-only MCP endpoint that answers each request with a single JSON
object or a request-scoped SSE stream carrying progress notifications followed by the final response.
Server-to-client interactions (sampling, elicitation, roots) are not transport traffic: they ride the
MRTR `InputRequiredResult` payload and the client retry. This lands on infrastructure already present:
`SendContext.relatedRequestId` is the routing key, and `RequestBoundSender::sendRequest()` already
rejects outbound requests, which the revision makes correct rather than a stub.

The work splits into three layers matching the package boundaries, sequenced so the pure logic lands
first and unblocks both transports.

Shared header core (`Nexus\Mcp\Core\Http`, no PSR dependencies). Pure string and array logic,
corpus-tested to the spec fixtures, consumed by both transports.

- [x] `Mcp-Param` / `Mcp-Name` value codec: the `=?base64?…?=` sentinel encode and decode (empty, leading
  or trailing whitespace, non-ASCII, control characters, and self-encoding of a value that already matches
  the sentinel), integer-to-decimal and boolean-to-lowercase conversion, and canonical-padded decode.
- [x] `x-mcp-header` schema scanner: validate declarations against the field-name token syntax, non-empty,
  control-character, case-insensitive uniqueness, primitive-type (integer, string, boolean, with `number`
  not permitted per spec), safe-integer-range, and static-reachability (a `properties`-only chain, never
  through `items`, composition, conditional, or `$ref`) constraints. Verify the `number` ban against the
  conformance suite before pinning it, since the TS SDK admits `number` to pass its conformance referee.
- [x] Standard-header validation: `MCP-Protocol-Version` cross-checked against the body `_meta` version,
  `Mcp-Method` required on every request, and `Mcp-Name` cross-checked against `params.name` (`tools/call`,
  `prompts/get`) or `params.uri` (`resources/read`). Header names compare case-insensitively, values
  case-sensitively, and integer parameters compare numerically.
- [x] Error-to-HTTP-status mapping keyed on origin: transport and protocol errors map to real statuses
  (`-32700`, `-32600`, envelope-level `-32602`, `-32020`, `-32021`, and `-32022` to 400, and `-32601` to
  404), while handler-produced errors, including `-32603` and tool errors, ride HTTP 200 with the JSON-RPC
  error in the body.

Server transport (`Nexus\Mcp\Server`, PSR-15). Adds `psr/http-message`, `psr/http-factory`, and
`psr/http-server-handler`. Runs on the amphp event loop with amphp/http-server as the reference host.
PSR-17 factories are constructor-injected, not discovered.

- [ ] `StreamableHttpServerTransport implements TransportInterface`, adding
  `handleRequest(ServerRequestInterface): ResponseInterface`. Per POST it registers a per-request response
  sink keyed by the JSON-RPC request id, emits the envelope to the dispatcher, routes outbound messages
  back by `relatedRequestId` (progress) or response id, and returns a buffered JSON response or a streaming
  SSE body. Response mode is `auto` (JSON unless a related message arrives mid-call, then a lazy SSE
  upgrade), `sse`, or `json`.
- [ ] SSE writer: frames `event: message\ndata: <json>\n\n`, response headers `text/event-stream`,
  `Cache-Control: no-cache`, `Connection: keep-alive`, and `X-Accel-Buffering: no`, plus periodic
  keep-alive comment frames on open streams. The body is a streaming `StreamInterface` backed by an amphp
  queue, and a client disconnect cancels the request.
- [ ] HTTP conformance edges: `202 Accepted` with no body when a POST produces no outbound message,
  `405 Method Not Allowed` with an `Allow: POST` header for other methods, an empty or undecodable body
  answered with `-32700 ParseError` (id key omitted), a defensive `406` when the client accepts neither
  content type, and a configurable body-size cap answered with `413`.
- [ ] `Origin` validation as separate middleware returning `403` with an id-less JSON-RPC error, and an
  optional CORS helper (preflight `OPTIONS` to `204`) for browser clients.
- [x] A non-blocking `Server::listen(TransportInterface)` seam that attaches the dispatcher listeners and
  starts the transport without the close-await that `run()` uses for stdio, so the endpoint can be mounted
  per request in a PSR-15 stack.
- [ ] Re-introduce the `ReceiveContext` listener argument across the chain, carrying the originating
  `ServerRequestInterface` and a later auth-subsystem slot.

Client transport (`Nexus\Mcp\Client`, amphp/http-client). Adds `amphp/http-client`.

- [ ] `StreamableHttpClientTransport implements TransportInterface`: each `send()` is a discrete POST with
  `Content-Type: application/json`, `Accept: application/json, text/event-stream`, and the
  `MCP-Protocol-Version`, `Mcp-Method`, and `Mcp-Name` headers computed from the message body. The response
  content-type selects single-JSON decode or SSE parse, completion is the terminal result or error message
  plus a read timeout rather than end-of-file alone, and cancelling one request aborts that POST.
- [ ] `x-mcp-header` mirroring (client mandatory): cache tool input schemas from `tools/list`, exclude any
  tool whose `x-mcp-header` declarations violate the scanner constraints (logging a warning), and on
  `tools/call` build the `Mcp-Param-{Name}` headers from the arguments, carried through `SendContext`.
  Gated on an HTTP-transport marker so stdio is unaffected.

Follow-on milestones.

- [ ] DoS hardening whose natural home is the per-request HTTP model: an in-flight dispatch cap and
  orphan-response log throttling, alongside the body-size cap above.
- [ ] `subscriptions/listen` serving over a long-lived SSE stream. The transport already supports
  long-lived streams structurally. The handler lands when the subscriptions result leg unblocks.
- [ ] Docs and examples: an amphp/http-server server example, an HTTP client example, and the
  `docs/transports.md` Streamable HTTP section.

### Authorization (OAuth 2.1)

Authorization is a committed part of the v1.0.0 surface. The MCP client must perform the OAuth 2.1 flow
to obtain tokens for protected MCP servers, and the conformance suite scores client-mode heavily on it
(the majority of scored client scenarios are OAuth, so client-mode conformance is not reachable without
it). It depends on the HTTP client transport above and is built after it.

Client (required for client-mode conformance):

- [ ] Discovery: Protected Resource Metadata via the `WWW-Authenticate` `resource_metadata` URL, then
  Authorization Server metadata (RFC 8414 and OpenID `.well-known`), including the RFC 8414 path suffix.
- [ ] Registration: Dynamic Client Registration with `application_type`, Client ID Metadata Document
  (CIMD), and a pre-registered-credentials fallback when there is no registration endpoint.
- [ ] PKCE (S256) on the authorization request.
- [ ] Token-endpoint auth: `client_secret_basic`, `client_secret_post`, and `none` (public client).
- [ ] Scope handling: select from `WWW-Authenticate` / `scopes_supported` / omit, step-up on a 403
  insufficient_scope with scope accumulation, and a retry cap.
- [ ] Resource Indicators (RFC 8707): send and validate the `resource` parameter.
- [ ] Issuer validation (RFC 9207): validate the authorization-response `iss` and the AS-metadata issuer.
- [ ] Refresh: request `offline_access` and use the refresh-token grant when supported.
- [ ] Authorization-server migration: re-register on AS change without credential reuse.

Server (resource server, not conformance-scored but part of the auth spec):

- [ ] 401 challenge with `WWW-Authenticate` carrying the `resource_metadata` URL.
- [ ] Serve a Protected Resource Metadata document.
- [ ] Bearer-token validation bound to this resource.

The OAuth-related official extensions (client-credentials and enterprise-managed authorization) are
covered in the "Official extensions" block below, built on this subsystem.

### Official extensions

The SDK fully supports the official MCP extensions. They are built as the final block of the migration,
after the transport and authorization above, and each ships disabled by default with explicit opt-in per
the extensions framework (SEP-2133).

- [ ] Extensions framework primitive: `ServerBuilder::enableExtension(...)` (or similar) plus the
  `extensions` capability slot (the slot itself lands with the schema layer).
- [ ] Tasks (`io.modelcontextprotocol/tasks`): relocate the task layer to `Nexus\Mcp\Extension\Tasks\*`,
  remove `tasks/list`, replace the blocking `tasks/result` with polling via `tasks/get`, add `tasks/update`
  for client-to-server input, drop per-request opt-in (servers may return task handles unsolicited), and
  route the `resultType: "task"` variant through the result discriminator.
- [ ] MCP Apps (SEP-1865): the `ui://` URI scheme, `text/html;profile=mcp-app`, and the sandboxed
  iframe interaction model.
- [ ] OAuth client-credentials (`io.modelcontextprotocol/oauth-client-credentials`) and
  enterprise-managed authorization (SEP-990), built on the authorization subsystem above.

### OpenTelemetry trace context

W3C Trace Context keys (`traceparent`, `tracestate`, `baggage`) are carried as ordinary `_meta` entries.
They are unprefixed, but the spec's `_meta` key rules make the prefix optional and the names are
alphanumeric-bounded, so they are valid keys that need no special casing.

- [x] Permit the W3C trace keys in `_meta`. Satisfied by design: the SDK does not enforce `_meta`
  key-name validation, so any conformant (or unprefixed-but-valid) key already passes through
  `RequestMetaObject` into `extras`. This matches every official SDK and the conformance suite, none of
  which validate `_meta` key names on the receive side. If defensive validation is ever wanted, the only
  form consistent with the robustness principle (conservative in what you send, liberal in what you
  accept) is producer-side, validating keys the SDK itself emits, never consumer-side rejection. Left out
  for now.

### Spec-reference retargeting

The schema generator tracks the release candidate: `McpSchemaProcessor` fetches the RC draft schema
(`schema/draft/` at the `2026-07-28-RC` tag) into the local `latest-schema.json` / `latest-schema.ts`
references. The `@see` tags across `src/` and the `SchemaConformanceTest` anchor constants point at the
`specification/draft/` docs pages, because the dated `2026-07-28` spec pages are not published yet. They
retarget to the dated pages once the final spec ships.

- [ ] Repoint the `@see` URLs in `src/` from `specification/draft/...` (and the `schema/draft/schema.ts`
  blob link) to the dated `2026-07-28` pages once published.
- [ ] Update `SchemaConformanceTest`'s `SCHEMA_ANCHOR_BASE_URL` and `TS_SCHEMA_FILE_URL`, plus
  `McpAnchorSnapshot::SPEC_BASE_URL`, to the dated spec, then re-run `composer spec:snapshot-anchors` to
  refresh the anchor snapshot.

## Server ergonomics

Composition-surface quality-of-life items, independent of any spec revision.

- [ ] Make the pagination page size configurable from the fluent `ServerBuilder` path.
  `AbstractPaginatedStore` fixes `DEFAULT_PAGE_SIZE` at 50. The size is a store constructor parameter, but
  the `addTool()` / `addPrompt()` / `addResource()` methods build the stores internally without threading a
  custom value, so today it can only be changed by hand-constructing a store and injecting it through
  `setToolStore()` and its siblings. Surface it as a builder-level knob (a global default, optionally
  per-feature).

## Transports

The `TransportInterface` is already shaped to accommodate streamable HTTP without a breaking change:
`SendContext` is a value-object slot for transport-specific routing fields (`relatedRequestId`), and
`onDrain` is symmetric with `onClose` so streaming responses can flush before the connection closes.

Streamable HTTP lands with the 2026-07-28 migration since the spec's session-management semantics also
move on that revision.

- [x] `Nexus\Mcp\Server\Transport\StdioServerTransport`.
- [x] `Nexus\Mcp\Core\Transport\InMemoryTransport` (test-only paired transports).
- [x] `Nexus\Mcp\Client\Transport\StdioClientTransport` (subprocess launcher).
- [ ] Streamable HTTP server transport (lands with the 2026-07-28 migration).
- [ ] Streamable HTTP client transport.

## Language compatibility

The SDK currently targets **PHP 8.4** minimum and uses the language features that became available in
that version (typed class constants, readonly classes, constructor property promotion, asymmetric
visibility, property hooks, `#[\Override]`).

PHP 8.5 brings covariant `static` return types for factory methods. Once the minimum PHP version is raised
to 8.5, the `Arrayable::fromArray()` contract will relax from `: static` to `: self` so final
implementations may narrow their return types. Until then, PHP 8.4 strictly enforces `: static`
invariance and the contract stays as it is.

The PHP-version floor will track the
[supported-versions calendar](https://www.php.net/supported-versions.php) at the SDK's own discretion.
Expect at least one major release per PHP minor that drops EOL versions.

- [x] PHP 8.4 floor.
- [ ] Raise floor to PHP 8.5.
- [ ] Relax `Arrayable::fromArray(): static` to `: self` after the 8.5 bump.

## See also

- **[Getting started](docs/getting-started.md)**: install + minimal server.
- **[Server API](docs/server.md)**: builder reference.
- **[Client API](docs/client.md)**: client builder + typed request reference.
- **[Transports](docs/transports.md)**: stdio contract + HTTP planning.
- **[Architecture](docs/architecture.md)**: dispatch kernel, layering, spec compliance.
