# Roadmap

This document describes where the SDK is today, what is queued for the next few minor releases, and how
the package plans to track upcoming MCP spec revisions and PHP language versions. It is intentionally
forward-looking: see [docs/architecture.md](docs/architecture.md) for the canonical description of what
already ships.

## Current capabilities

The SDK targets MCP spec **2025-11-25** and is published on Packagist (latest **v0.3.0**, pre-stable). It
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

Server-initiated requests (`elicitation/create`) and the HTTP transport are not implemented yet. They
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
subscription primitive that replaces today's `resources/subscribe` / `unsubscribe`.

- [ ] Delete `initialize` request + `notifications/initialized`, reshape lifecycle around per-request
  `_meta`.
- [ ] Implement `server/discover` request method.
- [ ] Implement `subscriptions/listen` request method + `SubscriptionsAcknowledgedNotification`.
- [ ] Delete `resources/subscribe` / `resources/unsubscribe` (none of these are implemented today, so
  this is a non-action verified by the migration).

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
that need to ask the client for input mid-request (elicitation) return an `InputRequiredResult` instead
of throwing the current `UrlElicitationRequiredError`. The single
generic `JsonRpcResultResponse<TResult>` splits into 18 per-method response envelopes.

- [ ] Add `resultType` enum + discriminator to the success-response parser.
- [ ] Add the `InputRequest` union with only its `ElicitRequest` member (the spec also defines
  `CreateMessageRequest` and `ListRootsRequest`, but sampling and roots are deleted, so the SDK does not
  implement them), `InputRequiredResult` (`inputRequests` + opaque `requestState`), and
  `InputResponseRequestParams` (`inputResponses` + `requestState`). The client retries by re-issuing the
  original request, so there is no `tasks/input_response` method.
- [ ] Generate 18 per-method `*ResultResponse` envelope classes.
- [ ] Delete `UrlElicitationRequiredError` (-32042) entirely. The success-result-based mechanism
  replaces it.

### Tool schema relaxation (SEP-2106)

`Tool.inputSchema` and `outputSchema` accept full JSON Schema 2020-12 through an open
`[key: string]: unknown` slot. Today's `Tool::projectSchemaEnvelope()` keeps only `type`, `$schema`,
`properties`, and `required`. The migration relaxes it to pass arbitrary top-level keywords through.

This step also resolves two attribute-discovery findings blocked on it:

