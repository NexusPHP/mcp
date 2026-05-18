# Architecture

This document covers how the SDK is laid out, the layering rules between namespaces, the dispatch kernel
that drives every inbound JSON-RPC message, and what the SDK does and does not cover against the spec.

## Namespaces

```text
Nexus\Mcp\
├── Core\               Protocol primitives. Depends on no other Mcp namespace
│   ├── Schema\         Types only (value objects, enums, interfaces)
│   ├── JsonRpc\        Envelope parser, method registry
│   ├── Handler\        RequestHandlerInterface, NotificationHandlerInterface, HandlerRegistry, AbstractContext
│   │   └── Request\    PingRequestHandler
│   ├── Transport\      TransportInterface, TransportEvents, in-memory implementation
│   ├── Exception\      McpExceptionInterface marker + concrete protocol errors
│   ├── Validation\     URI templates, RFC 3339, enum-value coercion
│   └── UriTemplate\    RFC 6570 expansion + matching
├── Server\             Server-side composition. Depends on Core only
│   ├── ServerBuilder
│   ├── Server
│   ├── ServerContext
│   ├── AbstractPaginatedStore
│   ├── Dispatch\       MessageDispatcher, InitializationGate, RequestBoundSender
│   ├── Handler\
│   │   └── Request\    Built-in request handlers
│   ├── Tool\           ToolStore + executor adapters
│   ├── Prompt\         PromptStore + renderer adapters
│   ├── Resource\       ResourceStore + ResourceTemplateStore + reader adapters
│   ├── Completion\     CompletionStore
│   ├── Logging\        LoggingLevelGate
│   └── Transport\      StdioServerTransport
└── Client\             not yet shipped. Planned for the next phase
```

### Layering rules

- **`Core` depends on no other `Nexus\Mcp` namespace.** It is the shared vocabulary both server-side and
  client-side consume.
- **`Core/Schema/` is types only.** No parsers, no codecs, no registries. Behaviour for those lives in
  sibling namespaces (`Core/JsonRpc/`, `Core/Validation/`, `Core/UriTemplate/`). Abstract bases sit at the
  namespace root with concrete subclasses in same-named subfolders (`Schema/Result.php` +
  `Schema/Result/EmptyResult.php`, `Schema/Request.php` + `Schema/Request/PingRequest.php`, etc.).
- **`Server` depends on `Core` only.** No back-references from `Core` into `Server`.
- **`Client` will depend on `Core` only.** `Server` and `Client` are symmetric peers, neither depending on the
  other.

## The `Arrayable` contract

Every JSON-RPC envelope, params block, and result type implements
[`Nexus\Mcp\Core\Schema\Arrayable`](../src/Core/Schema/Arrayable.php). The contract gives every schema class
three matching shapes:

| Method | Direction |
| --- | --- |
| `static fromArray(array $data): static` | envelope → PHP value object |
| `toArray(): array` | PHP value object → envelope (round-trip representation) |
| `jsonSerialize(): array\|\stdClass` | PHP value object → envelope (what `json_encode` emits) |

`toArray()` and `jsonSerialize()` usually return the same shape. When they diverge intentionally (e.g. an
empty object slot that should encode as `{}` instead of `[]`), the class substitutes `\stdClass` in
`jsonSerialize()` and opts in to the `encodingPathsDiverge` flag in the auto-review round-trip fixture
registry. The `AbstractRoundTripTestCase` asserts `json_encode($x) === json_encode($x->toArray())` for every
other class, so drift between the two paths fails the build.

One documented exception: `JsonRpcResultResponse`. A success-response envelope has no method-name or
discriminator, so the parser needs caller-supplied context (the expected `Result` subclass) to decode it.
That wrapper therefore has no `fromArray()` and is constructed only via
`JsonRpcMessageParser::parse(..., resultClass: SomeResult::class)`.

## The dispatch kernel

[`MessageDispatcher`](../src/Server/Dispatch/MessageDispatcher.php) is the per-envelope inbound pipeline.

