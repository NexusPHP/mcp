# Feature index

Every MCP feature the SDK ships, mapped to where it is documented. The rows follow the canonical feature
list the [MCP conformance repository](https://github.com/modelcontextprotocol/conformance) audits SDK
documentation against. The SDK tracks spec revision 2026-07-28, which removed or deprecated several
features on that list. Those rows say so explicitly and name the replacement.

## Core features

| # | Feature | Where |
| --- | --- | --- |
| 1 | Tools: listing | [Server tools](server/tools.md), [client `listTools()`](client/requests.md#typed-requests) |
| 2 | Tools: calling | [Server tools](server/tools.md), [client `callTool()`](client/requests.md#typed-requests) |
| 3 | Tools: text results | [Result content types](server/tools.md#result-content-types) |
| 4 | Tools: image results | [Result content types](server/tools.md#result-content-types) |
| 5 | Tools: audio results | [Result content types](server/tools.md#result-content-types) |
| 6 | Tools: embedded resources | [Result content types](server/tools.md#result-content-types) |
| 7 | Tools: error handling | [Server tools](server/tools.md), [error handling](error-handling.md) |
| 8 | Tools: change notifications | [Subscriptions](server/subscriptions.md), [client subscriptions](client/subscriptions.md) |
| 9 | Resources: listing | [Server resources](server/resources.md), [client `listResources()`](client/requests.md#typed-requests) |
| 10 | Resources: reading text | [Server resources](server/resources.md), [client `readResource()`](client/requests.md#typed-requests) |
| 11 | Resources: reading binary | [Server resources](server/resources.md) |
| 12 | Resources: templates | [Server resources](server/resources.md) |
| 13 | Resources: template reading | [Server resources](server/resources.md) |
| 14 | Resources: subscribing | Removed by the 2026-07-28 revision (SEP-2575). The filter-based [`subscriptions/listen`](server/subscriptions.md) replaces `resources/subscribe`. |
| 15 | Resources: unsubscribing | Removed with row 14. A client [closes the stream](client/subscriptions.md) instead. |
| 16 | Resources: change notifications | [Subscriptions](server/subscriptions.md), including `notifications/resources/updated` via `emitResourceUpdated()` |
| 17 | Prompts: listing | [Server prompts](server/prompts.md), [client `listPrompts()`](client/requests.md#typed-requests) |
| 18 | Prompts: getting simple | [Server prompts](server/prompts.md), [client `getPrompt()`](client/requests.md#typed-requests) |
| 19 | Prompts: getting with arguments | [Server prompts](server/prompts.md), [attribute discovery](attribute-discovery.md) |
| 20 | Prompts: embedded resources | [Message content types](server/prompts.md#message-content-types) |
| 21 | Prompts: image content | [Message content types](server/prompts.md#message-content-types) |
| 22 | Prompts: change notifications | [Subscriptions](server/subscriptions.md) |
| 23 | Sampling | Not implemented: deprecated by the 2026-07-28 revision (SEP-2577). See [deliberate non-features](design-rationale.md#deliberate-non-features). |
| 24 | Elicitation: form mode | [Asking the client for input](server/input-required.md), [answering it](client/input-required.md) |
| 25 | Elicitation: URL mode | [URL mode](server/input-required.md#url-mode) |
| 26 | Elicitation: schema validation | [The field vocabulary](server/input-required.md#the-field-vocabulary) |
| 27 | Elicitation: default values | [The field vocabulary](server/input-required.md#the-field-vocabulary) |
| 28 | Elicitation: enum values | [The field vocabulary](server/input-required.md#the-field-vocabulary) |
| 29 | Elicitation: complete notification | Not implemented: `notifications/elicitation/complete` was removed from the 2026-07-28 revision before its final tag and is absent from its schema. |
| 30 | Roots: listing | Not implemented: deprecated by SEP-2577. See [deliberate non-features](design-rationale.md#deliberate-non-features). |
| 31 | Roots: change notifications | Not implemented, with row 30. |
| 32 | Logging: log messages | Not implemented: `notifications/message` is deprecated by SEP-2577. The SDK logs to [PSR-3](server/configuration.md#logger) instead. |
| 33 | Logging: setting level | Not implemented: `logging/setLevel` was removed outright by SEP-2575. Its per-request replacement, the `io.modelcontextprotocol/logLevel` `_meta` field, is parsed and [exposed to handlers](server/context.md). |
| 34 | Completions: resource argument | [Completions](server/completions.md) |
| 35 | Completions: prompt argument | [Completions](server/completions.md) |
| 36 | Ping | Not implemented: removed by SEP-2575 with the session it kept alive. The protocol is stateless. |

## Transports

| # | Feature | Where |
| --- | --- | --- |
| 37 | Streamable HTTP transport (client) | [`StreamableHttpClientTransport`](transports.md#streamablehttpclienttransport) |
| 38 | Streamable HTTP transport (server) | [`StreamableHttpServerTransport`](transports.md#streamablehttpservertransport) |
| 39 | SSE transport, legacy (client) | Not implemented: the HTTP+SSE transport was deprecated in 2025-03-26 and is absent from the targeted revision. |
| 40 | SSE transport, legacy (server) | Not implemented, with row 39. |
| 41 | stdio transport (client) | [`StdioClientTransport`](transports.md#stdioclienttransport), plus [`SupervisedTransport`](transports.md#supervisedtransport) for restart supervision |
| 42 | stdio transport (server) | [`StdioServerTransport`](transports.md#stdioservertransport) |

## Protocol features

| # | Feature | Where |
| --- | --- | --- |
| 43 | Progress notifications | [Reporting progress](server/context.md#reporting-progress), [streaming progress](client/progress-and-timeouts.md#streaming-progress-from-calltool) |
| 44 | Cancellation | [Server-side cancellation](server/context.md#cancellation), [request timeouts](client/progress-and-timeouts.md#request-timeouts) |
| 45 | Pagination | [Pagination](server/stores.md#pagination), cursors on every client `list*()` call ([typed requests](client/requests.md#typed-requests)) |
| 46 | Capability negotiation | [Capability advertisement](server/capabilities.md), [client capabilities](client/configuration.md#client-capabilities) |
| 47 | Protocol version negotiation | The per-request `_meta` stamp and the `-32022` retry, on the [Client API](client.md) page |
| 48 | JSON Schema 2020-12 | [Schema validation](server/tools.md#schema-validation), [input schemas](attribute-discovery.md#input-schemas-and-arguments) |

## Extensions

The SEP-2133 extensions framework is implemented on both sides: [server](server/extensions.md) and
[client](client/extensions.md) builders take `enableExtension(...)`, advertising the capability and
serving the extension's methods behind the declared-capability gate.

Every official extension ratified for this spec revision is implemented on that framework, each one
covering both halves of a session.

| Extension | What it does | Where |
| --- | --- | --- |
| Tasks (`io.modelcontextprotocol/tasks`, SEP-2663) | Runs a long tool call as a task the client polls instead of holding the request open. The server brokers `tools/call` and serves `tasks/get`, `tasks/update`, and `tasks/cancel`. The client calls tools as tasks and polls them to completion. | [server](server/tasks.md), [client](client/tasks.md) |
| MCP Apps (`io.modelcontextprotocol/ui`, SEP-1865) | Links a tool to a `ui://` view a host renders. The server declares typed `_meta.ui` metadata through the guarded `UiResource`. The client advertises the renderable `mimeTypes` and reads that metadata back through `AppClient`. | [server](server/apps.md), [client](client/apps.md) |
| OAuth client credentials (`io.modelcontextprotocol/oauth-client-credentials`, SEP-1046) | Authenticates a machine client with a secret or a signed JWT assertion, as an unattended grant strategy for `AuthorizedHttpClient`. | [client](client/auth-extensions.md) |
| Enterprise-managed authorization (`io.modelcontextprotocol/enterprise-managed-authorization`, SEP-990) | Turns an enterprise sign-on into MCP access through an ID-JAG, so admin policy governs the grant and the user is never redirected. | [client](client/auth-extensions.md) |

The `notifications/tasks` follow-up, and the extensions still in proposal upstream (DPoP,
workload identity federation), are tracked in [ROADMAP.md](../ROADMAP.md) under Official
extensions.
