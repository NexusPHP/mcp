# Roadmap

This document describes where the SDK is today, what is queued for the next few minor releases, and how
the package plans to track upcoming MCP spec revisions and PHP language versions. It is intentionally
forward-looking: see [docs/architecture.md](docs/architecture.md) for the canonical description of what
already ships.

## Current capabilities

The SDK targets MCP spec **2026-07-28** and is published on Packagist as a pre-stable `0.x` line. It
ships a symmetric server and client over stdio and Streamable HTTP, sharing one protocol kernel under
`Nexus\Mcp\Core`.

Protocol surface (both sides):

- [x] Tools (`tools/list`, `tools/call`, with streaming progress on the client).
- [x] Prompts (`prompts/list`, `prompts/get`).
- [x] Resources, static and RFC 6570 templated (`resources/list`, `resources/templates/list`,
  `resources/read`).
- [x] Completions (`completion/complete`).
- [x] Capability discovery (`server/discover`), with per-request `_meta` lifecycle fields in place of a
  handshake.
- [x] Subscriptions (`subscriptions/listen`): filtered listen streams fed `list_changed` events by the
  runtime-mutable stores.
- [x] The input-required flow: `tools/call`, `resources/read`, and `prompts/get` may answer with
  `InputRequiredResult`, and the client resumes the call with the collected responses.

Composition and transport:

- [x] Server composition (`ServerBuilder`) and client composition (`ClientBuilder`) over a shared
  dispatch kernel (`MessageDispatcherInterface`), handler-registry shape, and transport contract.
  `PendingOutboundRequests` correlates inbound responses to awaiting senders by `RequestId`.
- [x] Stdio transport on both sides (`StdioServerTransport`, `StdioClientTransport`) plus an in-memory
  transport for tests, with an end-to-end stdio client / server example.
- [x] Streamable HTTP transport on both sides: `StreamableHttpServerTransport` is a PSR-15 handler
  (wrapped by `SecuredHttpEndpoint` with the recommended middleware), and the client speaks SSE streams
  and carries the required per-request headers.
- [x] Authorization: an OAuth 2.1 client (discovery, dynamic registration, PKCE, scope step-up) and the
  resource-server middleware with pluggable token validation.
- [x] Attribute-based registration: `#[AsTool]` / `#[AsResource]` / `#[AsPrompt]` /
  `#[AsResourceTemplate]` / `#[AsCompletion]` on methods plus class-level `#[AsServer]`, registered via
  `ServerBuilder::register()`. Tool input schemas (JSON Schema 2020-12) are generated from PHP
  signatures and docblocks.
- [x] Tool-call argument and result validation against their JSON Schemas (opis/json-schema by default,
  pluggable via `ServerBuilder::setSchemaValidator()`), and structured tool results mirrored into a text
  content block.

Quality and project gates:

- [x] PHPStan level 10 + strict rules. Infection at 100% MSI, 100% MCC, 100% covered-code MSI.
- [x] Architecture-boundary enforcement (StructArmed) and dependency-declaration checks
  (composer-dependency-analyser) guarding the eventual component split.
- [x] Documentation (getting-started, the server and client guides with per-feature pages, transports,
  authorization, architecture, error-handling, best-practices, design-rationale, attribute-discovery)
  plus README and community-health files (CONTRIBUTING, CODE_OF_CONDUCT, SECURITY, CHANGELOG,
  BREAKING_CHANGES, VERSIONING, DEPENDENCY_POLICY).

Server-initiated requests are absent by design: the 2026-07-28 revision removes them, replacing the
`ServerRequest` union with `InputRequest`, whose members ride an `InputRequiredResult` payload rather
than travelling as dispatchable JSON-RPC requests. So `RequestBoundSender::sendRequest()` rejecting
outbound requests is the finished behaviour, not a stub.

## MCP 2026-07-28 migration

