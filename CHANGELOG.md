# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
aims to follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html) from `1.0.0` onward. While
in `0.x`, minor releases may include breaking changes.

## [Unreleased](https://github.com/NexusPHP/mcp-sdk/commits/1.x)

### Added

- Attribute-based registration. Mark methods with `#[AsTool]`, `#[AsPrompt]`, `#[AsResource]`, or
  `#[AsResourceTemplate]`, and a class with `#[AsServer]`, then register the object via
  `ServerBuilder::register(object ...$sources)`. A tool's `inputSchema` and a prompt's `arguments` are
  inferred from the method signature and `@param` docblocks (overridable per parameter with
  `#[InputSchema]`). A `ServerContext` parameter is injected, and a plain return value (string, content
  block, or schema object) is adapted to the matching result.
- `#[AsServer]` supplies the server identity and instructions. An explicit `setServerInfo()` /
  `setInstructions()` call wins per field and the attribute fills only the gaps, regardless of call order.
  More than one `#[AsServer]` across registered sources throws `DuplicateServerMetadataException`.
- A variadic parameter on an `#[AsTool]` method maps to an array input and is spread back into the call. The
  same parameter on a prompt, resource, or resource template throws `UnsupportedVariadicParameterException`.
- `ServerBuilder::register()` rejects a source that carries no `#[AsServer]` and no attribute-marked method
  with `MissingDiscoveryAttributeException`, catching typo'd attribute names and objects passed in by mistake.
- An `#[AsTool]` parameter typed as an instantiable class is expanded into an object input schema built from
  the class constructor, and the handler receives a constructed instance. Expansion is one level deep: a
  nested object, a list of objects, an interface, or an abstract class is not expanded and throws.
- `ServerBuilder::setToolStore()`, `setPromptStore()`, `setResourceStore()`, and `setResourceTemplateStore()`
  swap in a custom store implementation, replacing the in-memory one built from the matching `addTool()` /
  `addPrompt()` / `addResource()` / `addResourceTemplate()` entries. These mirror the existing
  `setCompletionStore()`.

### Changed

- Mutation testing no longer times out while covering the shutdown coroutine drain.
- Public accessors now follow a verb-first naming scheme: `Request`/`Notification::getMethod()` (was
  `method()`), the protocol exceptions' `getErrorCode()` (was `errorCode()`),
  `TransportInterface::getSessionId()` (was `sessionId()`), `BaseMetadata`/`Tool::getDisplayName()` (was
  `displayName()`), and `InMemoryTransport::createPair()` (was `pair()`).

### Removed

- The static `Server::builder()` / `Client::builder()` factories. Construct the builders directly with
  `new ServerBuilder()` / `new ClientBuilder()`.

## [v0.3.0](https://github.com/NexusPHP/mcp-sdk/compare/v0.2.0...v0.3.0) - 2026-05-25

### Added

- `Client::getServerCapabilities()` returns the `ServerCapabilities` negotiated during the handshake, or
  `null` before it completes.
- The client now gates typed requests on the server's advertised capabilities: calling a method whose
  capability the server did not advertise (e.g. `tools/list` without a `tools` capability) throws
  `ServerCapabilityNotSupportedException` before the request reaches the transport. `ping` is never gated.

### Fixed

- Closing a `StdioClientTransport` whose subprocess is still running no longer fatals on PHP builds
  without the `pcntl` extension (such as Windows). The transport now terminates the subprocess via
  `Process::kill()` instead of the `SIGKILL` constant.

### Changed

- `StdioClientTransport` now prunes the spawned subprocess environment by default instead of inheriting the
  full parent environment. The `env` constructor argument changed from `array $env = []` to
  `?array $env = null`: `null` (default) passes a safe allowlist (`PATH`, `HOME`, `TERM`, …) drawn from the
  parent and skips exported shell-function values. An empty array still inherits the full parent
  environment. A non-empty array is passed verbatim.

## [v0.2.0](https://github.com/NexusPHP/mcp-sdk/compare/v0.1.0...v0.2.0) - 2026-05-24

### Fixed

- Sampling requests (`sampling/createMessage`) no longer reject a `temperature` value outside `0.0` to
  `2.0`. The MCP schema sets no bound on the field, so the previous range rejected spec-valid input.
- Sampling requests (`sampling/createMessage`) no longer reject a negative `maxTokens`. The MCP schema
  sets no minimum on the field.
- A request envelope with a malformed `id` (wrong type or empty string) now returns a `-32600` Invalid
  Request error instead of `-32602` Invalid Params, matching JSON-RPC 2.0.
- Shutdown now drains handler coroutines spawned while an earlier batch of in-flight work is
  still being awaited, instead of returning before they complete.

### Removed

- `HandlerRegistry::methods()`, an unused accessor returning the registered method names.

## [v0.1.0](https://github.com/NexusPHP/mcp-sdk/releases/tag/v0.1.0) - 2026-05-23

### Added

- MCP server runtime: `ServerBuilder` plus `Server`, with tools, prompts, resources (static and RFC
  6570 templated), completions, logging, and ping handlers against MCP spec 2025-11-25.
- MCP client runtime: `ClientBuilder` plus `Client`, with the `initialize` handshake, typed request
  methods (`listTools`, `listResources`, `listResourceTemplates`, `listPrompts`, `readResource`,
  `getPrompt`, `complete`, `callTool` with streaming progress, `ping`, `setLoggingLevel`), and the
  `sendRequest` escape hatch.
- Tool I/O JSON Schema validation: `tools/call` arguments are checked against the tool's `inputSchema`
  and a result's `structuredContent` against its `outputSchema`, backed by `opis/json-schema` and
  pluggable via `SchemaValidatorInterface` / `ServerBuilder::setSchemaValidator()`.
- A tool returning `structuredContent` with no content blocks gets a serialised-JSON `TextContent`
  mirror for backwards compatibility.
- Stdio transport for both server and client, plus an in-memory paired transport for tests.
- JSON-RPC 2.0 envelope and MCP schema types under `Nexus\Mcp\Core`.
- Every SDK exception implements `McpExceptionInterface`, so consumers can catch all SDK errors in one
  block.
