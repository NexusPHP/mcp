# Architecture

This document covers how the SDK is laid out, the layering rules between namespaces, the dispatch kernel
that drives every inbound JSON-RPC message, and what the SDK does and does not cover against the spec.

## Namespaces

```text
Nexus\Mcp\
├── Core\               Protocol primitives shared by both peers. Depends on no other Mcp namespace
│   ├── Auth\           Authorization vocabulary both peers read: metadata documents, verified tokens, resource identifiers
│   ├── Dispatch\       Shared dispatch contract and in-flight correlation primitives
│   ├── Exception\      McpExceptionInterface marker plus concrete protocol error types
│   ├── Handler\        Handler interfaces, the method-to-handler registry, and the abstract context base
│   │   └── Notification\  Built-in notification handlers both peers register (cancellation)
│   ├── Http\           HTTP vocabulary shared by both sides: status codes, header codecs, Mcp-Param binding and validation
│   ├── JsonRpc\        Envelope parser, method registry, parser-state value objects
│   ├── Schema\         Types only (value objects, enums, interfaces). No behaviour
│   ├── Transport\      Transport contract, lifecycle event keys, shared line-framed duplex, in-memory paired transports for tests
│   ├── UriTemplate\    RFC 6570 expansion plus matching
│   └── Validation\     URI templates, RFC 3339, enum-value coercion
├── Server\             Server-side composition. Depends on Core only
│   ├── Attribute\      #[AsTool], #[AsPrompt], #[AsResource], #[AsResourceTemplate], #[AsServer]
│   ├── Auth\           Resource-server side: the access-token validator contract
│   ├── Completion\     Completion store contract
│   ├── Discovery\      Attribute scanner behind ServerBuilder::register()
│   ├── Dispatch\       Server-side per-envelope inbound pipeline
│   ├── Exception\      Server-side error types
│   ├── Handler\
│   │   └── Request\    Built-in server request handlers
│   ├── Prompt\         Prompt store plus renderer adapters
│   ├── Resource\       Static and templated resource stores plus reader adapters
│   ├── Subscription\   Open subscriptions/listen streams and the fanout that reaches them
│   ├── Tool\           Tool store plus executor adapters
│   ├── Transport\      Server-side transport implementations
│   │   └── Http\       Streamable HTTP endpoint composition
│   │       └── Middleware\  PSR-15 middleware: CORS, DNS rebinding, bearer auth, parameter headers, body size
│   └── Validation\     Pluggable JSON Schema validator contract plus the opis-backed default
└── Client\             Client-side composition. Depends on Core only
    ├── Auth\           OAuth 2.1 client: metadata discovery, registration, token endpoint, the authorizing HTTP decorator
    ├── Dispatch\       Client-side per-envelope inbound pipeline
    ├── Exception\      Client-side local-misuse errors (not connected, already connected, unadvertised server capability)
    ├── Handler\
    │   └── Notification\  Built-in client notification handlers (progress routing)
    ├── Subscription\   Open `subscriptions/listen` streams, their notification routing, and their replay across a reconnect
    └── Transport\      Client-side transport implementations
```

### Layering rules

- **`Core` depends on no other `Nexus\Mcp` namespace.** It is the shared vocabulary both server-side and
  client-side consume.
- **`Core/Schema/` is types only.** No parsers, no codecs, no registries. Behaviour for those lives in
  sibling namespaces (`Core/JsonRpc/`, `Core/Validation/`, `Core/UriTemplate/`). Abstract bases sit at the
  namespace root with concrete subclasses in same-named subfolders (`Schema/Result.php` +
  `Schema/Result/EmptyResult.php`, `Schema/Request.php` + `Schema/Request/DiscoverRequest.php`, etc.).
- **`Server` depends on `Core` only.** No back-references from `Core` into `Server`.
- **`Client` depends on `Core` only.** `Server` and `Client` are symmetric peers, neither depending on the
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

Which path a caller takes is not a free choice. Everything that encodes a message for a peer reads
`jsonSerialize()`, so the substitution survives to the other end. `toArray()` is the round-trip form, used
where the envelope stays a PHP array: the in-memory transport handing it to its peer, and `StandardHeaders`
reading values back out of it to mirror into request headers.

A success-response envelope carries no method-name, so the parser cannot pick its concrete type from the
envelope alone. `JsonRpcResultResponse` is therefore an abstract base with one self-decoding
`*ResultResponse` per method: the awaiter supplies the expected response class and the parser delegates to its `fromArray()`
(`JsonRpcMessageParser::parse(..., SomeResultResponse::class)`). Results with no dedicated envelope (e.g.
`EmptyResult`) ride the `@internal` `GenericResultResponse`.

## The dispatch kernel