- `#[InputSchema(definition: ...)]` is documented as a full schema override but is currently truncated
  to `type`/`$schema`/`properties`/`required` by `projectSchemaEnvelope`, so any other root construct
  (`additionalProperties`, `$defs`, `allOf`, conditionals) is dropped from the advertised schema. The
  doc-accuracy slice (narrowing the attribute's PHPDoc so it stops claiming a full override) lands
  pre-migration. Preserving the full definition is this SEP-2106 work.
- Once `definition` can carry arbitrary schemas (including a nested-DTO constructor),
  `ArgumentBinder::construct` must raise a clean SDK exception instead of leaking a raw `\TypeError`
  when a constructor parameter is itself an expandable class.

- [ ] Relax `Tool::projectSchemaEnvelope()` to preserve all top-level JSON Schema 2020-12 keywords.
- [ ] Preserve `#[InputSchema(definition: ...)]` verbatim once the schema root accepts arbitrary keywords.
- [ ] Raise a clean SDK exception (not `\TypeError`) for an unconstructable nested-DTO constructor
  parameter reached via the `definition` bypass.

### Deterministic tool ordering

The revision adds a SHOULD that servers return `tools/list` in a deterministic order (the same ordering
across requests when the tool set is unchanged) so clients can cache the list and improve LLM
prompt-cache hit rates. The SDK already satisfies this by construction: the tool store preserves
registration order. The migration verifies the property rather than adding new work.

- [ ] Verify deterministic `tools/list` ordering holds after the migration.

### Deprecation cleanup

The 2026-07-28 spec marks Roots, Sampling, and Logging as `@deprecated`. The SDK does not implement a
compatibility window: these are deleted from core at the migration cut.

- [ ] Delete Roots (`roots/list`, `notifications/roots/list_changed`, `Root`, the capability slot).
- [ ] Delete Logging (`logging/setLevel`, `notifications/message`, `LoggingLevel`, the capability
  slot, `SetLevelRequestHandler`, `LoggingLevelGate`, and the `log()` helper on `ServerContext`).
- [ ] Delete Sampling from core (`sampling/createMessage`, `CreateMessageRequest`,
  `CreateMessageResult`, `SamplingMessage`, the capability slot). Sampling is not shipped, not even as an
  optional extension.

### TTL on list results

A new `CacheableResult` interface lets servers tell clients how long a list result stays fresh
(`ttlMs` + `cacheScope`). Replaces the `*ListChanged` notification pattern as the primary cache-busting
mechanism. Today's stores need a way to surface TTL on their list outputs.

- [ ] Add `CacheableResult` interface under `Core/Schema/`.
- [ ] Implement on `ListToolsResult`, `ListPromptsResult`, `ListResourcesResult`,
  `ListResourceTemplatesResult`, `ReadResourceResult`.
- [ ] Plumb `?int $ttlMs` + `?string $cacheScope` through the per-feature stores.

### Streamable HTTP transport

The HTTP transport reshapes around the sessionless / stateless protocol changes, gains a required
request-metadata header layer (`MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name`) with anti-spoofing
cross-checks against body content, adds the `x-mcp-header` mechanism for custom headers sourced from tool
parameters, drops GET-based SSE entirely, and removes resumable streams. It is built against the release
candidate's stateless shape, after the schema and protocol layer it carries.

- [ ] Streamable HTTP server transport (POST-only MCP endpoint, per-request SSE streams, `Origin`
  validation, `X-Accel-Buffering: no` on SSE).
- [ ] Streamable HTTP client transport.
- [ ] Request-metadata header layer: emit and validate `MCP-Protocol-Version`, `Mcp-Method`, and
  `Mcp-Name`, rejecting any header-vs-body mismatch (or missing/malformed required header) as
  `-32001 HeaderMismatch` on HTTP 400. Integers compare numerically, not as strings.
- [ ] `x-mcp-header` to `Mcp-Param-{Name}` mirroring (client side is mandatory): mirror designated
  tool-parameter values (primitive types only, `number` banned, any nesting depth) into headers with the
  `=?base64?…?=` value-encoding for non-ASCII / whitespace / sentinel values, and reject (exclude from
  `tools/list`) any tool whose `x-mcp-header` violates the field-name / uniqueness / type constraints.
- [ ] Server-side `Mcp-Param-{Name}` validation against the body, emitting `-32001` on mismatch.

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
  prune `tasks/list` / `tasks/result` / `tasks/create`, add `tasks/update`, and route the
  `resultType: "task"` variant through the result discriminator.
- [ ] MCP Apps (SEP-1865): the `ui://` URI scheme, `text/html;profile=mcp-app`, and the sandboxed
  iframe interaction model.
- [ ] OAuth client-credentials (`io.modelcontextprotocol/oauth-client-credentials`) and
  enterprise-managed authorization (SEP-990), built on the authorization subsystem above.

### OpenTelemetry trace context

W3C Trace Context keys (`traceparent`, `tracestate`, `baggage`) become an explicit allowlist exception
to the DNS-prefix `_meta` convention. The runtime `_meta` validator needs to permit them unprefixed.

- [ ] Allow `traceparent` / `tracestate` / `baggage` unprefixed in `RequestMetaObject` validation.

## Transports

The `TransportInterface` is already shaped to accommodate streamable HTTP without a breaking change:
`sessionId()` is optional (stdio returns `null`, HTTP populates it), `SendContext` is a value-object
slot for transport-specific routing fields (`relatedRequestId`, resumption tokens), and `onDrain` is
symmetric with `onClose` so streaming responses can flush before the connection closes.

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
