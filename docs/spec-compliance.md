# Spec compliance

What the SDK covers against the MCP specification, and what it deliberately omits. The short version: one
revision, the whole revision, and none of its deprecated behaviours.

## The targeted revision

The SDK targets **MCP spec 2026-07-28** only. There are no back-compat shims for earlier revisions: no JSON-RPC
batching, and no protocol-version negotiation against older drafts.

## How conformance is enforced

Auto-review tests (`tests/AutoReview/`) verify three things. Every PHP class with a spec counterpart matches the
canonical description. Every `@see` link resolves to a real anchor in the spec docs. Every round-trip fixture
matches the canonical envelope shape.

## What ships

### Server side

The server handlers cover `server/discover`, tools, prompts, resources (static and templated), and completions.

### Client side

The client covers `discover()` plus typed requests for the same surface: `tools/call` with streaming progress, and
the list, read, get, and complete methods.

### Transports and authorization

Both transports ship on both sides: stdio, and Streamable HTTP with its PSR-15 security stack. OAuth 2.1 ships on
both sides too. The client authorizes and re-authorizes itself. The server validates bearer tokens and publishes
its protected resource metadata.

### Attribute discovery

`#[AsTool]` and friends declare features on a plain object.

### The input-required flow

Both halves ship. A client recognises an `InputRequiredResult`, collects what it asks for, and answers by calling
again with `inputResponses` and the `requestState` it carried. A tool, prompt, or resource handler may return one
to ask, and reads the answers back off `ServerContext`.

### Schema validation

Tool-call arguments and results are validated against the tool's declared `inputSchema` and `outputSchema`. The
validator is pluggable through `SchemaValidatorInterface`. A `structuredContent`-only result is mirrored into a
`TextContent` block for backwards compatibility.

### Official extensions

Every official extension ratified for the targeted revision ships, each opt-in: Tasks, MCP Apps, and the two OAuth
extensions. See [official extensions](server/extensions.md#official-extensions).

## What is deliberately omitted

Sampling, roots, and logging. SEP-2577 deprecated them, and the spec tells new implementations not to adopt a
deprecated feature, so a greenfield SDK carries none of them.

The schema shape is still mirrored faithfully. A slot the 2026-07-28 schema itself keeps, such as the deprecated
`logging` capability on `ServerCapabilities`, is modelled so a peer's capabilities decode. Nothing in the SDK acts
on it.

One consequence reaches the input-required flow. The spec's `InputRequest` union is
`CreateMessageRequest | ListRootsRequest | ElicitRequest`, and only the last is undeprecated. A server built on
this SDK can therefore ask for elicitation and nothing else. The two deprecated members are not modelled, not even
as payload types that never travel as dispatchable methods, since emitting one is adopting the feature.

Three more features from earlier revisions are absent because the targeted revision itself dropped them, so there
is nothing to omit:

| Feature | Status in 2026-07-28 | What replaces it |
| --- | --- | --- |
| `ping` | Removed by SEP-2575 with the session it kept alive. | Nothing. The protocol is stateless. |
| `logging/setLevel` | Removed by SEP-2575. | The per-request `io.modelcontextprotocol/logLevel` `_meta` field, exposed to handlers through [`ServerContext`](server/context.md). |
| `notifications/elicitation/complete` | Removed before the revision was tagged and absent from its schema. | The [input-required flow](server/input-required.md) completes through the retry itself. |
| HTTP+SSE transport | Deprecated in 2025-03-26 and absent from the targeted revision. | [Streamable HTTP](transports/streamable-http.md). |

## See also

- **[Architecture](architecture.md)**: the namespace layout and the dispatch kernel.
- **[Design rationale](design-rationale.md)**: why strictness beats convenience here.
- **[Error handling](error-handling.md)**: the error codes and the diagnostic message grammar.
