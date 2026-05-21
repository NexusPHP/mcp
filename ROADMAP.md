# Roadmap

This document describes where the SDK is today, what is queued for the next few minor releases, and how
the package plans to track upcoming MCP spec revisions and PHP language versions. It is intentionally
forward-looking: see [docs/architecture.md](docs/architecture.md) for the canonical description of what
already ships.

## Current capabilities

The SDK targets MCP spec **2025-11-25**. Server-side composition is shipped and covers the surface most
real servers need:

- [x] Tools (`tools/list`, `tools/call`).
- [x] Prompts (`prompts/list`, `prompts/get`).
- [x] Resources, static and RFC 6570 templated (`resources/list`, `resources/templates/list`,
  `resources/read`).
- [x] Completions (`completion/complete`).
- [x] Logging (`notifications/message` + `logging/setLevel`).
- [x] Ping.
- [x] Stdio transport for one-process-per-session deployments.
- [x] Static analysis floor: PHPStan level 10 + strict rules. Infection at 100% MSI, 100% MCC, 100%
  covered code MSI.

The client-side namespace (`Nexus\Mcp\Client`) is not yet published. The shared protocol kernel under
`Nexus\Mcp\Core` is already laid out as the common dependency for both sides.

## Near-term: client-side composition

The next minor cycle ships the `Client` namespace as a symmetric peer of `Server`. The two sides share
the same dispatch kernel, the same handler registry shape, and the same transport contract; only the
composition surface (`ClientBuilder`, the client-side stores) is new.

Two protocol primitives land alongside the client namespace because both sides need them. The first is
a shared `MessageDispatcherInterface` extracted from the current server-only `MessageDispatcher`, so the
client and server can be written against the same contract from the first commit. The second is a
`PendingOutboundRequests` service under `Core/Dispatch/` that correlates inbound responses to awaiting
senders by `RequestId`. It is a sibling of the dispatcher, not a slot on the transport contract; the
transport stays a dumb pipe. The client uses it for every request it issues. The server will use it for
server-initiated request methods once those land post-2026-06-30 (see below). Today
`RequestBoundSender::sendRequest()` is a stub that throws, because the routing primitive does not exist
yet and adding it under the server alone would force a rewrite when the client lands.

The scope is deliberately bounded to pieces unaffected by the 2026-06-30 spec migration: scope is
stdio-only on both sides, with the HTTP transport and the server-initiated request methods
(`sampling/createMessage`, `elicitation/create`) deferred to the migration bundle. Building those
against the current spec means rebuilding them after the migration reshapes both.

