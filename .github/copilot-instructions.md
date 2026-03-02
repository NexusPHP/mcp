# Copilot Instructions

## Project Overview

This is a PHP monorepo implementing an SDK for the [Model Context Protocol (MCP)](https://modelcontextprotocol.io).
It is intentionally architected differently from the official PHP MCP SDK.

## Monorepo Structure

The repository is organized as a Composer monorepo with three distinct packages:

- `src/Core/` — Shared foundation: JSON-RPC 2.0 types, MCP schema classes, and reusable utilities used by both server and client packages.
- `src/Server/` — MCP server implementation: handling tool/resource/prompt registration and responding to client requests.
- `src/Client/` — MCP client implementation: connecting to MCP servers, calling tools, reading resources, and getting prompts.

Each package has its own `composer.json` but all packages are released at the same version. Releases are managed from the root repository; a subtree split script pushes each package directory to its own read-only mirror repository for Packagist. The root `composer.json` wires packages together via path repositories for local development.

## Tooling

### Commands

```bash
# Install all dependencies (run from repo root)
composer update  # composer.lock is not committed; update is the standard setup command

# Run all tests
composer test:all

# Run tests for a single package
./vendor/bin/phpunit tests/Core
./vendor/bin/phpunit tests/Server
./vendor/bin/phpunit tests/Client

# Run automatic review tests (includes conformance tests)
./vendor/bin/phpunit tests/AutoReview

# Run a single test file or test method
./vendor/bin/phpunit tests/Core/SomeTest.php
./vendor/bin/phpunit --filter testMethodName

# Static analysis
composer phpstan:check    # runs PHPStan across all packages
composer phpstan:baseline # regenerates the PHPStan baseline — only use when a confirmed false positive/negative requires suppression; never add baseline entries to silence real errors

# Code style (check only)
composer cs:check

# Code style (fix)
composer cs:fix
```

### Key Tools

- **PHPUnit** — test framework
- **PHPStan level 10** — static analysis (strict)
- **PHP-CS-Fixer + Nexus CS Config** — code style enforcement
- **Minimum PHP version: 8.3**

## Architecture Conventions

### Core Package

The `core` package owns all MCP protocol types. These are modeled as immutable readonly classes or enums.
No server or client logic belongs here — only types, interfaces, and JSON-RPC primitives.
It can also provide abstract classes or traits for shared logic, but it should not have any concrete implementations of protocol handling.

MCP schema types map directly to the [MCP specification](https://spec.modelcontextprotocol.io). When the spec defines an object, it becomes a readonly PHP class. Enums in the spec map to PHP backed enums.

### Server Package

The server package depends on `core`. It defines:
- Handler interfaces that consumers implement (e.g., `ToolHandlerInterface`)
- A `Server` class that wires transports to protocol handling
- Transport implementations (stdio, Streamable HTTP)

### Client Package

The client package depends on `core`. It provides a `Client` class that connects over a transport and exposes typed methods for each MCP capability.

### Transport Layer

Transports are abstracted behind an interface defined in `core`. Both `server` and `client` depend on this interface. Concrete transport implementations live in the package that uses them.

### JSON-RPC

All MCP communication is JSON-RPC 2.0. Request/response/notification types live in `core`. The server and client packages build on these primitives and should not define their own wire-format types.

## Conformance Testing

This SDK must pass the official [MCP conformance test suite](https://github.com/modelcontextprotocol/modelcontextprotocol) maintained by the MCP project. Conformance tests are the authoritative check that the implementation adheres to the protocol specification. All new server and client functionality must remain conformance-compliant — if a conformance test fails, the implementation is wrong, not the test.

Conformance tests live in `tests/AutoReview/`, which is also the home for all other automatic review tests (e.g., architecture checks, coding standard enforcement at the test level).

## Code Style

- Strict types declared in every file: `declare(strict_types=1);`
- Namespace root per package: `Nexus\Core`, `Nexus\Server`, `Nexus\Client`
- Readonly classes for value objects and protocol types
- No public mutable properties; use constructor promotion with `readonly`
- PHPStan at max level — all code must pass without `@phpstan-ignore`. Test code can use `@phpstan-ignore` if necessary, but production code should not.
- Code style should use the `Nexus83` preset from Nexus CS Config. Use that library to construct the config for PHP-CS-Fixer.
- Classes should be final by default unless they are designed for extension (e.g., abstract classes or interfaces).
- Properties, parameters, and return types should be fully typed. Use `mixed` only when absolutely necessary, and prefer union types or generics (via docblocks) to express complex types.
- Use constructor injection for dependencies. Avoid service locators or static access to shared services.
- All exceptions must extend a package-specific base exception class (TBA). This allows consumers to catch all SDK errors with a single catch block.
- Mark implementation details with `@internal` so consumers know what is part of the public API vs internal to the SDK.