Both peers run a per-envelope inbound pipeline behind the shared
[`MessageDispatcherInterface`](../src/Core/Dispatch/MessageDispatcherInterface.php):
[`ServerMessageDispatcher`](../src/Server/Dispatch/ServerMessageDispatcher.php) on the server,
[`ClientMessageDispatcher`](../src/Client/Dispatch/ClientMessageDispatcher.php) on the client. The two
share the same shape. The structural difference is which direction owns response correlation.

```text
inbound envelope (array)
   │
   ├── response shape (`result` or `error` key) → server: discard with a warning (it issues no outbound requests)
   │       → client: correlate to the matching pending outbound request (resolve / reject)
   │
   ▼
JsonRpcMessageParser::parse()        ← classifies request/notification, raises typed protocol exceptions
   │
   ├── parse failed                                → the envelope's `id` decides, never the method it names:
   │     ├── no `id`  (a notification per §4.1)         → drop, log, answer nothing
   │     └── has `id` (a request per §4.1)              → `InvalidRequest` error per §5, echoing that id
   │
   └── parse succeeded
         │
         ├── JsonRpcRequest      → dispatchRequest()
         │     ├── claim the request id (duplicate in-flight id → InvalidRequest error)
         │     └── spawn async coroutine → resolve handler → emit response or error
         │
         └── JsonRpcNotification → dispatchNotification()
               └── spawn async coroutine → call handler (no response)
```

The protocol is stateless: every inbound request dispatches immediately, carrying the client's identity and
capabilities in its `_meta`, which the server-side handler reads through `ServerContext::$meta`.

A misrouted method (a request method sent without an `id`, or a notification method sent with one) takes
that same fork. The method name never overrides the envelope: `tools/list` without an `id` is a
notification and goes unanswered, while `notifications/cancelled` with an `id` is a request and is answered
with an error echoing it.

On the answering side, MCP narrows JSON-RPC rather than following it. §5 mandates a null `id` when the
request id could not be recovered, but MCP types `RequestId` as `int | non-empty-string`, so
`JsonRpcErrorResponse` drops the key instead of emitting `"id": null`. Such a frame goes out as
`{"jsonrpc":"2.0","error":{…}}`, and a test pins that encoding.

The diagram traces the server. The client shares the request and notification arms but diverges in two
places. The first is the response-shape fork above: where the server discards a `result`/`error` envelope,
the client correlates it to the pending outbound request it is awaiting, resolving on success or rejecting
on error, and warns on an unknown ("orphan") id.

The second is the misrouted-method arm. The client never answers a misrouted envelope, whatever shape it
arrived in, because the spec gives it no reply to send: *"servers do not initiate JSON-RPC requests and
clients do not send JSON-RPC responses"*. The server's §5 obligation to answer an envelope carrying an id
therefore has no client-side counterpart, and the client drops with a warning instead. The client is thus both a responder (it routes
`notifications/progress` to per-call listeners) and a requester (it
awaits the responses to the calls it makes, gating each *outbound* send on the server's advertised
capabilities in `Client::sendRequest()`).

A few pieces are worth calling out:

### Coroutine draining

Each dispatched request and notification runs in its own `Amp\async` coroutine, tracked by the dispatcher.
When the transport fires its `onDrain` listeners (before close), the dispatcher awaits every still-running
coroutine so in-flight responses flush before the transport actually closes. Without this, a handler that
finishes right as the transport closes would lose its response to a race with the close listeners.

## Spec compliance

- Target: **MCP spec 2026-07-28** only. No back-compat shims for earlier revisions (no JSON-RPC batching, no
  protocol-version negotiation against older drafts).
- The canonical schema lives at `latest-schema.json` in the repo root. The class-to-schema map is in
  `sorted-schema.json`. Both are regenerated by `composer schema:generate`.
- Auto-review tests (`tests/AutoReview/`) verify every PHP class with a spec counterpart matches the
  canonical description, every `@see` link resolves to a real anchor in the spec docs, and every round-trip
  fixture matches the canonical envelope shape.
- What we have today: server-side handlers covering `server/discover`, tools, prompts, resources (static +
  templated), and completions. Client-side covering `discover()` plus typed requests for the same surface
  (`tools/call` with streaming progress, the list/read/get/complete methods). Both transports, stdio and
  Streamable HTTP, on both sides, the latter with its PSR-15 security stack. OAuth 2.1 on both sides: the
  client authorizes and re-authorizes itself, the server validates bearer tokens and publishes its protected
  resource metadata. Attribute discovery via `#[AsTool]` and friends. Both halves of the
  input-required flow: a client recognises an `InputRequiredResult`, collects what it asks for, and
  answers by calling again with `inputResponses` and the `requestState` it carried, while a tool, prompt
  or resource handler may return one to ask, reading the answers back off `ServerContext`. Tool call
  arguments and results are validated against the tool's declared `inputSchema` / `outputSchema` (pluggable
  via `SchemaValidatorInterface`), and a `structuredContent`-only result is mirrored into a `TextContent`
  block for backwards compatibility.
