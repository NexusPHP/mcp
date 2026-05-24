# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
aims to follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html) from `1.0.0` onward. While
in `0.x`, minor releases may include breaking changes.

## [Unreleased](https://github.com/NexusPHP/mcp-sdk/commits/1.x)

### Added

- `Client::getServerCapabilities()` returns the `ServerCapabilities` negotiated during the handshake, or
  `null` before it completes.
- The client now gates typed requests on the server's advertised capabilities: calling a method whose
  capability the server did not advertise (e.g. `tools/list` without a `tools` capability) throws
  `ServerCapabilityNotSupportedException` before the request reaches the transport. `ping` is never gated.

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
