# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
aims to follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html) from `1.0.0` onward. While
in `0.x`, minor releases may include breaking changes.

## [Unreleased]

### Added

- MCP server runtime: `ServerBuilder` plus `Server`, with tools, prompts, resources (static and RFC
  6570 templated), completions, logging, and ping handlers against MCP spec 2025-11-25.
- MCP client runtime: `ClientBuilder` plus `Client`, with the `initialize` handshake, typed request
  methods (`listTools`, `listResources`, `listResourceTemplates`, `listPrompts`, `readResource`,
  `getPrompt`, `complete`, `callTool` with streaming progress, `ping`, `setLoggingLevel`), and the
  `sendRequest` escape hatch.
- Stdio transport for both server and client, plus an in-memory paired transport for tests.
- JSON-RPC 2.0 envelope and MCP schema types under `Nexus\Mcp\Core`.

[Unreleased]: https://github.com/NexusPHP/mcp-sdk/commits/1.x