The MCP spec revision dated **2026-07-28** reshapes the protocol significantly. The migration is built
against the published dated tag and shipped in **v0.6.0**, staged foundation-first: the schema and
protocol layer, then the Streamable HTTP transport that carries it. The extension framework
([Official extensions](#official-extensions) below) is the remaining piece, and **v1.0.0 is reserved
for the stable major on this revision**.

**No backward-compatibility layer with 2025-11-25.** v0.x consumers have already accepted that minor
versions may break BC, so anyone pinning a 2025-11-25-shaped v0.x release understands the migration
path is "port code to the new revision" ([BREAKING_CHANGES.md](BREAKING_CHANGES.md) is the guide).
Compatibility shims that let a single release speak both spec revisions would carry permanent
maintenance overhead for a one-time porting exercise consumers have already opted into.

The bundle is large because the spec batches a lot of interlocking changes. Subsections below group
them by what they affect.

### Sessionless and stateless protocol

The `initialize` / `notifications/initialized` handshake and the per-session HTTP `Mcp-Session-Id`
header both disappear. Each request becomes self-describing via per-request `_meta` fields:
`protocolVersion` and `clientCapabilities` are required, `clientInfo` is optional. The peer identities
the handshake used to exchange now ride `_meta` on both legs, self-reported and unverified, for display
and logging rather than for behaviour or security decisions. New methods replace the removed handshake:
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
- [x] Serve `subscriptions/listen` server-side: `SubscriptionStore` holds the open streams and fans the four
  event types out to the ones that asked, acknowledging each stream before it becomes visible to any emit and
  tagging every message with `io.modelcontextprotocol/subscriptionId`. The handler holds the request open and
  answers the empty result on graceful teardown. Announcements coalesce per event-loop tick. The four
  in-memory stores gained runtime mutation (`Mutable*StoreInterface`) plus an `onListChanged()` seam
  (`ListChangeSourceInterface`) the builder routes to the matching emit, and `listChanged` / `subscribe` are
  advertised only when both a subscription store and a change-reporting feature store are present.
- [x] Call `subscriptions/listen` client-side: `Client::listen()` opens a stream and returns a
  `SubscriptionStream` handle, and the dispatcher routes each subscription-tagged notification to the stream
  that asked for it ahead of the build-time handler for its method.
- [x] Delete `resources/subscribe` / `resources/unsubscribe` (none of these are implemented today, so
  this is a non-action verified by the migration).
- [x] Complete the `_meta` family: `MetaObject` is the abstract base under `Core/Schema/`, with its
  concrete subclasses in `Core/Schema/MetaObject/`. `PayloadMetaObject` carries the `_meta` of a payload
  nested inside an envelope, `RequestMetaObject` and `NotificationMetaObject` the request and notification
  slots (the latter holding the optional `io.modelcontextprotocol/subscriptionId`), and `ResultMetaObject`
  is itself an abstract base over `GenericResultMetaObject` and `SubscriptionsListenResultMetaObject`. Each
  slot types its `_meta` to the matching class, and every concrete one is `final`.
- [x] Carry the server identity on result `_meta`: `ResultMetaObject` (the result-side sibling of
  `RequestMetaObject`) holds the optional `io.modelcontextprotocol/serverInfo` key, `Result` and every
  subclass type their `_meta` slot to it, `DiscoverResult` drops its body-level `serverInfo` field, and
  `Client::discover()` reads the identity back off the result `_meta`.
- [x] Stamp `io.modelcontextprotocol/serverInfo` onto every outgoing result, not only `server/discover`,
  which is what the spec's SHOULD asks for. `ServerMessageDispatcher` owns the policy: it rebuilds the
  `_meta` of every handler result before wrapping, skipping any result that already declares an identity of
  its own (a proxy forwarding an upstream server's, in the typed slot or among the `_meta` extras, which
  `ResultMetaObject::declaresServerInfo()` answers). The schema layer contributes only the rebuild itself:
  `Result::rebuildWithMeta()`, implemented field-by-field by each concrete result.
  `ServerBuilder::setServerInfoDisclosure()` takes a `ServerInfoDisclosure` (`Full`, `NameAndVersion`,
  `None`), where `None` is the spec's "unless specifically configured not to do so" opt-out and
  `NameAndVersion` keeps icons and descriptions off every response but the `server/discover` one. Discovery
  holds the full block because `DiscoverRequestHandler` declares it on its own result, which the
  already-declared rule then leaves alone.
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
- [x] Emit `MissingRequiredClientCapabilityError` (`-32021`) when processing a request requires a client
  capability absent from `_meta.clientCapabilities`. A handler raises
  `MissingRequiredClientCapabilityException` with the `ClientCapabilities` it needed, since only the handler
  knows what serving its request takes. `HttpStatusResolver` pins the code to `400` even though a
  handler-raised error otherwise rides `200`. `HeaderMismatchError` is emitted by the Streamable HTTP header
  layer (below), not this path.

### Stdio client restart on unexpected server exit

The lifecycle "Unexpected Termination" rule: when the stdio server process exits unexpectedly, the client
SHOULD restart it, retry the now-lost in-flight requests against the fresh process, and re-establish any
active `subscriptions/listen` streams. Supervision stays out of the dumb-pipe transport: `StdioClientTransport`
reports a teardown nobody asked for through `SupervisableTransportInterface::onUnexpectedExit()`, and the
`SupervisedTransport` decorator holds a per-connection factory and respawns against it. Restarting is
enough on its own because the protocol is sessionless, so a fresh peer needs no handshake replayed.

The transport reports a replacement through `ReconnectingTransportInterface::onReconnect()`, which is the
client-side seam for rebuilding per-connection state. `Client` uses it to re-send every open
`subscriptions/listen` stream under its original subscription id, since that id is the request id the caller
still holds, and to re-send the in-flight requests that may safely be repeated.

- [x] Capture the subprocess exit code (`Process::join()`) so an unexpected exit is distinguishable from an
  intentional close.
- [x] `SupervisedTransport` decorator that respawns the subprocess on unexpected exit and re-emits the
  listener chain to an unchanged `Client`.
- [x] Re-establish active `subscriptions/listen` streams after restart.
- [x] Optional opt-in retry of the lost in-flight requests against the fresh process
  (`ClientBuilder::setRetryLostRequests()`), limited to state-reading methods because a retry is
  at-least-once and no tool is marked idempotent.

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
- [x] Model `SubscriptionsListenResult` and its dedicated `SubscriptionsListenResultResponse` envelope, with
  `SubscriptionsListenResultMetaObject` carrying the required `io.modelcontextprotocol/subscriptionId`.
  `ResultResponseFactory` routes the pair, and because the result's `_meta` is required and names the stream,
  `rebuildWithMeta` keeps that identity when the dispatcher stamps `serverInfo` over it.
- [x] Delete `UrlElicitationRequiredError` (-32042) entirely.
- [x] Serve the server half. The tool executor, prompt renderer, and resource reader contracts (and the
  stores, handlers, and closure and attribute-discovered adapters behind them) return
  `<Result>|InputRequiredResult`, and `ServerContext` carries the `inputResponses` and `requestState` the
  dispatcher reads off `InputResponseRequestParams`. `RequestStateSigner` mints and checks the continuation
  token, since the client echoes it back unverified and a handler must be able to tell its own state from a
  forged one. Eight of the twelve conformance scenarios pass.
- [x] Ask for input with elicitation only. The spec's `InputRequest` union is
  `CreateMessageRequest | ListRootsRequest | ElicitRequest`, and `latest-schema.ts` marks the first two
  `@deprecated` as of 2026-07-28 (SEP-2577) while leaving `ElicitRequest` unmarked. Neither is modelled, not
  even as a payload type that never travels as a dispatchable method: emitting one is adopting a deprecated
  feature, and the `sampling` and `roots` client capabilities carry the same deprecation, so a client built
  to this revision declares neither and a server able to ask would find nobody to ask. The receiving side
  loses nothing, because a client must answer every key in `inputRequests` or none, so one that cannot sample
  could not proceed even with the request decoded. Four conformance scenarios stay baselined as a result
  (`basic-sampling`, `basic-list-roots`, `multiple-input-requests`, and `capability-check`), raised upstream
  as [conformance#439](https://github.com/modelcontextprotocol/conformance/issues/439).
- [x] Give `readResource()` and `getPrompt()` the `inputResponses` / `requestState` parameters `callTool()`
  carries, so answering an `InputRequiredResult` on those two methods stays typed instead of dropping to
  `sendRequest()`.

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

- [x] `StreamableHttpServerTransport` implements `RequestHandlerInterface` and `TransportInterface`,
  exposing `handle(ServerRequestInterface): ResponseInterface`. Per POST it re-keys the request to a
  transport-internal id (so independent clients cannot collide on a shared JSON-RPC id), registers a
  per-request response sink under that id, emits the envelope to the dispatcher, routes outbound messages
  back by `relatedRequestId` (progress) or response id, restores the client's id on the response, and
  returns a buffered JSON response or a streaming SSE body. Response mode is `Auto` (JSON unless a related
  message arrives mid-call, then a lazy SSE upgrade), `Sse`, or `Json`.
- [x] SSE writer (`SseResponseStream`): frames `event: message\ndata: <json>\n\n`, response headers
  `text/event-stream`, `Cache-Control: no-cache`, `Connection: keep-alive`, and `X-Accel-Buffering: no`,
  plus a keep-alive comment frame buffered whenever a read stays idle past the configured interval, so the
  frame still honours the caller's `$length` and counts towards `tell()`. The body is a streaming
  `StreamInterface` fed by the transport. Closing it retires the stream (full handler cancellation waits on
  the cancellation registry).
- [x] `RequestBodySizeLimitMiddleware` (PSR-15): a configurable byte cap answered with an id-less JSON-RPC
  error on `413`, measured against the buffered body size so the transport is spared stringifying and parsing
  an oversized payload. A body whose size cannot be determined passes through, leaving a streaming cap to the
  HTTP server. The `202` / `405` / `-32700` / `406` conformance edges shipped with the transport.
- [x] `DnsRebindingProtectionMiddleware` (PSR-15): rejects a present-but-unlisted `Origin` with an id-less
  JSON-RPC error on `403`, while a request without an `Origin` header (non-browser clients) passes through.
  The allow-list is an exact-origin `list<non-empty-string>`, with `*` for allow-all, matched
  case-insensitively since RFC 9110 makes a URI's scheme and host so. The middleware also carries a
  beyond-spec, opt-in `Host` allow-list (empty disables it, otherwise the `Host` header must be present and
  listed) for fuller rebinding protection. The spec mandates only the `Origin` check.
- [x] `CorsMiddleware` (PSR-15): a beyond-spec, additive CORS helper for browser clients. An allowed `Origin`
  is reflected into `Access-Control-Allow-Origin`, a preflight `OPTIONS` is answered with `204` plus the
  negotiated `Access-Control-*` headers (echoing `Access-Control-Request-Headers`), and every other response is
  decorated. A disallowed or absent `Origin` receives no grant, so rejection stays with the DNS-rebinding gate.
  Every response carries the `Vary` keys it turns on (`Origin`, plus `Access-Control-Request-Headers` on a
  preflight) whether or not the grant was given, so a shared cache cannot replay one origin's answer to
  another. The spec does not define CORS for the MCP endpoint.
- [x] `ParameterHeaderValidationMiddleware` (PSR-15): the spec's server-side `Mcp-Param-{Name}` MUST. On a
  `tools/call` it peeks at the body without consuming it, resolves the named tool's `x-mcp-header` bindings, and
  rejects a header that is absent, malformed, or disagrees with the body argument with `-32020` on `400`, echoing
  the request id. Bindings come from paging `ToolStoreInterface::list()` once and caching the scan, so a store
  whose tool set changes after the first `tools/call` outlives the cache. A tool whose own declarations violate
  the scanner constraints is skipped with a warning, since a conforming client already excluded it from its
  listing.
- [x] `MiddlewarePipeline` (PSR-15): a re-entrant `RequestHandlerInterface` that runs middleware outermost-first
  in front of an inner handler, so operators compose the security middlewares with a plain
  `new MiddlewarePipeline($transport, ...$middleware)` and no external PSR-15 runner. The transport stays a bare
  handler, keeping composition and ordering with the operator.
- [x] `SecuredHttpEndpoint` (PSR-15): a batteries-included `RequestHandlerInterface` that wraps the transport in
  the recommended security stack from config (CORS, then DNS-rebinding, then the optional parameter-header
  validation, then the optional body-size cap). Origin allow-listing is required, so security is on-by-default
  without the permissive zero-arg defaults the spec and our explicit-config middlewares would otherwise force.
  Passing the served tool store lights up the `Mcp-Param-{Name}` validation, which a server declaring
  `x-mcp-header` must do. `ServerBuilder::getToolStore()` hands over that store, whether `setToolStore()`
  supplied it or the builder assembled it from `addTool()` and `register()` entries.
- [x] A non-blocking `Server::listen(TransportInterface)` seam that attaches the dispatcher listeners and
  starts the transport without the close-await that `run()` uses for stdio, so the endpoint can be mounted
  per request in a PSR-15 stack.
- [x] Re-introduce the `ReceiveContext` listener argument across the chain, carrying the originating
  `ServerRequestInterface` and exposing it to request handlers via `ServerContext::$receiveContext`. The
  auth-subsystem slot is added with the authorization milestone.

Client transport (`Nexus\Mcp\Client`, amphp/http-client). Adds `amphp/http-client`.

- [x] `SseFrameParser` (`Core/Http`, pure): incremental SSE framing, absorbing response chunks and yielding
  whole frames. LF, CRLF, and bare-CR terminators, a chunk ending mid-CRLF, multi-line `data`, and the
  comment lines the spec has servers emit as keep-alives and clients ignore.
- [x] `StandardHeaders::build()`: the client-side counterpart to the validator, deriving
  `MCP-Protocol-Version`, `Mcp-Method`, and `Mcp-Name` from the envelope so header and body agree by
  construction. A `Mcp-Name` outside the header-safe set (a resource URI, since tool names are constrained to
  the identifier set) carries the `=?base64?…?=` sentinel.
- [x] `StreamableHttpClientTransport implements TransportInterface`: each `send()` is a discrete POST with
  `Content-Type: application/json`, `Accept: application/json, text/event-stream`, and the standard headers
  computed from the message body. The response content-type selects single-JSON decode or SSE parse, and an
  outbound response is dropped with a warning since the spec forbids a client from sending one. amphp's
  10-second transfer timeout is disabled (it would sever a long-lived stream) in favour of a read timeout
  that must exceed the server's keep-alive interval. `close()` cancels in-flight POSTs rather than awaiting
  them, since a `subscriptions/listen` stream never ends, and a cancellation at shutdown is not reported as a
  fault.
- [x] Correlated failure: an exchange that raises is reported as an `OutboundRequestFailedException` naming
  the request it carried, so the client fails that one caller rather than leaving it awaiting a response that
  can no longer arrive. A notification carries no id and no caller, so its failure is reported unwrapped, and
  one unreadable frame mid-stream is reported without ending the exchange.
- [x] Per-request cancellation of the client's own *outbound* requests, so abandoning one aborts only that
  POST. `AbortableTransportInterface::abort(RequestId)` carries the signal, and the HTTP transport composes
  a per-request `DeferredCancellation` with the shared lifetime, so `close()` still stops everything while
  an abort reaches one exchange. `Client` calls it wherever it gives up on a response: a deadline expiring
  and a subscription stream closing. Unrelated to the inbound half, where a peer's `notifications/cancelled`
  already cancels a request the client is serving.
- [x] `x-mcp-header` mirroring (client mandatory): `listTools()` scans each tool's `inputSchema`, caches the
  bindings, and drops a tool whose declarations violate the scanner constraints with a warning, since the spec
  has a client exclude what it cannot mirror. `callTool()` builds the `Mcp-Param-{Name}` headers from the
  arguments and carries them through `SendContext`, which the HTTP transport merges into the POST and stdio
  ignores. The listing filter is gated on `ParameterHeaderMirroringInterface` so a stdio user does not lose a
  usable tool to an annotation that does not apply. `disconnect()` clears the cache, which belongs to the
  connection that served it.
- [x] `HeaderMismatch` recovery on the client: a `-32020` rejection re-lists the tool, walking pages until
  the listing yields it, then retries the call exactly once. A second mismatch propagates to the caller, and
  any other error code propagates without a retry. Each leg carries its own request deadline. The cache
  satisfies the spec's optimistic-use rule on its own, since it is rebuilt on every `tools/list`, never
  expires, and is consulted without a staleness check.
- [x] Docs and examples: the `docs/transports.md` Streamable HTTP sections for both legs, the `x-mcp-header`
  mirroring rules in `docs/client.md`, and the runnable `examples/http-server.php` / `examples/http-client.php`
  pair. `examples/PsrHttpAdapter.php` binds the PSR-15 endpoint to `amphp/http-server` (a dev dependency,
  since the SDK ships no HTTP server) and pipes an SSE body frame by frame rather than buffering it, which is
  the one thing a host must get right.

Follow-on milestones.

- [x] Remaining DoS hardening whose natural home is the per-request HTTP model.
  `ServerBuilder::setMaxInFlightDispatches()` caps how many messages the dispatcher runs at once, counted off
  `PendingCoroutines`: past the cap a request is shed with `-32000` (`SdkErrorCode::Overloaded`, which the
  Streamable HTTP transport answers `503`) before its id is claimed, so a retry is not a duplicate, and a
  notification is dropped outright since JSON-RPC 2.0 §4.1 forbids answering one. The cap is off by default,
  matching the opt-in shape of the request-body-size cap. Orphan-response and shed-notification logging both
  run through `LogThrottle`, which admits the first occurrence and every hundredth after it and never echoes
  the envelope, so a flood cannot amplify into one structured log record per message.
- [x] `subscriptions/listen` serving over a long-lived SSE stream. The streaming half was already in place:
  `SseResponseStream::read()` buffers a keep-alive comment frame instead of ending when the interval
  expires, so a stream stays open indefinitely, no max duration bounds a request, `routeNotification`
  already keys off `SendContext.relatedRequestId` (which `RequestBoundSender` binds to the listen request's
  id), and `routeResponse` ends the stream after pushing the final frame, matching the spec's graceful
  closure. Disconnect-to-cancel closed the gap: `releaseStream()` reports the abandoned request through
  `CancellableTransportInterface::onCancel()`, which `Server` routes into the per-request cancellation now
  held by `PendingInboundRequests`, so a dropped client stops its handler instead of leaving it running. The
  same cancellation serves the stdio path, where a default `notifications/cancelled` handler fires it. Over
  Streamable HTTP that notification is ignored: the spec makes closing the response stream the signal there,
  and a client's request id names a different id space from the one the transport dispatches under.
- [x] Per-request timeouts: every request carries an idle deadline that each progress notification restarts,
  plus a ceiling that ignores progress. On expiry the client frees the correlation slot, sends
  `notifications/cancelled`, and throws `RequestTimeoutException`. Both bounds are configurable on
  `ClientBuilder` and disabled with `null`, and `sendRequest()` takes a per-request override. This covers the
  silent peer that raises nothing for `OutboundRequestFailedException` to correlate.

### Authorization (OAuth 2.1)

The MCP client performs the OAuth 2.1 flow to obtain tokens for protected MCP servers, and the
conformance suite scores client-mode heavily on it (the majority of scored client scenarios are OAuth,
so client-mode conformance is not reachable without it). It builds on the HTTP client transport above.

Client (required for client-mode conformance):

- [x] Discovery: Protected Resource Metadata via the `WWW-Authenticate` `resource_metadata` URL, then
  Authorization Server metadata (RFC 8414 and OpenID `.well-known`), including the RFC 8414 path suffix.
- [x] Registration: Dynamic Client Registration with `application_type`, Client ID Metadata Document
  (CIMD), and a pre-registered-credentials fallback when there is no registration endpoint.
- [x] PKCE (S256) on the authorization request.
- [x] Token-endpoint auth: `client_secret_basic`, `client_secret_post`, and `none` (public client).
- [x] Scope handling: select from `WWW-Authenticate` / `scopes_supported` / omit, step-up on a 403
  insufficient_scope with scope accumulation, and a retry cap.
- [x] Resource Indicators (RFC 8707): send and validate the `resource` parameter.
- [x] Issuer validation (RFC 9207): validate the authorization-response `iss` and the AS-metadata issuer.
- [x] Refresh: opt into `offline_access` and use the refresh-token grant when supported.
- [x] Authorization-server migration: re-register on AS change without credential reuse.

Server (resource server, not conformance-scored but part of the auth spec):

- [x] 401 challenge with `WWW-Authenticate` carrying the `resource_metadata` URL.
- [x] Serve a Protected Resource Metadata document.
- [x] Bearer-token validation bound to this resource.

- [x] Make the wait for the coordinator's lock cancellable. Every authorization round trip is bounded by
  the cancellation of the request that needs it, and so is the wait to enter the flow: the coordinator
  races the lock acquire against the caller's cancellation, so a request queued behind a consent screen
  gives up at its own deadline rather than at that screen's completion. Re-entry from the fiber that
  already holds the lock runs the operation directly.
- [x] Settle what a new grant says about a scope granted earlier. A grant replaces the remembered set
  rather than merging onto it, because the token endpoint already resolves the ambiguity: a token
  response that omits `scope` grants what was asked (RFC 6749 §3.3), so the scopes on the token are
  authoritative and a scope a grant omits stops being asked for.

The OAuth-related official extensions (client-credentials and enterprise-managed authorization) are
covered in the "Official extensions" block below, built on this subsystem.

Ecosystem practices worth adopting, drawn from a comparison against the official TypeScript and PHP
SDKs.

- [x] Run the `modelcontextprotocol/conformance` suite in CI against a checked-in
  expected-failures baseline, and publish the server score. The harness lives in `conformance/`: a
  pinned referee, an attribute-discovered fixture, a baseline whose stale entries fail the build, and
  a scorer that counts an unmet SHOULD against the total. Server mode runs at
  `--spec-version 2026-07-28` and stands at 107 of 111 checks. The 4 that remain all need an input
  request this SDK does not model, named in the baseline.
- [x] Run the conformance suite in client mode, on the same pinned referee and baseline.
  `conformance/client.php` routes on the scenario name the referee supplies. Stands at 332 of 332
  checks, 32 of 32 scenarios, so its half of the baseline is empty. That covers the whole OAuth
  block, the SEP-2243 header scenarios (`http-custom-headers` 18 of 18), client-side MRTR,
  `request-metadata`, `tools_call`, and `json-schema-ref-no-deref`.

Defects the first conformance runs surfaced, smallest first. The three marked as unseen by the suite
are not in the baseline.

- [x] `SecureEndpoint::verifyAuthorizationServerUrl()` admitted no loopback, while the sibling
  `verifyRedirectUri()` did, so an authorization server on `http://localhost` was unusable. That
  covered the conformance referee's mock and every local development setup, and accounted for 22 of
  the 32 client scenarios. `AuthorizationOptions::$allowInsecureLoopback` now opts in, defaulting to
  off so production stays strict.
- [x] `Client::callTool()` takes `inputResponses` and `requestState`, so the typed surface can
  complete an MRTR round trip rather than forcing callers onto `sendRequest()`.
- [x] When a server rejects the requested protocol version with `-32022`, the client retries once with
  a version the error named as supported, as SEP-2575 says it SHOULD. `RemoteCallFailedException` now
  carries the `Error` it was built from, so `data.supported` survives to the dispatch layer, which
  rebuilds the request from its own `toArray()` under a restamped `_meta` and a fresh id. A rejection
  naming no version this SDK speaks propagates untouched, and the retry is not itself retried.
- [x] `metadata-var2` stopped after Protected Resource Metadata. When the path-scoped well-known URL
  404s, discovery falls back to the origin-root one, and RFC 9728 assigns that URL to the resource at
  the origin, so the document it serves names the origin rather than the path-scoped endpoint.
  `MetadataDiscovery` refused it and aborted, making the fallback unreachable by construction. It now
  accepts a document naming either the resource or its origin, and still refuses any other origin.
- [x] `auth/pre-registration` made no token request. Credentials issued out of band name no authorization
  server, but `ClientRegistration` required an issuer, so the conformance client fabricated one from the
  MCP server URL and `ClientRegistrar` correctly refused to carry credentials across issuers. The issuer
  is now nullable: an unbound registration takes the issuer discovery names, and picks up RFC 7591's
  `client_secret_basic` default when it carries a secret and the caller named no method. A registration
  that does name an issuer is still refused anywhere else.
- [x] Three OAuth scenarios each missed one obligation, and all three were gaps in the conformance
  client rather than in the SDK. `AuthorizationOptions` now carries the Client ID Metadata Document
  URL the CIMD scenario compares against, so the URL-based `client_id` is reached at all. The
  step-up challenge rides on a `tools/call`, which the fixture never made, and an
  authorization-server change is announced only after one request has already succeeded, so the
  fixture now makes a second one. The OAuth block is at 181 of 181 checks.

- [x] A missing required `prompts/get` argument answered `-32603 Internal error` rather than
  `-32602`. `ArgumentBinder::bind()` now converts the assertion failure into an
  `InvalidParamsException`, keeping the original message and cause, so every reflected prompt,
  resource, and tool handler reports a binding fault as invalid params.
- [x] `examples/attribute-discovery.php` called `ServerContext::log()`, which no longer exists, so the
  `weather` tool fataled on its first call. It reports progress instead. `examples/` is now in the
  PHPStan paths alongside `conformance/`, which is what stops the next one rotting unnoticed.
- [x] Empty object slots shipped as `[]` rather than `{}` over Streamable HTTP. Pattern A substitutes
  `\stdClass` inside `jsonSerialize()`, but both HTTP transports encoded `$message->toArray()` and
  `json_encode`d the plain array, so the substitution never ran on the send path. Every client request
  carried `"io.modelcontextprotocol/clientCapabilities":[]`, and `server/discover` answered
  `"capabilities":[]`. Both transports and the three rejecting middlewares now encode the message
  itself, which is what stdio already did.
- [x] `UnsupportedProtocolVersionError` (-32022) was never emitted for a version that is not a date.
  `ProtocolVersion` enforced a `YYYY-MM-DD` format the spec does not define, so an unrecognised
  version was rejected as malformed params before the support check ran. The constructor now takes
  any non-empty string, and the dispatcher's version gate answers -32022 with `data.supported` and
  `data.requested` as before.
- [x] `MissingRequiredClientCapabilityError` (-32021) was never raised. `MissingRequiredClientCapabilityException`
  gives a handler a way to refuse, and `HttpStatusResolver` pins the code to HTTP 400.
- [x] `ClientCapabilities` silently dropped every key outside the three it names, despite the spec (and
  its own docblock) saying the set is open. It now keeps them in `extras`, the same shape `MetaObject`
  uses, so a client declaring `sampling` or `roots` round-trips instead of vanishing. This is what lets
  a handler require a capability the SEP-2596 cleanup removed from the named set.
- [x] SEP-2164 says a resource-not-found error SHOULD carry `error.data.uri`. Protocol exceptions now
  carry an optional `errorData` payload that `ResponseSender` passes to `ErrorFactory`, and
  `ResourceNotFoundException` fills it with the URI.
- [x] Establish the optional-dependency pattern for anything cryptographic: a `suggest` entry rather
  than a `require`, guarded by `class_exists` with an actionable message naming the package to
  install. `JwksAccessTokenValidator` is the first consumer: it validates JWT bearer tokens against a
  key set through the suggested `firebase/php-jwt`, and `SuggestedDependencyGuard` is the reusable
  check any later cryptographic consumer repeats.
- [x] Grow the authorization documentation into per-provider ground: recipes for Keycloak, Entra,
  Auth0, and Okta, each covering the provider-side configuration, the token validator, and the quirks
  the generic pages cannot know.
- [x] A dockerised end-to-end example (Keycloak in compose, a protected server, the SDK client walking
  the full flow), so a reader can stand a protected server up rather than assemble one from the
  reference. `examples/keycloak-e2e/` holds the realm export, compose file, protected server, and
  flow-walking client, and the `keycloak-e2e` workflow re-runs the whole flow in CI.

### Official extensions

The SDK fully supports the official MCP extensions. They are built as the final block of the migration,
after the transport and authorization above, and each ships disabled by default with explicit opt-in per
the extensions framework (SEP-2133). Official extensions land per-extension under a top-level
`src/Extension/{Name}/` tree (the architecture ruleset lets it depend on `Core`, `Server`, and
`Client`, and nothing depends on it), splitting at 1.0 as a single `nexusphp/mcp-extensions` package.

- [x] Extensions framework primitive: a first-class extension object (`ServerExtensionInterface` /
  `ClientExtensionInterface` declaring identifier, settings, method-to-class maps, and handlers) consumed
  by `ServerBuilder::enableExtension(...)` and `ClientBuilder::enableExtension(...)`. The server
  advertises the `extensions` capability slot on `server/discover` and auto-gates every extension-owned
  request behind the client's per-request declared capabilities (`-32021` otherwise). The client
  advertises enabled extensions in the `_meta` stamp on every request and refuses an extension's declared
  outbound methods against a server that did not advertise it. `Client::stampMeta()` is public so
  hand-built `sendRequest()` requests carry the same lifecycle `_meta`.
- [x] Tasks (`io.modelcontextprotocol/tasks`, SEP-2663) under `Nexus\Mcp\Extension\Tasks\*`: the method
  set is `tasks/get` (polling), `tasks/update` (client-to-server input), and `tasks/cancel`. There is no
  `tasks/list` and no blocking `tasks/result` (both answer `-32601`). The server extension decorates
  `tools/call` through the framework's `RequestHandlerDecoratorInterface` seam with a broker driven by
  per-tool `ToolTaskPolicy` entries, backed by a `TaskStoreInterface` with sticky terminal states and
  terminal-anchored TTL retention. A task handle (`resultType: "task"`, opening the `ResultType` enum)
  is only returned to a client whose per-request capabilities declared the extension. The client half
  pairs `TasksClientExtension` with the polling `TaskClient` facade. The ten referee `tasks-*` scenarios
  pass (35 checks), with `tasks-status-notifications` SKIPPED upstream as a placeholder.
  - [ ] `notifications/tasks` delivered via `subscriptions/listen`. SEP-2663 makes these notifications
    optional (a server is conformant whether or not it sends them), so the polling-only `TaskClient`
    loop stands on its own. The upstream scenario is a placeholder pending a harness rewrite against
    the subscriptions channel, and that rewrite is the trigger to pick this up. Settling the loop onto
    push updates starts with the SEP's opt-in shape for the listen filter, which `SubscriptionFilter`
    has no slot for.
- [x] MCP Apps (SEP-1865, `io.modelcontextprotocol/ui`) under `Nexus\Mcp\Extension\Apps`. The
  extension defines no JSON-RPC methods, so the SDK surface is metadata and negotiation: typed
  `_meta.ui` value objects for tools and resources (CSP allow-lists, sandbox permissions, dedicated
  domain, border hint), `UiResource` enforcing the `ui://` scheme and `text/html;profile=mcp-app`
  mime type at construction, `AppsServerExtension` advertising the empty server slot,
  `AppsClientExtension` declaring the client's required `mimeTypes`, and the `AppClient` facade
  resolving metadata and verifying `ui://` reads against the mime profile. The deprecated flat
  `ui/resourceUri` key is read-tolerated and never emitted. The `ui/*` postMessage family
  (`ui/initialize`, host notifications, sandbox proxy) is the browser host's side and stays
  unmodelled. The pinned conformance referee carries no apps scenarios, so there is no conformance
  claim to make yet. Extension-tagged scenarios sit outside every referee suite, so if a later
  referee release adds `apps-*` scenarios, adopting them is a deliberate step at pin-bump time:
  extend the scenario list in `conformance/run-extensions.sh` and the extension prefixes in
  `conformance/score.php`.
- [x] OAuth client-credentials (`io.modelcontextprotocol/oauth-client-credentials`, SEP-1046) and
  enterprise-managed authorization (`io.modelcontextprotocol/enterprise-managed-authorization`,
  SEP-990), built on the authorization subsystem above. `Nexus\Mcp\Extension\Auth` ships
  `ClientCredentialsGrant` (basic or `private_key_jwt` credentials, the latter signing RFC 7523
  assertions through the suggested `firebase/php-jwt`) and `IdentityAssertionGrant` (RFC 8693
  token exchange at the enterprise IdP, then the RFC 7523 JWT-bearer redemption), both plugged
  into `AuthorizedHttpClient` through the grant-strategy seam extracted from the coordinator,
  plus settings-free capability declarations for both sides. The three referee scenarios
  (`auth/client-credentials-basic`, `auth/client-credentials-jwt`,
  `auth/enterprise-managed-authorization`) pass and score into the client-extensions badge via
  `composer conformance:extensions:client`. DPoP (SEP-1932) and workload identity federation
  (SEP-1933) are open proposals upstream and stay unmodelled until ratified.

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

The schema generator and every spec reference track the dated release: `McpSchemaProcessor` fetches
`schema/2026-07-28/` into the local `latest-schema.json` / `latest-schema.ts` references, and the `@see`
tags across `src/` plus the `SchemaConformanceTest` and `McpAnchorSnapshot` constants point at the
`specification/2026-07-28/` docs pages.

- [x] Repoint the `@see` URLs in `src/` and the `schema/2026-07-28/schema.ts` blob link at the dated pages.
  Almost a prefix swap, with one page moved between the draft and the dated release:
  `basic/utilities/mrtr` is now `basic/patterns/mrtr`.
- [x] Update `SchemaConformanceTest`'s `SCHEMA_ANCHOR_BASE_URL` and `TS_SCHEMA_FILE_URL`, plus
  `McpAnchorSnapshot::SPEC_BASE_URL`, to the dated spec, and refresh the anchor snapshot.

## Server ergonomics

Composition-surface quality-of-life items, independent of any spec revision.

- [x] Make the paginated-store settings configurable from the fluent `ServerBuilder` path. `setPageSize()`,
  `setTtlMs()`, and `setCacheScope()` supply the defaults for every store the builder assembles from its
  `add*()` entries. A store injected through `setToolStore()` and its siblings keeps its own values.

## Transports

`TransportInterface` accommodates both transports without a breaking change: `SendContext` is a value-object
slot for transport-specific routing fields (`relatedRequestId`, `fromHandler`, `headers`), and `onDrain` is
symmetric with `onClose` so streaming responses can flush before the connection closes.

- [x] `Nexus\Mcp\Server\Transport\StdioServerTransport`.
- [x] `Nexus\Mcp\Core\Transport\InMemoryTransport` (test-only paired transports).
- [x] `Nexus\Mcp\Client\Transport\StdioClientTransport` (subprocess launcher).
- [x] `Nexus\Mcp\Server\Transport\StreamableHttpServerTransport` (PSR-15 handler, mounted by the host).
- [x] `Nexus\Mcp\Client\Transport\StreamableHttpClientTransport` (one POST per message).

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