- What we do not have yet: tasks and MCP Apps.
- What we deliberately omit: sampling, roots, and logging. SEP-2596 deprecated them, and the spec tells new
  implementations not to adopt a deprecated feature, so a greenfield SDK carries none of them. One
  consequence reaches the input-required flow: the spec's `InputRequest` union is
  `CreateMessageRequest | ListRootsRequest | ElicitRequest`, and only the last is undeprecated, so a server
  built on this SDK can ask for elicitation and nothing else.

## Diagnostic message conventions

Every `Assert::that(...)` chain and bare `ExpectationFailedException` in `Core/Schema/` follows a fixed
shape so consumers can parse messages programmatically and non-PHP clients can recognise the structure.

### Field labels

Each message identifies its target with the JSON field name in double quotes, optionally scoped by a
parent key:

- **Top-level request, result, notification, and error-response fields** use a dotted path from the
  JSON-RPC envelope key:

  ```text
  '"params.name" must be a string, {type} given.'
  '"result.completion.values" must be a list, non-list array given.'
  '"params._meta" must be an object, {type} given.'
  '"error.code" must be an integer, {type} given.'
  ```

- **Schema classes with a single canonical wrapping field** use that field as the label:

  | Class                                                                                          | Label                  |
  |------------------------------------------------------------------------------------------------|------------------------|
  | `ServerCapabilities`, `ClientCapabilities`                                                     | `"capabilities"`       |
  | `Annotations`, `ToolAnnotations`                                                               | `"annotations"`        |
  | `Icon` (array item under `icons`)                                                              | `"icons"`              |
  | `PromptArgument` (array item under `arguments`)                                                | `"arguments"`          |
  | `MetaObject` and its `MetaObject\*` subclasses                                                 | `"_meta"`              |
  | `RequestId`                                                                                    | `"id"`                 |
  | `ProtocolVersion`                                                                              | `"protocolVersion"`    |
  | `Cursor`                                                                                       | `"cursor"`             |
  | `ElicitRequestedSchema`                                                                        | `"requestedSchema"`    |
  | `EnumOption` (array item under `oneOf`)                                                        | `"oneOf"`              |

- **Multi-context classes** (e.g. `Implementation`, referenced under both `serverInfo` and `clientInfo`)
  drop the prefix entirely. Messages start with the field name directly:

  ```text
  '"name" must be a string, {type} given.'
  ```

- **Classes without a fixed wrapping field** use the lowercased space-separated form of their class
  name as the prefix: `text content`, `image content`, `embedded resource`, `resource link`,
  `boolean schema`, `number schema`, `tool`, `prompt`, `resource template`, `prompt message`,
  et cetera.

- **`*Request` and `*Notification` classes have no label.** Their messages start with the field name
  directly:

  ```text
  '"id" must be an int or non-empty string, {type} given.'
  'missing the required "params" key.'
  ```

### Envelope-kind wrapper

The `JsonRpcMessageParser` prefixes every decode failure with one wrapper per envelope kind, so the
inner message never repeats it:

```text
Invalid success response: "result" is missing the required "content" key.
Invalid error response: "error.code" must be an integer, {type} given.
Invalid "tools/call" request: "params" is missing the required "name" key.
Invalid "notifications/progress" notification: "params" is missing the required "progressToken" key.
```

The four kinds (request, notification, success response, error response) are the only omitted top
scope. Everything below the envelope (`params`, `result`, the `error` object, nested objects) keeps its
scope in the inner message.

### Rules

1. JSON field names are double-quoted (`"name"`, `"capabilities.tasks.cancel"`).
2. `Assert::that(...)->values()` and `->keys()` chains prepend `each` to the message, kept singular to
   agree with it (`each "params.stopSequences" entry must be a string`, not `entries must be strings`).
3. Type mismatches use the PHP idiom `<type> given.` (`int given.`, `array given.`).
4. Required-key checks mirror the matching type-mismatch's scope, drop the envelope kind (the wrapper
   above supplies it), and read `is missing`. Envelope-root fields stay bare, e.g.
   `'missing the required "id" key.'`. Payload and deeper fields keep their scope, e.g.
   `'"params" is missing the required "name" key.'` and
   `'"error.data" is missing the required "elicitations" key.'`.
5. Value mismatches against a constant use Assert's lazy `{value}` and `{other}` template tokens
   instead of `\sprintf`, so the comparand renders via `var_export` at exception-render time.
6. Bare `new ExpectationFailedException($template, $context)` constructions pre-`var_export` value
   tokens in the context array to match Assert's auto-rendering. Example from
   `MessageDiscriminator::buildUnknownTypeError()`:

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
- **[Client API](client.md)**: client builder + typed request reference.
- **[Transports](transports.md)**: stdio contract. HTTP planning.
