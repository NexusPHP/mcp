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
- [ ] Serve `subscriptions/listen` server-side: a handler that returns the empty result response (leaving
  the dispatcher's result-send path unchanged) and emits `SubscriptionsAcknowledgedNotification` as the
  first stream message, tagged with the server-minted `io.modelcontextprotocol/subscriptionId` `_meta` key.
  Requires a subscription store and a list-changed / resource-updated fanout source to feed the stream.
- [x] Delete `resources/subscribe` / `resources/unsubscribe` (none of these are implemented today, so
  this is a non-action verified by the migration).
- [x] Carry the server identity on result `_meta`: `ResultMetaObject` (the result-side peer of
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
  `x-mcp-header` must do.
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
- [ ] Per-request cancellation, so abandoning one request aborts only that POST. `TransportInterface` has no
  cancel seam yet, so this lands with the cancellation registry.
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
- [ ] `subscriptions/listen` serving over a long-lived SSE stream. The transport already supports
  long-lived streams structurally. The handler lands when the subscriptions result leg unblocks.
- [x] Per-request timeouts: every request carries an idle deadline that each progress notification restarts,
  plus a ceiling that ignores progress. On expiry the client frees the correlation slot, sends
  `notifications/cancelled`, and throws `RequestTimeoutException`. Both bounds are configurable on
  `ClientBuilder` and disabled with `null`, and `sendRequest()` takes a per-request override. This covers the
  silent peer that raises nothing for `OutboundRequestFailedException` to correlate.

### Authorization (OAuth 2.1)

Authorization is a committed part of the v1.0.0 surface. The MCP client must perform the OAuth 2.1 flow
to obtain tokens for protected MCP servers, and the conformance suite scores client-mode heavily on it
(the majority of scored client scenarios are OAuth, so client-mode conformance is not reachable without
it). It depends on the HTTP client transport above and is built after it.

Client (required for client-mode conformance):

- [x] Discovery: Protected Resource Metadata via the `WWW-Authenticate` `resource_metadata` URL, then
  Authorization Server metadata (RFC 8414 and OpenID `.well-known`), including the RFC 8414 path suffix.
- [x] Registration: Dynamic Client Registration with `application_type`, Client ID Metadata Document
  (CIMD), and a pre-registered-credentials fallback when there is no registration endpoint.
- [x] PKCE (S256) on the authorization request.
- [x] Token-endpoint auth: `client_secret_basic`, `client_secret_post`, and `none` (public client).
- [ ] Scope handling: select from `WWW-Authenticate` / `scopes_supported` / omit, step-up on a 403
  insufficient_scope with scope accumulation, and a retry cap.
- [x] Resource Indicators (RFC 8707): send and validate the `resource` parameter.
- [x] Issuer validation (RFC 9207): validate the authorization-response `iss` and the AS-metadata issuer.
- [ ] Refresh: request `offline_access` and use the refresh-token grant when supported.
- [x] Authorization-server migration: re-register on AS change without credential reuse.

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
