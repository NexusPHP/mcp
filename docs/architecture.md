# Architecture

This document covers how the SDK is laid out, the layering rules between namespaces, the dispatch kernel
that drives every inbound JSON-RPC message, and what the SDK does and does not cover against the spec.

## Namespaces

```text
Nexus\Mcp\
├── Core\               Protocol primitives. Depends on no other Mcp namespace
│   ├── Dispatch\       MessageDispatcherInterface
│   ├── Exception\      McpExceptionInterface marker + concrete protocol errors
│   ├── Handler\        RequestHandlerInterface, NotificationHandlerInterface, HandlerRegistry, AbstractContext
│   │   └── Request\    PingRequestHandler
│   ├── JsonRpc\        Envelope parser, method registry
│   ├── Schema\         Types only (value objects, enums, interfaces)
│   ├── Transport\      TransportInterface, TransportEvents, in-memory implementation
│   ├── UriTemplate\    RFC 6570 expansion + matching
│   └── Validation\     URI templates, RFC 3339, enum-value coercion
├── Server\             Server-side composition. Depends on Core only
│   ├── AbstractPaginatedStore
│   ├── Server
│   ├── ServerBuilder
│   ├── ServerContext
│   ├── Completion\     CompletionStore
│   ├── Dispatch\       ServerMessageDispatcher, InitializationGate, RequestBoundSender
│   ├── Exception\      Server-side protocol errors
│   ├── Handler\
│   │   └── Request\    Built-in request handlers
│   ├── Logging\        LoggingLevelGate
│   ├── Prompt\         PromptStore + renderer adapters
│   ├── Resource\       ResourceStore + ResourceTemplateStore + reader adapters
│   ├── Tool\           ToolStore + executor adapters
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

[`ServerMessageDispatcher`](../src/Server/Dispatch/ServerMessageDispatcher.php) implements the shared
[`MessageDispatcherInterface`](../src/Core/Dispatch/MessageDispatcherInterface.php) for the server side. It
is the per-envelope inbound pipeline.

```text
inbound envelope (array)
   │
   ├── response shape (`result` or `error` key) → discard with a warning (server has no outbound-request correlation)
   │
   ▼
JsonRpcMessageParser::parse()        ← classifies request/notification, raises typed protocol exceptions
   │
   ├── parse failed
   │     │
   │     ├── misrouted method                      → behaviour follows the method's intended shape, not the envelope's:
   │     │       ├── request method sent without id     → `InvalidRequest` error with `id: null` per §5
   │     │       └── notification method sent with id   → drop silently per §4.1 (WARN-log only)
   │     ├── parse error on notification shape          → drop silently per §4.1
   │     └── parse error on request shape               → send an `InvalidRequest` error response
   │
   └── parse succeeded
         │
         ├── JsonRpcRequest      → dispatchRequest()
         │     ├── $gate->allowsRequest($method)?
         │     ├── sync (pre-coroutine): on initialize, $gate->markInitializeInFlight()
         │     ├── spawn async coroutine → resolve handler → emit response or error
         │     └── on initialize success in coroutine, $gate->markInitializeCompleted()
         │
         └── JsonRpcNotification → dispatchNotification()
               ├── initialized notification: $gate->markInitialized()
               ├── other notifications: dropped if uninitialized, dispatched otherwise
               └── spawn async coroutine → call handler (no response)
