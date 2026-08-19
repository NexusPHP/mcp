# Nexus MCP SDK documentation

Every page in one map, grouped by what you came to do: learn the SDK, get a task done, look something
up, or understand why it is built this way.

## Start here

- **[Getting started](getting-started.md)**: the tutorial. From an empty directory to a stdio server
  and a PHP client that calls it.

## How-to guides

### Server

- **[Server API overview](server.md)**: the builder, the lifecycle, and the guide to every feature page.
- **[Tools](server/tools.md)**: registering tools, structured content, and schema validation.
- **[Prompts](server/prompts.md)**: registering prompt renderers.
- **[Resources](server/resources.md)**: static and templated resources, and cache hints.
- **[Completions](server/completions.md)**: serving `completion/complete`.
- **[Stores and pagination](server/stores.md)**: page size, custom stores, runtime mutation.
- **[Custom handlers](server/handlers.md)**: vendor-extension methods and spec-method overrides.
- **[Extensions](server/extensions.md)**: enabling SEP-2133 extensions and the capability gate.
- **[Tasks](server/tasks.md)**: brokering tool calls into polled long-running tasks (SEP-2663).
- **[Apps](server/apps.md)**: declaring `ui://` views and linking tools to them (SEP-1865).
- **[Subscriptions](server/subscriptions.md)**: serving `subscriptions/listen` streams.
- **[Asking the client for input](server/input-required.md)**: the `InputRequiredResult` flow.
- **[Attribute discovery](attribute-discovery.md)**: declaring features with `#[AsTool]` and friends.

### Client

- **[Client API overview](client.md)**: the builder, the lifecycle, and the guide to every feature page.
- **[Connecting and discovery](client/connecting.md)**: attaching a transport and reading
  `server/discover`.
- **[Typed requests](client/requests.md)**: the per-method calls, mirrored tool parameters, and
  `sendRequest()`.
- **[When the server asks for input first](client/input-required.md)**: answering an
  `InputRequiredResult`.
- **[Progress and timeouts](client/progress-and-timeouts.md)**: streaming progress and request
  deadlines.
- **[Notification handlers](client/notifications.md)**: reacting to server notifications.
- **[Extensions](client/extensions.md)**: enabling SEP-2133 extensions and the outbound gate.
- **[Tasks](client/tasks.md)**: calling tools as tasks and polling them to completion (SEP-2663).
- **[Apps](client/apps.md)**: advertising renderable mime types and reading `_meta.ui` (SEP-1865).
- **[Subscriptions](client/subscriptions.md)**: opening `subscriptions/listen` streams.

### Authorization

- **[Authorization overview](authorization.md)**: what the SDK enforces and the OAuth error surface.
- **[Client authorization](auth/client.md)**: composing an authorized client and the user-agent leg.
- **[Resource server](auth/server.md)**: validating tokens and publishing the metadata document.
- **[Persisting tokens and registrations](auth/persistence.md)**: the store interfaces.
- **[Scopes and step-up](auth/scopes.md)**: scope selection and insufficient-scope retries.
- **[OAuth extension grants](auth/extension-grants.md)**: client credentials (SEP-1046) and
  enterprise-managed authorization (SEP-990).
- Provider recipes: **[Keycloak](auth/keycloak.md)**, **[Microsoft Entra ID](auth/entra.md)**,
  **[Auth0](auth/auth0.md)**, **[Okta](auth/okta.md)**.

## Reference

- **[Transports](transports.md)**: the transport contract and lifecycle, with one page per binding:
  **[stdio](transports/stdio.md)**, **[Streamable HTTP](transports/streamable-http.md)**,
  **[SupervisedTransport](transports/supervised.md)**, **[InMemoryTransport](transports/in-memory.md)**.
- **[Server configuration](server/configuration.md)**: everything `ServerBuilder` takes.
- **[Client configuration](client/configuration.md)**: everything `ClientBuilder` takes.
- **[Capability advertisement](server/capabilities.md)**: how `ServerCapabilities` is derived.
- **[ServerContext](server/context.md)**: what every handler receives.
- **[Error handling](error-handling.md)**: exception types, JSON-RPC error codes, and the diagnostic
  message grammar.
- **[API reference](https://nexusphp.github.io/mcp/)**: the generated class-level reference for the
  public `Nexus\Mcp\` API.

## Explanation

- **[Architecture](architecture.md)**: the namespace tree, layering rules, and the dispatch kernel.
- **[Spec compliance](spec-compliance.md)**: coverage against the targeted revision, and the deliberate
  omissions.
- **[Design rationale](design-rationale.md)**: the choices behind the SDK.
- **[Best practices](best-practices.md)**: conventions the SDK is shaped to reward.

## Runnable code

- **[examples/](../examples/)**: demo servers and clients over stdio, in-memory, and HTTP, including
  the OAuth and MCP Apps end-to-end setups.
