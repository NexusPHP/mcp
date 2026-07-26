# Copilot Instructions

## Project Overview

This is a PHP monorepo implementing an SDK for the [Model Context Protocol (MCP)](https://modelcontextprotocol.io).
It is intentionally architected differently from the official PHP MCP SDK.

## Monorepo Structure

The repository is a single Composer monorepo with three logical namespaces under `src/`:

- `src/Core/`: shared foundation. JSON-RPC 2.0 types, MCP schema classes, and reusable utilities used by both server and client packages.
- `src/Server/`: MCP server implementation. Handles tool/resource/prompt registration and responds to client requests.
- `src/Client/`: MCP client implementation. Connects to MCP servers, calls tools, reads resources, and gets prompts.

All code is managed under the unified namespace `Nexus\Mcp\` with the directory structure mirroring the namespace hierarchy. Tests mirror the source structure under `tests/` with namespace `Nexus\Mcp\Tests\`. Development tooling is isolated in a separate `tools/` directory with its own dependencies.

## Tooling

### Commands

```bash
# Install all dependencies (run from repo root)
composer update  # composer.lock is not committed; update is the standard setup command

# Run the gate suite (code style, static analysis, automatic review, coverage, diff-based mutation)
composer test:with-untracked

# Same suite with full-tree mutation instead. 7+ minutes, so run it deliberately, not by default
composer test:all

# Run automatic code review tests (conformance tests and architecture checks)
composer test:auto-review

# Run unit tests with code coverage
composer test:unit   # runs all unit tests
composer test:client # only client tests
composer test:core   # only core tests
composer test:server # only server tests

# Enforce 100% line coverage (parses the Clover report emitted by test:unit)
composer coverage:check

# Run a single test file or test method
./vendor/bin/phpunit tests/Core/SomeTest.php
./vendor/bin/phpunit --filter testMethodName

# Static analysis
composer phpstan:check    # runs PHPStan across all packages
composer phpstan:baseline # regenerates the PHPStan baseline; only use when a confirmed false positive/negative requires suppression; never add baseline entries to silence real errors
composer test:stan        # PHPStan type-inference lock-in assertions (the static-analysis PHPUnit group, data under tests/AutoReview/data/)

# Architecture boundaries (Server and Client must not depend on each other, both may depend on Core)
composer arch:check

# Dependency declarations (shadow/unused composer deps via shipmonk/composer-dependency-analyser)
composer deps:check

# Mutation testing (checks for code quality via mutation detection)
composer mutation:check      # runs Infection on whole codebase
composer mutation:filter     # runs Infection on diff vs origin/1.x; includes untracked files via intent-to-add

# Code style (check only)
composer cs:check

# Code style (fix)
composer cs:fix

# Documentation linters (typos whole-repo + markdownlint scoped to .md)
composer lint:docs
composer lint:fix         # auto-fix typos + markdownlint

# Regenerate the schema snapshots (latest-schema.json + sorted-schema.json)
composer schema:generate

# Regenerate the spec @see anchor snapshot consumed by the auto-review test
composer spec:snapshot-anchors
```

### Key Tools

- **PHPUnit**: test framework
- **PHPStan level 10**: static analysis (strict)
- **PHP-CS-Fixer + Nexus CS Config**: code style enforcement
- **Minimum PHP version: 8.4**

## Architecture Conventions

### Core Package

The `Core/` subdirectory owns all MCP protocol types. These are modeled as immutable readonly classes or enums under `Nexus\Mcp\Core\`.
No server or client logic belongs here: only types, interfaces, and JSON-RPC primitives.
It can also provide abstract classes or traits for shared logic, but it should not have any concrete implementations of protocol handling.
All protocol envelope contracts (request/response/notification payload schemas) must be defined in `Core` even if one side typically originates them.

MCP schema types map directly to the [MCP specification](https://modelcontextprotocol.io/specification). When the spec defines an object, it becomes a readonly PHP class. Enums in the spec map to PHP backed enums.

### Server Package

The `Server/` subdirectory under `Nexus\Mcp\Server\` depends on `Core`. It defines:

- Handler interfaces that consumers implement (e.g., `ToolHandlerInterface`)
- A `Server` class that connects transports to protocol handling
- Transport implementations (stdio, Streamable HTTP)

### Client Package

The `Client/` subdirectory under `Nexus\Mcp\Client\` depends on `Core`. It provides a `Client` class that connects over a transport and exposes typed methods for each MCP capability.

### Transport Layer

Transports are abstracted behind an interface defined in `Core`. Both `Server` and `Client` depend on this interface. Concrete transport implementations live in the package that uses them.

### JSON-RPC

All MCP communication is JSON-RPC 2.0. Request/response/notification types live in `Core`. The `Server` and `Client` packages build on these primitives and should not define their own JSON-RPC envelope types.

If a type is implementation-internal and never appears in a JSON-RPC envelope, it may live in `Server` or `Client`. Once it becomes a protocol contract, it belongs in `Core`.

## Conformance Testing

This SDK must pass the official [MCP conformance test suite](https://github.com/modelcontextprotocol/modelcontextprotocol) maintained by the MCP project. Conformance tests are the authoritative check that the implementation adheres to the protocol specification. All new server and client functionality must remain conformance-compliant. If a conformance test fails, the implementation is wrong, not the test.

Conformance tests live in `tests/AutoReview/`, which is also the home for all other automatic review tests (e.g., architecture checks, coding standard enforcement at the test level).

## Code Style

- Strict types declared in every file: `declare(strict_types=1);`
- Namespace root: `Nexus\Mcp\` with subnamespaces for `Core`, `Server`, and `Client` (e.g., `Nexus\Mcp\Core\`, `Nexus\Mcp\Server\`, `Nexus\Mcp\Client\`)
- Readonly classes for value objects and protocol types
- No public mutable properties. Use constructor promotion with `readonly`
- PHPStan at max level. All code must pass without `@phpstan-ignore`. Test code can use `@phpstan-ignore` if necessary, but production code should not.
- Code style should use the `Nexus84` preset from Nexus CS Config. Use that library to construct the config for PHP-CS-Fixer.
- Classes should be final by default unless they are designed for extension (e.g., abstract classes or interfaces).
- Properties, parameters, and return types should be fully typed. Use `mixed` only when absolutely necessary, and prefer union types or generics (via docblocks) to express complex types.
- Use constructor injection for dependencies. Avoid service locators or static access to shared services.
- All exceptions must extend a package-specific base exception class. This allows consumers to catch all SDK errors with a single catch block.
- Mark implementation details with `@internal` so consumers know what is part of the public API vs internal to the SDK.