```text
inbound envelope (array)
   │
   ▼
JsonRpcMessageParser::parse()        ← classifies request/notification/response, raises typed protocol exceptions
   │
   ├── parse failed
   │     │
   │     ├── response shape    → discard with a warning (server has no outbound-request correlation)
   │     ├── notification shape → drop silently per JSON-RPC 2.0 §4.1
   │     └── request shape     → send an error response
   │
   └── parse succeeded
         │
         ├── JsonRpcRequest      → dispatchRequest()
         │     ├── $gate->allowsRequest($method)?
         │     ├── spawn async coroutine → resolve handler → emit response or error
         │     └── on initialize success, $gate->markInitializeInFlight()
         │
         ├── JsonRpcNotification → dispatchNotification()
         │     ├── initialized notification: $gate->markInitialized()
         │     ├── other notifications: dropped if uninitialized, dispatched otherwise
         │     └── spawn async coroutine → call handler (no response)
         │
         └── response shape      → discard (server has no outbound-request correlation)
```

Two pieces are worth calling out:

### `InitializationGate`

Holds a single piece of state: the lifecycle phase (`AwaitingInitialize`, `InitializeInFlight`, `Initialized`).
Consulted on every request to enforce the spec's "no other method may be invoked before `initialize`
completes" rule. It also rejects a second `initialize` once one is in flight, and silently drops
`notifications/initialized` envelopes that arrive outside a valid handshake.

### `LoggingLevelGate`

Same shape as `InitializationGate`: a single mutable `LoggingLevel`, mutated by the `logging/setLevel`
handler, consulted by `ServerContext::log()` before emitting any `notifications/message`. The SDK ships a
default `SetLevelRequestHandler` so the always-advertised `logging` capability is actually honoured. See
[examples/stdio-server.php](../examples/stdio-server.php) for an example that bridges the client-controlled
level to the operator's PSR-3 logger.

### Coroutine draining

Each `dispatchRequest()` and `dispatchNotification()` call spawns an `Amp\async` coroutine and tracks the
resulting `Future` in a `SplObjectStorage`. When the transport fires its `onDrain` listeners (before close),
the dispatcher awaits every pending future so in-flight responses are flushed before the transport actually
closes. Without this, a tool that finishes computing right as the transport closes would lose its response
to a race with the close listeners.

## Spec compliance

- Target: **MCP spec 2025-11-25** only. No back-compat shims for earlier revisions (no JSON-RPC batching, no
  protocol-version negotiation against older drafts).
- The canonical schema lives at `latest-schema.json` in the repo root. The class-to-schema map is in
  `sorted-schema.json`. Both are regenerated by `composer schema:generate`.
- Auto-review tests (`tests/AutoReview/`) verify every PHP class with a spec counterpart matches the
  canonical description, every `@see` link resolves to a real anchor in the spec docs, and every round-trip
  fixture matches the canonical envelope shape.
- What we have today: server-side covering tools, prompts, resources (static + templated), completions,
  logging, ping. Stdio transport.
- What we do not have yet: client side, streamable HTTP transport, sampling / elicitation, tasks, OAuth,
  MCP Apps. These land across subsequent phases. Tasks, sampling, elicitation, and MCP Apps in particular
  reshape significantly in the upcoming 2026-06-30 RC and are deferred to that migration rather than built
  twice.

## Static analysis and runtime validation

- **PHPStan level 10 + strict rules** across `src/`, `tests/`, and `tools/`. Type-inference lock-in tests
  under `tests/AutoReview/data/` pin the generic contracts of the spec classes so a refactor that widens a
  return type fails the build.
- **Mutation testing** (Infection) gates at 100% MSI, 100% MCC, and 100% covered-code MSI. Infection's
  `--static-analysis-tool=phpstan` flag is on by default so the type system also acts as a mutant killer.
- **Runtime validation** uses [`nexusphp/assert`](https://github.com/NexusPHP/assert) at every envelope
  boundary. The library's PHPStan extension narrows types after each assertion, so the value objects can
  carry strict types throughout the codebase.

## See also

- **[Getting started](getting-started.md)**: minimal server.
- **[Server API](server.md)**: builder reference.
- **[Transports](transports.md)**: stdio contract. HTTP planning.