- [x] Extract `MessageDispatcherInterface` from the current server-only `MessageDispatcher`.
- [x] Add the `PendingOutboundRequests` correlation primitive under `Core/Dispatch/`, keyed by `RequestId`.
- [x] Ship `Nexus\Mcp\Client\` with `ClientBuilder`, client-side stores, and the shared dispatch kernel.
- [x] Cover the symmetric handshake (`initialize` / `notifications/initialized`) from the client side.
- [ ] `Nexus\Mcp\Client\Transport\StdioClientTransport` (subprocess launcher).
- [ ] End-to-end stdio client / server example.

## v0.1.0

Once the client namespace ships and the SDK exposes a symmetric server / client surface, the package
tags **v0.1.0**. That is the first release that downstream consumers can pin against with confidence
that the protocol-level public API is stable for a full MCP spec revision.

Releases before v0.1.0 may break BC freely. Releases from v0.1.0 through v0.x.0 follow the same
"breaking changes allowed in minor versions" policy until v1.0.0, which is gated on a stable upstream
MCP spec.

- [ ] Client-side composition merged.
- [ ] CHANGELOG documents the v0.1.0 surface vs the pre-release iteration.
- [ ] Tag and publish v0.1.0.

## MCP 2026-06-30 migration

The next MCP spec revision (currently in RC as **2026-06-30**) reshapes the protocol significantly. The
SDK tracks 2025-11-25 only until the RC stabilises, then migrates as a single coordinated bundle.

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
subscription primitive that replaces today's `resources/subscribe` / `unsubscribe`.

- [ ] Delete `initialize` request + `notifications/initialized`, reshape lifecycle around per-request
  `_meta`.
- [ ] Implement `server/discover` request method.
- [ ] Implement `subscriptions/listen` request method + `SubscriptionsAcknowledgedNotification`.
- [ ] Delete `resources/subscribe` / `resources/unsubscribe` (none of these are implemented today, so
  this is a non-action verified by the migration).

### Multi round-trip requests (MRTR)

Every result envelope gains a `resultType` discriminator (`"complete"` vs `"input_required"`). Servers
that need to ask the client for input mid-request (elicitation, sampling, roots) return an
`InputRequiredResult` instead of throwing the current `UrlElicitationRequiredError`. The single
generic `JsonRpcResultResponse<TResult>` splits into 18 per-method response envelopes.

- [ ] Add `resultType` enum + discriminator to the success-response parser.
- [ ] Add `InputRequest` union (`ElicitRequest`, `CreateMessageRequest`, `ListRootsRequest`) and the
  `tasks/input_response` method.
- [ ] Generate 18 per-method `*ResultResponse` envelope classes.
- [ ] Delete `UrlElicitationRequiredError` (-32042) entirely; the success-result-based mechanism
  replaces it.

### Deprecation cleanup

The 2026-06-30 spec marks Roots, Sampling, and Logging as `@deprecated`. The SDK does not implement a
compatibility window: these are deleted from core at the migration cut.

- [ ] Delete Roots (`roots/list`, `notifications/roots/list_changed`, `Root`, the capability slot).
- [ ] Delete Logging (`logging/setLevel`, `notifications/message`, `LoggingLevel`, the capability
  slot, `SetLevelRequestHandler`, `LoggingLevelGate`, and the `log()` helper on `ServerContext`).
- [ ] Delete Sampling from core (`sampling/createMessage`, `CreateMessageRequest`,
  `CreateMessageResult`, `SamplingMessage`, the capability slot).
- [ ] Keep the door open for an optional `Nexus\Mcp\Extension\Sampling\*` namespace if a downstream
  consumer needs it. Not built speculatively.

### TTL on list results

A new `CacheableResult` interface lets servers tell clients how long a list result stays fresh
(`ttlMs` + `cacheScope`). Replaces the `*ListChanged` notification pattern as the primary cache-busting
mechanism. Today's stores need a way to surface TTL on their list outputs.

- [ ] Add `CacheableResult` interface under `Core/Schema/`.
- [ ] Implement on `ListToolsResult`, `ListPromptsResult`, `ListResourcesResult`,
  `ListResourceTemplatesResult`, `ReadResourceResult`.
- [ ] Plumb `?int $ttlMs` + `?string $cacheScope` through the per-feature stores.

### Extensions framework + Tasks

The Tasks layer relocates out of core into an extension namespace
(`Nexus\Mcp\Extension\Tasks\*`). The general-purpose extensions framework formalised at the same time
defines how downstream SDKs register opt-in extensions and how `ServerCapabilities` surfaces them.

- [ ] Design `ServerBuilder::enableExtension(...)` (or similar) and the
  `ServerCapabilities.extensions` slot.
- [ ] Move task classes to `Nexus\Mcp\Extension\Tasks\*` and prune the deleted methods (`tasks/list`,
  `tasks/result`, `tasks/create`).
- [ ] Add `tasks/update`; route `CreateTaskResult` through the `resultType: "task"` discriminator.

### MCP Apps

The first official non-tasks extension. Defines a `ui://` URI scheme and a sandboxed iframe interaction
model. Optional; built on top of the extensions framework above.

- [ ] Implement when a downstream consumer asks for it. Not on the critical path.

### Streamable HTTP transport

The HTTP transport reshapes around the sessionless / stateless protocol changes, gains two required
headers (`Mcp-Method`, `Mcp-Name`) with anti-spoofing cross-checks against body content, drops
GET-based SSE entirely, and removes resumable streams. Building the transport pre-migration means
building against a shape the spec is about to invalidate.

- [ ] Streamable HTTP server transport.
- [ ] Streamable HTTP client transport.

### OpenTelemetry trace context

W3C Trace Context keys (`traceparent`, `tracestate`, `baggage`) become an explicit allowlist exception
to the DNS-prefix `_meta` convention. The runtime `_meta` validator needs to permit them unprefixed.

- [ ] Allow `traceparent` / `tracestate` / `baggage` unprefixed in `RequestMetaObject` validation.

## Transports

The `TransportInterface` is already shaped to accommodate streamable HTTP without a breaking change:
`sessionId()` is optional (stdio returns `null`, HTTP populates it), `SendContext` is a value-object
slot for transport-specific routing fields (`relatedRequestId`, resumption tokens), and `onDrain` is
symmetric with `onClose` so streaming responses can flush before the connection closes.

Streamable HTTP lands with the 2026-06-30 migration since the spec's session-management semantics also
move on that revision.

- [x] `Nexus\Mcp\Server\Transport\StdioServerTransport`.
- [x] `Nexus\Mcp\Core\Transport\InMemoryTransport` (test-only paired transports).
- [ ] `Nexus\Mcp\Client\Transport\StdioClientTransport` (subprocess launcher; lands with the client
  namespace).
- [ ] Streamable HTTP server transport (lands with the 2026-06-30 migration).
- [ ] Streamable HTTP client transport.

## Language compatibility

The SDK currently targets **PHP 8.4** minimum and uses the language features that became available in
that version (typed class constants, readonly classes, constructor property promotion, asymmetric
visibility, property hooks, `#[\Override]`).

PHP 8.5 brings covariant `static` return types for factory methods
([php/php-src#17724](https://github.com/php/php-src/pull/17724)). Once the minimum PHP version is raised
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
- **[Transports](docs/transports.md)**: stdio contract + HTTP planning.
- **[Architecture](docs/architecture.md)**: dispatch kernel, layering, spec compliance.