```

Two pieces are worth calling out:

### `InitializationGate`

Holds the lifecycle phase (`AwaitingInitialize`, `InitializeInFlight`, `InitializeCompleted`,
`Initialized`) plus a one-bit `pendingInitializedNotification` flag. `InitializeInFlight` is set
synchronously when the `initialize` request is accepted (before the handler runs). When the handler
returns successfully, the coroutine calls `markInitializeCompleted()`, which folds the buffered
notification flag into the transition: if `notifications/initialized` arrived while the handler was
still running, the gate jumps `InitializeInFlight` -> `Initialized` directly; otherwise it transitions
to `InitializeCompleted` to wait for the notification. `markInitialized()` accepts both an in-flight
arrival (buffers it) and a post-completion arrival (flips to `Initialized`); the buffer flag is cleared
on `revertInitializeInFlight()` so a retry handshake starts fresh.
Consulted on every request to enforce the spec's "no other method may be invoked before `initialize`
completes" rule. It also rejects a second `initialize` once one is in flight, and silently drops
`notifications/initialized` envelopes that arrive outside a valid handshake (either no handshake yet,
or one already completed, or a duplicate during the buffered window).

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

## Diagnostic message conventions

Every `Assert::that(...)` chain and bare `ExpectationFailedException` in `Core/Schema/` follows a fixed
shape so consumers can parse messages programmatically and non-PHP clients can recognise the structure.

### Field labels

Each message identifies its target with the JSON field name in double quotes, optionally scoped by a
parent key:

- **Top-level request, result, and notification fields** use a dotted path from the JSON-RPC envelope
  key:

  ```text
  '"params.name" must be a string, {type} given.'
  '"result.completion.values" must be a list, non-list array given.'
  '"params._meta" must be an object, {type} given.'
  ```

- **Schema classes with a single canonical wrapping field** use that field as the label:

  | Class                                                                                          | Label                  |
  |------------------------------------------------------------------------------------------------|------------------------|
  | `ServerCapabilities`, `ClientCapabilities`                                                     | `"capabilities"`       |
  | `Annotations`, `ToolAnnotations`                                                               | `"annotations"`        |
  | `Icon` (array item under `icons`)                                                              | `"icons"`              |
  | `PromptArgument` (array item under `arguments`)                                                | `"arguments"`          |
  | `ModelPreferences`                                                                             | `"modelPreferences"`   |
  | `ModelHint` (array item under `hints`)                                                         | `"hints"`              |
  | `ToolChoice`                                                                                   | `"toolChoice"`         |
  | `ToolUseContent`, `ToolResultContent` (shared)                                                 | `"content"`            |
  | `MetaObject`, `RequestMetaObject`                                                              | `"_meta"`              |
  | `RequestId`                                                                                    | `"id"`                 |
  | `ProtocolVersion`                                                                              | `"protocolVersion"`    |
  | `Cursor`                                                                                       | `"cursor"`             |
  | `ElicitRequestedSchema`                                                                        | `"requestedSchema"`    |
  | `EnumOption` (array item under `oneOf`)                                                        | `"oneOf"`              |

- **Multi-context classes** (e.g. `Implementation`, referenced under both `serverInfo` and `clientInfo`)
  drop the prefix entirely; messages start with the field name directly:

  ```text
  '"name" must be a string, {type} given.'
  ```

- **Classes without a fixed wrapping field** use the lowercased space-separated form of their class
  name as the prefix: `text content`, `image content`, `embedded resource`, `resource link`,
  `boolean schema`, `number schema`, `tool`, `prompt`, `resource template`, `prompt message`,
  `sampling message`, `error response`, et cetera.

- **`*Request` and `*Notification` classes have no label.** Their messages start with the field name
  directly:

  ```text
  '"id" must be int or string, {type} given.'
  'missing the required "params" key.'
  ```

### Rules

1. JSON field names are double-quoted (`"name"`, `"capabilities.tasks.cancel"`).
2. `Assert::that(...)->values()` and `->keys()` chains prepend `each` to the message.
3. Type mismatches use the PHP idiom `<type> given.` (`int given.`, `array given.`).
4. Required-key checks read `'missing the required "X" key.'` with no parent scope.
5. Value mismatches against a constant use Assert's lazy `{value}` and `{other}` template tokens
   instead of `\sprintf`, so the comparand renders via `var_export` at exception-render time.
6. Bare `new ExpectationFailedException($template, $context)` constructions pre-`var_export` value
   tokens in the context array to match Assert's auto-rendering. Example from
   `MessageDiscriminator::unknownType`:

   ```php
   return new ExpectationFailedException(
       '{context} "type" must be one of "{allowed}", {value} given.',
       [
           'context' => $context,
           'allowed' => implode('", "', $allowedTypes),
           'value' => var_export($given, true),
       ],
   );
   ```

### Reusable validators

`Core/Validation/` exposes four field-format validators. Each takes the value plus a `$context` label
that becomes the message prefix:

| Validator                                                  | Purpose                                  |
|------------------------------------------------------------|------------------------------------------|
| `IdentifierNameValidator::validate($name, $context)`       | 1-128 chars from `[A-Za-z0-9._-]`        |
| `Rfc3986UriValidator::validate($uri, $context)`            | RFC 3986 absolute URI                    |
| `Rfc6570UriTemplateValidator::validate($uri, $context)`    | RFC 6570 URI Template                    |
| `Iso8601DateTimeValidator::parse($value, $context)`        | ISO 8601 datetime parse                  |

The validator templates have no hardcoded field noun. Callers pass the full label they want in the
emitted message (e.g. `'"params.name"'`, `'tool "name"'`, `'resource link "uri"'`,
`'resource template "uriTemplate"'`).

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
