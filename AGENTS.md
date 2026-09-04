# Agent Instructions

## Project Overview

This is a PHP monorepo implementing an SDK for the [Model Context Protocol (MCP)](https://modelcontextprotocol.io).
It is intentionally architected differently from the official PHP MCP SDK.

## Monorepo Structure

The repository is a single Composer monorepo with four logical namespaces under `src/`:

- `src/Core/`: shared foundation. JSON-RPC 2.0 types, MCP schema classes, and reusable utilities used by both server and client packages.
- `src/Server/`: MCP server implementation. Handles tool/resource/prompt registration and responds to client requests.
- `src/Client/`: MCP client implementation. Connects to MCP servers, calls tools, reads resources, and gets prompts.
- `src/Extension/`: the official MCP extensions (tasks, MCP Apps, OAuth extension grants), each with server and client halves. Depends on the other three, and nothing depends on it.

All code is managed under the unified namespace `Nexus\Mcp\` with the directory structure mirroring the namespace hierarchy. Tests mirror the source structure under `tests/` with namespace `Nexus\Mcp\Tests\`. Development tooling is isolated in a separate `tools/` directory with its own dependencies.

Each tree is also a Composer package: `src/<Tree>/composer.json` is published as `nexusphp/mcp-core`, `nexusphp/mcp-server`, `nexusphp/mcp-client`, or `nexusphp/mcp-extensions` from a read-only subtree mirror that `.github/workflows/split-components.yml` refreshes on every push to `1.x` and tags in lockstep on every release. The umbrella `nexusphp/mcp` replaces all four. Every tree carries its `README.md`, `LICENSE`, a `.gitattributes` that keeps `.github/` out of the dist, and a `.github/workflows/redirect.yml` that closes issues and pull requests opened on the mirror with a pointer back here. `composer deps:check` analyses each manifest against its tree, and `tests/AutoReview/ComponentManifestTest.php` holds the five manifests and the mirror scaffolding in lockstep.

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

# Dependency declarations (shadow/unused composer deps via shipmonk/composer-dependency-analyser), root and per-component manifests
composer deps:check

# Backward compatibility of the public surface against the latest stable tag (roave/backward-compatibility-check)
# Compares committed revisions only, so it sees no uncommitted work. Needs `composer update --working-dir=bc` once.
composer bc:check

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

# Lint the PHP code fences in markdown against the floor declared in composer.json
PHP_FLOOR_BIN=/path/to/php8.3 composer lint:fences

# Regenerate the schema snapshots (latest-schema.json + sorted-schema.json)
composer schema:generate

# Regenerate the spec @see anchor snapshot consumed by the auto-review test
composer spec:snapshot-anchors
```

### Key Tools

- **PHPUnit**: test framework
- **PHPStan level 10**: static analysis (strict)
- **PHP-CS-Fixer + Nexus CS Config**: code style enforcement
- **Minimum PHP version: 8.3**, enforced in documentation as well as in source. Code samples in
  markdown must parse on the floor, which `composer lint:fences` and the `doc-fences` workflow check.

## Architecture Conventions

### Core Package

The `Core/` subdirectory owns all MCP protocol types. These are modelled as immutable readonly classes or enums under `Nexus\Mcp\Core\`.
No server or client logic belongs here: only types, interfaces, and JSON-RPC primitives.
It can also provide abstract classes or traits for shared logic, but it should not have any concrete implementations of protocol handling.
All protocol envelope contracts (request/response/notification payload schemas) must be defined in `Core` even if one side typically originates them.

MCP schema types map directly to the [MCP specification](https://modelcontextprotocol.io/specification). When the spec defines an object, it becomes a readonly PHP class. Enums in the spec map to PHP backed enums.

### Server Package

The `Server/` subdirectory under `Nexus\Mcp\Server\` depends on `Core`. It defines:

- Handler interfaces that consumers implement (`ToolExecutorInterface`, `PromptRendererInterface`, `ResourceReaderInterface`, `TemplatedResourceReaderInterface`)
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

## Before making changes

- Target PHP 8.3 minimum, the oldest release still under security support. Use typed class constants (`public const string FOO = 'x';`), readonly classes, constructor property promotion, and `#[\Override]` where they fit. **Do not use 8.4+ syntax**: asymmetric visibility (`public private(set)`), property hooks (including `get;` on an interface property), the `#[\Deprecated]` attribute, `new Foo()->bar()` without parentheses, and `array_find` / `array_any` / `array_all`. A real 8.3 binary is the authoritative check, since PHPStan alone will not catch every construct: `find src tests -name '*.php' | xargs -P 8 -n 25 <php8.3> -n -l`.
- The SDK tracks **MCP spec version 2026-07-28 and later**. Do not add back-compat for earlier revisions (e.g. 2025-03-26 batching). The live schema sits at `latest-schema.json`, the class-to-schema map in `sorted-schema.json`. If either is missing, run `composer schema:generate`.
- `Core/Schema/` is types-only. Behaviour (parsers, codecs, registries) lives in sibling namespaces under `Core/`. Prefer editing existing files over introducing new layers.

## Spec compliance

Do **NOT** introduce types, params, or response shapes absent from the official spec, and ask before adding extension types. When in doubt, cite the `latest-schema.json` section (or the upstream [modelcontextprotocol/modelcontextprotocol](https://github.com/modelcontextprotocol/modelcontextprotocol) schema.ts) that justifies the type. PHP-only scaffolding (parsers, guards, exceptions) is allowed. Any class representing a JSON-RPC envelope or schema payload must correspond to a spec def.

**`latest-schema.ts` is canonical for shape and inheritance.** The JSON schema inlines `$ref` aliases, so abstract intermediate bases (`PaginatedRequestParams` and friends) look like flat property bags there and are easy to miss. Before adding a concrete `*Request` / `*Notification` / `*RequestParams` / `*NotificationParams` / `*Result` class, grep `latest-schema.ts` for the spec name and mirror its `extends` clause exactly, introducing the corresponding abstract PHP base first when the TS shows an intermediate. For marker membership (`ClientRequest`, `ClientNotification`, `ServerNotification`, `ClientResult`, `ServerResult`), read the TS unions at the bottom of the file. There is no `ServerRequest` union: the revision replaced it with `InputRequest`, whose members ride an `InputRequiredResult` payload rather than travelling as dispatchable requests. A class implements every marker whose union it appears in (`CancelledNotification` is both a `ClientNotification` and a `ServerNotification`).

**Strict spec compliance beats SDK convenience.** When a choice trades spec strictness for SDK-side simplicity (simpler tests, fewer code paths, easier ergonomics), pick the strict semantic and push the cost onto the SDK's own surrounding code (test helpers, fixtures, callers), never onto the envelope contract. Spec-covered edge cases (failed handshake, malformed envelopes, out-of-order notifications) are not optional. Surface the tradeoff before deciding rather than quietly taking the easier path.

**MCP narrows JSON-RPC 2.0 in places. Follow MCP.** MCP 2025-11-25 narrows `RequestId` to `int | non-empty-string` (no null), so a `JsonRpcErrorResponse` built with a null id omits the `id` key entirely from `toArray()` rather than emitting `"id": null`. This looks like a JSON-RPC 2.0 violation (that spec mandates a null id on parse and invalid-request errors) but is deliberate MCP conformance, pinned by a test. Do not "fix" it. Error-response tests for malformed input must assert the absent-id encoding (`{"jsonrpc":"2.0","error":{...}}`). The removal of batching is another such narrowing.

**The envelope's `id` decides request-versus-notification, never the method name.** §4.1 defines a notification as an envelope *without* an `id`, so that key is the only discriminator either dispatcher may consult when deciding whether to answer. `tools/list` arriving without an `id` is a notification and MUST go unanswered even though it names a request method, and `notifications/cancelled` arriving with an `id` is a request and MUST be answered with an error echoing that id. This holds uniformly across every parse-failure arm, misrouted methods included, so an inbound envelope that fails to parse has exactly two possible fates. Reject any change that makes one arm consult the method name, and be suspicious of a guard on `$isNotification` that only one of the two dispatchers applies.

Clients are narrower still, but only in what they *serve*. With no `ServerRequest` union, no spec method is served client-side, so a built client's request registry is empty until a consumer registers a handler through `ClientBuilder::addRequestHandler()` or supplies an extension declaring one. That says nothing about what a client *answers*: the id rule binds both dispatchers equally, so a client still answers an id-carrying envelope it cannot serve with an error, and still drops the same envelope unanswered when it carries no id. A default client with an empty registry does send JSON-RPC responses, and two dispatcher tests pin it.

## Workflow gates

Every structural change to `src/` or `tests/` must survive the gate suite before it is done. That suite is **`composer test:with-untracked`**: it covers unstaged and untracked work, and its mutation step is the diff-based one. `composer test:all` is the same chain with full-tree mutation instead, which pushes it past 17 minutes. Reserve it for the maintainer, and do not reach for it or offer it as a pre-merge sweep. For fast iteration, the single-concern scripts are enough (`composer test:core` / `test:client` / `test:server` / `test:extension` / `test:auto-review` / `test:stan`). Reach for the script, not a bare `vendor/bin/phpunit`: each one carries `@putenv XDEBUG_MODE=off` plus the group and coverage flags.

**Scope verification to what changed.** Every step of the gate is about PHP under `src/` and `tests/`, so pick the checks that cover the files actually touched, name the ones run, and take the union for a mixed set:

- `composer lint:docs` runs regardless of what changed: `lint:typos` spellchecks the whole repository, source included.
- `src/` or `tests/` changed only in a comment or non-type docblock prose → `cs:check` plus `phpstan:check`, and stop: no statement changed, so coverage and the mutant set are identical. Type-bearing annotations (`@param`, `@return`, `@var`, `@template`, `@phpstan-assert` and friends) are code, not comments, and earn the full gate. One exception: the class-level docblocks under `Core/Schema/` are asserted verbatim against `latest-schema.json` by `SchemaConformanceTest`, so add `composer test:auto-review` when one is touched, and their spec-verbatim punctuation must survive any prose cleanup.
- PHP outside `src/` and `tests/` (`tools/src/`, `conformance/`, `examples/`) → `cs:check` plus `phpstan:check`, nothing else: no PHPUnit suite runs them, `coverage:check` reads only `src/` statements, and `infection.json5` lists `src` as its single source directory. The tree is the trigger, not the file extension.
- Only `*.sh` → `bash -n <file>`. Only `.github/workflows/*.yml` → `python3 .github/scripts/validate_yaml.py`, plus `.github/scripts/actionlint` when installed. Only `*.md` → `lint:docs`, plus `lint:fences` when the markdown carries PHP fences. `composer.json` scripts → `composer validate --strict`.
- Work a green gate already verified stays verified: a later, unrelated edit does not restart from zero.
- The conformance runs (`conformance:server` / `:client`) sit deliberately outside the gate and are re-run only when the harness, the fixtures, or the `src/` behaviour they exercise changed.

Mutation testing:

- **Default during iteration: skip mutation.** A code change needs only `cs:check` + `phpstan:check` + the relevant test suite. Mutation is not part of the inner loop.
- **`composer mutation:filter`** (diff-based against `origin/1.x`, picks up untracked files) is the run to reach for when one is needed, and is what `test:with-untracked` invokes.
- **Scoped, never bare:** `composer mutation:check -- path/to/File.php` is the authoritative per-file check (the `--` passes paths to Infection as positional arguments, space-separated to batch). Infection 0.34 deprecated `--filter` in favour of that form. Bare `composer mutation:check` is full-tree at 17+ minutes and belongs to the maintainer, same as `test:all`. The bottleneck is per-mutant forked PHPUnit, so do not propose PCOV or `infection.json5` perf tweaks.
- A scoped run mutates the **whole** file, not just the changed lines, so it surfaces mutants on pre-existing committed code. Check `git diff origin/1.x` on the line before treating an escapee as newly introduced.
- Both enforce **100% MSI** and **100% Covered Code MSI** (`minMsi` / `minCoveredMsi` in [infection.json5](infection.json5)). Escaped mutants fail the build, so improve the tests rather than adding ignores.
- **`followRedirects(0)`'s `DecrementInteger` mutant is exempt because it is provably equivalent.** `HttpClientBuilder::followRedirects()` nulls its interceptor for any `$limit <= 0`, so `0` and `-1` are the same call and no test can tell them apart. The exemption is a regex on the call, not a method-wide ignore.
- **A test that spawns a subprocess cannot validate a mutant.** Infection's `IncludeInterceptor` registers a userland `file://` wrapper, so `proc_open()` fails and Infection scores that error as a kill: 100% MSI over code no test reached. Hence the spawn sits behind `SubprocessLauncherInterface`, the transport test drives a scripted subprocess, and only the two adapter tests spawn. Those declare `#[CoversClass]` on the adapters alone, so under `requireCoverageMetadata="true"` Infection cannot select them as covering tests for the transport. The adapters themselves sit in `source.excludes` and must stay branch-free: a branch there goes silently unmutated.

Line coverage is a separate gate, not an Infection metric. `composer coverage:check` parses the Clover report `test:unit` emits and fails on any uncovered `src/` statement line. MSI cannot stand in for it: a line that generates no mutant (e.g. a plain assignment from a consumed method call) stays invisible to MSI even when no test exercises it. The gate runs inside `test:with-untracked` right after `test:unit`, and in the `unit-tests` CI workflow. The PHPUnit-running CI workflows set `zend.assertions=1` (PHPUnit's recommended dev config) so structural `\assert()` lines are exercised. Under the production default `-1` those lines strip to nothing and read as uncovered.

Docs sync: after any change that renames, deletes, moves, or adds a top-level building block (class, trait, enum, namespace, interface, public method, file location), grep `docs/`, `ROADMAP.md`, and the project memories for the old symbol and update them, then run `composer lint:docs`. Skip only for purely internal changes (private body, test-only, comment-only).

## Doc linters

`composer lint:docs` bundles typos (whole-repo) and markdownlint. Auto-fix with `composer lint:fix`. Gotcha: `composer lint:typos:fix` rewrites identifiers in source files too, so review the diff before staging.

`composer lint:fences` is separate, and parses every PHP code fence in the repository's markdown against the floor declared in `composer.json`. It is deliberately **not** bundled into `lint:docs`, so `test:with-untracked` does not hard-require a floor binary on every machine. Point `PHP_FLOOR_BIN` at a real 8.3 binary to run it (the default is a bare `php8.3` on `PATH`), and note it refuses to run rather than skipping when that binary is missing or reports the wrong version. It lints each fence twice, on the floor and on the running interpreter, because that is what separates a genuine drift from a deliberately partial fragment. The `doc-fences` workflow enforces it in CI.

## Conventions

### Namespaces and layout

- `Nexus\Mcp\Core\Schema\*`: protocol types only (value objects, enums, interfaces). No behaviour.
- Abstract bases sit at the root and concrete subclasses live in same-named subfolders: `Schema/Result.php` + `Schema/Result/EmptyResult.php`, and likewise for `Request`, `Notification`, `RequestParams` and `NotificationParams`.
- `Nexus\Mcp\Core\JsonRpc\*`: JSON-RPC envelope behaviour (parser, guards). The envelope-parsing guard lives here, not under `Schema\`, even though schema classes consume it.
- `Nexus\Mcp\Core\Exception\*`: every SDK exception implements the `McpExceptionInterface` marker so consumers can `catch (McpExceptionInterface $e)`.

### Exception classes

Two shared final classes carry every message-only failure: `Core\Exception\LogicException` (SDK misuse,
surfaced at composition time) and `Core\Exception\RuntimeException` (flow diagnostics). Both implement
`McpExceptionInterface`. Throw them with the full message composed at the site. When one template serves
several sites, single-source it in a private `: never` helper (or an exception-building factory where the
site needs the object, e.g. a `match` arm).

Do **not** mint a new exception class unless at least one of these holds:

1. **The type is peer-visible.** The `AbstractJsonRpcProtocolException` family, whose `getErrorCode()`
   decides the JSON-RPC error code. Message composition *into* another diagnostic does not count: the
   composing site owns the wrapper template and the composed class can still collapse.
2. **Something branches on the type**: an `src/` catch that does more than re-compose the message, the
   conformance client's refusal list, or a documented consumer pattern (`ClientRegistrationRequiredException`
   triggering DCR, `StalledTaskException` triggering a later poll).
3. **It carries a property read somewhere other than its own constructor and its own dedicated test**
   (`RemoteCallFailedException::$error`, the `onError` signal objects). A property only interpolated into
   the message is not a property: drop it.
4. **A shared catch would over-match.** A typed catch that scopes recovery to one failure
   (`TypeNodeSchemaMapper::isMappable()` converting `UnsupportedSchemaTypeException` to `false`) cannot
   widen to the shared class without swallowing unrelated errors, and a class whose template serves
   several files (`UnsupportedReturnValueException`) is that message's single source.

### Method naming

- **Methods are verb-first**, private helpers included: `readLine()`, `resolveNameField()`, `resolveBindings()`, not `line()`, `nameField()`, `bindingsFor()`. A noun name reads as a property accessor and hides that the call does work. Interface-mandated names (`list()`, `call()`) are exempt, as is anything a third-party contract fixes.

### PHPDoc style

- **Prefer native phpdoc tags over `@phpstan-*` variants.** Use `@return`, `@param`, `@var`, `@implements`, `@extends`, `@throws`, `@template`, etc. Reach for `@phpstan-*` only where there is no native equivalent (`@phpstan-assert`, `@phpstan-consistent-constructor`, `@phpstan-type`, `@phpstan-import-type`, `@phpstan-ignore`).
- **Class and method docblocks stay concise and behaviour-focused**: what the class *is/does* in 1–2 sentences plus structural tags, never why it exists in its current form. Decision history belongs in commits and PRs. Same for inline comments in data files and fixtures: no "this verifies that we fixed X" headers.
- **When a concrete request narrows its `$params` to a subtype of `RequestParams`** (e.g. `ListResourcesRequest` taking `PaginatedRequestParams`), PHP keeps the inherited property typed as the parent at runtime and intelephense flags `$req->params->cursor` as undefined. Add a class-level `@property-read PaginatedRequestParams $params` between the description and `@extends`. Use `@property-read`, not `@property` (the CS preset keeps the distinction). Only the concrete leaf classes that narrow need it.

### `@internal`, `@final`, `@no-final`

- **Do** apply `@internal` to implementation-detail classes that should not be consumed externally. PHPStan then flags external callers.
- **If a class should be final but cannot use the `final` keyword** (typical reason: it is mocked in tests), add a `@final` phpdoc tag to communicate the intent to PHPStan and humans.
- **If an `@internal` class is explicitly meant to be extended within this package**, add `@no-final` so the `final_internal_class` CS fixer does not promote it to `final`.

### Spec mapping

- PHP class names map to spec defs via a basename-normalizer in the schema processor under `tools/`. If a new class's name diverges from the spec's, extend the normalizer rather than renaming the class.
- `$ref` aliases (where one spec def points to another shape) are inlined at load time so aliased schemas expose the full shape for conformance testing.
- Spec `"type": "number"` maps to PHP `float`, enforced by `SchemaConformanceTest::normaliseJsonType`, so the constructor parameter and property are typed `float`. Decoding in `fromArray` is permissive: it accepts both JSON ints and floats and coerces ints via the shared `Nexus\Mcp\Core\Schema\ParsesNumber` trait (`self::parseNumber($value, $message)`). Phrase the error message as "must be a number", and exercise both int and float inputs in the happy-path data provider.

### Schema stability (HIGH PRIORITY)

Schema classes under `src/Core/Schema/` are stable value objects locked to the MCP spec shape. Internal micro-DRY (helper extraction, trait lifting, abstract base lifting, and the like) is not worth the added abstraction unless it fixes a real envelope-encoding bug. The duplication is byte-identical except for class-name prefixes, the schema tracks the spec exactly and rarely changes, and each extraction adds a layer the reader must follow.

- Before proposing a schema-class refactor, ask whether it fixes an encoding bug or only adds abstraction. If abstraction-only, skip it and document why rather than landing it.
- Dead-code removal in schema classes is allowed, but only after verifying that no test, no direct-encode path, and no PHPStan-narrowing role exercises the "dead" branch.
- Trait extraction for shared schema fields also loses constructor property promotion and forces class-name plumbing into error messages, an ergonomic regression even where the rule would otherwise permit it.
- The rule does not block non-schema cleanups under `Server/`, `Client/`, and core classes outside `Core/Schema/`. Those are normal-cadence refactors.

### Empty-object encoding: Pattern A vs Pattern B

`json_encode([])` emits `[]`, but the MCP spec requires `{}` for object-typed positions. The fix depends on whether "empty" carries meaning:

- **Pattern A (empty has meaning): substitute `\stdClass` to emit `{}`.** Applied inside `jsonSerialize()`, at the leaf or on an abstract base its leaves inherit (the `MetaObject` family does the latter). Capabilities classes use a dual-loop walker because they nest empties: the top-level loop stays in `jsonSerialize()` (preserving the strict `array<string, mixed>` return), and the recursive helper takes `array<array-key, mixed>` so the `is_array($value)` narrowing needs no runtime `Assert::isMap`.
- **Pattern B (empty is meaningless): omit the slot in the concrete class's `toArray()`.** The abstract `RequestParams`, `NotificationParams` and `Error` bases declare no `toArray()` of their own, so the omission lives in each leaf: `EmptyNotificationParams` drops an empty `_meta`, and the concrete `Error` subclasses emit `data` only when it is set. Request params always carry `_meta`, since the spec's lifecycle fields make it required there. Open-object slots whose contents have no further schema (`Error::$data`, `MetaObject::$extras`) are always Pattern B at the slot itself: an empty list there is legitimately a list and stays `[]`.

The `Arrayable::jsonSerialize(): array<string, mixed>|\stdClass` return type is widened to formalise Pattern A. `toArray()` keeps the strict `array<string, mixed>` binding for round-trip purity. Decide per slot on a new schema class: semantic distinction between empty and absent picks Pattern A (add a `json_encode` substring test asserting `"slot":{}`), equivalence picks Pattern B (add a test asserting the slot is omitted).

### Server-side composition

- **Registration lives on `ServerBuilder`, not on `Server`.** Tools, prompts, resources, request and notification handlers, loggers, and request-id factories all register via fluent `ServerBuilder::add*` / `set*` methods before `build()`. After `Server::run(TransportInterface)` is called, no further registration is possible. Don't add convenience setters like `Server::addTool()`. The immutable-after-build shape is intentional.
- **Construct builders directly: `new ServerBuilder()` / `new ClientBuilder()`.** There is no static `Server::builder()` / `Client::builder()` factory. The builder vocabulary (`setServerInfo` / `addTool` / `build`) is conventional and intentional. Differentiate on substance (strict typing, no reflection magic, mutation-tested correctness), not on a novel construction surface.
- **Built-in request handlers depend on exactly one per-feature store** (`ToolStore` and its siblings), constructor-injected. There is no umbrella registry. When adding a new feature, mirror the per-feature-store shape rather than reaching for a combined `Capability\Registry`.
- **Dispatch is method-name → handler at registration time** (TS-SDK style), not the official PHP-SDK's polymorphic `supports($method)` walk. This is what motivates the phantom `@template-covariant TMethod` on `RequestHandlerInterface` / `NotificationHandlerInterface` (see [Per-method request/notification/result classes](#per-method-requestnotificationresult-classes)).
- **Attribute discovery is method-level plus `#[AsServer]`, registered explicitly.** The per-feature `#[As*]` attributes on public methods, and a class-level `#[AsServer]` for identity, are read by `Server\Discovery\AttributeScanner` and applied via `ServerBuilder::register(object ...$sources)`. Explicit `setServerInfo()` / `setInstructions()` win per field over `#[AsServer]` (the attribute fills only the gaps), and more than one `#[AsServer]` across sources throws `DuplicateServerMetadataException`. Still explicit non-features: filesystem auto-discovery (`symfony/finder`), class-level handler backends (a whole class as one tool), PSR-14 event dispatch, and any client-side attribute discovery. Each is its own milestone. Don't add them incidentally.
- **`ServerBuilder::addRequestHandler(method, handler)` is the power-user escape hatch.** It looks redundant next to the typed per-feature builders, but it's the seam consumers reach for to register handlers for spec methods that have no typed builder. Preserve it.
- **`ServerInterface` / `ClientInterface` and their builder interfaces are explicit non-extractions.** A one-method `ServerInterface` (`run(TransportInterface): void`) is feasible but skipped: no foreseeable second implementation, `Client` is not a `Server`, and decorator use cases are speculative. A `ClientInterface` would instead be wide (one typed method per MCP capability), which only sharpens the objection: a large surface forced into lockstep with a second implementation no consumer needs. The builder interfaces (`ServerBuilderInterface` / `ClientBuilderInterface`, each the fluent surface plus `build()`) are actively unwise: the wide surface forces lockstep, `: self` in an interface upcasts away the concrete type, and `build(): Server` / `build(): Client` couples each to its concrete-class decision. Do not re-derive any of them.

### Per-method request/notification/result classes

The spec defines methods as named JSON-RPC operations. Each gets a concrete request (and optionally a notification or result) class.

- Each concrete request/notification overrides `static getMethod(): string` to return the JSON-RPC method name as a literal. That accessor is the single source of truth: registry, parser, error messages, and tests all read it. Do not introduce a `JSONRPC_METHOD` constant. The method name is polymorphic per-subclass behaviour and belongs in a method.
- Pin the literal at the type level. Bases declare `@template-covariant TMethod of non-empty-string`, and concrete subclasses must bind it via `@extends Base<'method-name'>`. Without that binding, `getMethod()` widens to `non-empty-string` and the type-inference tests under `tests/AutoReview/data/` will fail.
- When adding a new spec-defined request/notification class, register it in the method registry under `Core/JsonRpc/` so callers get it automatically. User-supplied maps passed to the parser merge over the defaults per-key, so callers need only specify overrides or non-default method classes.
- For methods with no typed params, reuse the shared `EmptyRequestParams`/`EmptyNotificationParams` (under `Core/Schema/RequestParams/` and `Core/Schema/NotificationParams/`). Only add a new subclass of the abstract `RequestParams`/`NotificationParams` base when the method carries typed fields beyond `_meta`. Same rule for `Result` subclasses: use `EmptyResult` unless the method carries a typed payload.
- The dispatch registries are typed `array<non-empty-string, RequestHandlerInterface<...>>`. `TMethod` is a phantom template: it pins the literal for storage (covariant subtype assignability into the heterogeneous registry slot) but does not appear in `handle()`'s parameter, which stays at the wide `JsonRpcRequest<non-empty-string>`. Narrowing `handle()` to `JsonRpcRequest<TMethod>` cannot be done without breaking dispatch (PHPStan's call-site variance projection collapses the parameter to `JsonRpcRequest<never>`). Do not strip the phantom template or refactor the registries to a `supports()` list.

### `Arrayable` and the success-response exception

Schema classes implement `Arrayable` (`fromArray()` / `toArray()` / `jsonSerialize()`) for round-trip JSON-RPC envelope construction. The one documented exception is the JSON-RPC success-response wrapper: its payload has no method-name or discriminator in the envelope, so the parser requires caller-supplied context (the expected `Result` subclass) to decode it. That wrapper therefore has no `fromArray()` and is constructed only via the parser's success-response entry point.

When the spec defines two structurally similar shapes that differ only in optional extra fields, model them as peer final classes, not parent/child. Inheritance implies LSP substitutability that doesn't hold when the schemas diverge.

### Runtime validation: use `nexusphp/assert`

[`nexusphp/assert`](https://github.com/NexusPHP/assert) is a production dependency. Reach for it in constructors and `fromArray()` methods instead of inline `is_int`/`is_string`/`sprintf` + `new \InvalidArgumentException(...)`. Its PHPStan type-specifying extension is auto-registered via `phpstan/extension-installer`.

- **Before adding any narrowing, try removing it.** Modern PHPStan tracks types through array mutations and conditional returns, so a narrowing line it accepts as absent was redundant. Signal: if Infection's `MethodCallRemoval` strips an `Assert::that()->isX()` call with no test failing, it was a structural no-op. That the removal then trips PHPStan does not save it, it means the narrowing stands in for a type the code never declared, so declare the type instead. `JsonRpcMessage` gained its `toArray()` declaration exactly this way, retiring the `\assert(method_exists(...))` plus `isMap()` pair at every transport. The same reading covers any pure type-level call: `array_values(array_filter(...))` whose `array_values` only produced a `list<…>` return type belongs as a `foreach` that appends.
- **Where narrowing is genuinely needed, at an input boundary, use `Assert::that()`** (always active, integrates with the type-specifying extension). Reserve native `\assert()` for structurally-guaranteed branches that exist only to satisfy PHPStan, and never rely on it for runtime validation: `zend.assertions=-1` strips it in production, and every mutation run measures with it off (the `mutation:*` scripts append `tools/ini` to `PHP_INI_SCAN_DIR`, matching the CI job's default), so a mutant on the line reads as not covered rather than escaped. `NativeAssertConditionRule` holds the condition to shapes that carry no mutant at all, an `instanceof` or a single-argument `is_*()` call over a plain variable, so a comparison is refused even where it narrows honestly.
- `Assert::ExpectationFailedException` extends `\InvalidArgumentException`, so existing `catch (\InvalidArgumentException $e)` wrap-and-rethrow patterns keep working.
- Messages are templates interpolated via `strtr`: `{value}` (value-exported) and `{type}` (from `get_debug_type()`). Example: `'JSON-RPC envelope "method" must be a non-empty string, {type} given.'`.
- **String-keyed arrays**: `Assert::that($x)->isArray('… {type} given.')->isMap('… string-keyed object.')`. The two-step chain preserves distinct messages for "not an array" vs. "int-keyed array". A single `isMap` call collapses both into one.
- **Must-have keys on a known array**: `Assert::that($data)->hasOffset('key', 'missing message.')`. Its bundled `isArray` check is redundant on already-typed input but harmless. Preferred over a bare `array_key_exists` when you want the message to live next to the other Assert chains.
- **Conditional keys** (`if (\array_key_exists('_meta', $data))`): leave as native PHP. Assert has no "optional key" shape.
- **Ids**: use `isIntOrNonEmptyString` for any value that goes on to build a `RequestId` or `ProgressToken`, never `isArrayKey`. The spec shape is `int|non-empty-string`, and `isArrayKey` admits `''`, deferring the failure a frame to a constructor whose message names less of the contract.

### Constructor narrowing: strict `@param` plus promotion

A constructor parameter states its narrowed type on the `@param` and is promoted to the property, so callers see the contract at the call site rather than discovering it from a separate `@var`. The runtime `Assert` chain stays: the phpdoc is the signal, the assert is the enforcement, and `treatPhpDocTypesAsCertain` stays `false` so the two do not fight (see [Static analysis and CS gotchas](#static-analysis-and-cs-gotchas)).

Narrowing a schema class pushes outward, and that is the point. A decoder that fed the constructor a coarse `isString` must tighten to `isNonEmptyString`, which changes its error message and the test that pins it. Where the value originates in a public entry point, that entry point declares the narrow type too: the builders' `set*Info()` methods, the `Client` capability calls, the reader interfaces, and the `#[As*]` discovery attributes all carry `non-empty-string` for exactly this reason. An attribute argument is a compile-time constant, so `#[AsTool(name: '')]` is a static error at the declaration rather than a runtime throw.

**`PromotableConstructorPropertyRule` enforces this**, flagging a parameter stored unchanged in a same-named property when the two carry the same declared type. It stays silent on four shapes, each a real class here:

- **Reassignment.** A promoted property is assigned *before* the constructor body runs, so `$x = [] === $x ? null : $x;` would be silently discarded. Several result and params classes normalise an empty map to `null` this way. This one loses data with no test failure and no PHPStan error, so never promote past it.
- **Transformation.** `Annotations` parses a string into a `DateTimeImmutable`, and `Tool` runs both schemas through validators.
- **A property narrower than its parameter.** A promoted property's type *is* its parameter's type, so the divergence cannot be expressed. `ProtectedResourceMetadata` accepts `list<non-empty-string>` and its guard narrows to `non-empty-list`, which its caller (`readStringList(...) ?? []`) genuinely cannot satisfy.
- **Constraints richer than phpdoc.** `ResourceIdentifier` needs "absolute URI carrying no fragment or userinfo" and rewrites its input besides.

### String composition and logging

- **Prefer `\sprintf('%s …', $value)` over concat (`$value.' …'`)** when composing strings with dynamic pieces, matching the codebase's use of `\sprintf` for exception messages, Assert templates, and string-building helpers. Simple two-piece literals like `$prefix.': '.$line` are still fine.
- **Logger messages are the exception.** Pass a literal PSR-3 template (`'{label} transport sent {kind}.'`) as the message and the dynamic values via the context array (`['label' => $this->label, 'kind' => self::describe($message)]`). Do not pre-render with `sprintf`. Aggregators index `label`/`kind`/etc. as structured fields, and tests match against the raw template via `ArrayLogger::recordsMatching('{label} transport sent {kind}.')` plus a context-equality assertion.
- The `{type}` and `{value}` tokens in `nexusphp/assert` messages are interpolated by the library at throw time, not by `sprintf`. Leave them as-is inside a `sprintf` template when injecting a class const or computed prefix: `\sprintf('%s command must be a list, {type} given.', self::LABEL)`.

## Transport architecture

`Nexus\Mcp\Core\Transport\TransportInterface` is contract-only, a sibling of `Core/JsonRpc/`. The design is shaped around streamable HTTP (the constraining transport) so stdio falls out as the simple case.

- **The transport is a dumb pipe.** The protocol layer (Server/Client) owns parsing and correlation. Interface methods: `start()`, `send(JsonRpcMessage, ?SendContext)`, `close()`, and the `onMessage` / `onClose` / `onError` / `onDrain` listener setters (each call appends to the chain rather than replacing it). No `Future` or `Promise` types leak to consumers.
- **`onMessage` receives `array<string, mixed> $envelope`, not a parsed `JsonRpcMessage`.** The transport stops at JSON-decode plus a map-shape check. Parsing to nominal types belongs to the protocol layer, which owns the parser and the pending-request map and so can build spec-coded `ParseError` / `InvalidRequest` / `MethodNotFound` / `InvalidParams` responses with the recovered request id.
- **`SendContext` is a value object on `send()`** carrying per-send routing metadata (`relatedRequestId`). `ReceiveContext` is its inbound counterpart, an empty placeholder until streamable HTTP adds `request` and `authInfo` slots.
- **Substrate is Revolt + AMPHP v3.** Fibers make the sync-looking signatures honest, and the same machinery serves stdio and the streamable-HTTP SSE writer. Property hooks are avoided on the interface because intelephense does not parse them yet, so auto-composing setter methods achieve the same behaviour while staying IDE-friendly.
- **Streamable HTTP uses PSR-15.** The HTTP transport implements `RequestHandlerInterface`, so it exposes `handle(ServerRequestInterface): ResponseInterface`. The SDK does not ship its own HTTP server. The HTTP-only deps are `psr/http-message`, `psr/http-server-handler`, `psr/http-factory`.
- **The protocol is sessionless.** The 2026-07-28 spec removed `Mcp-Session-Id` and protocol-level sessions: every request is self-describing via its `_meta` lifecycle fields, so the transport carries no session identity. Concrete transports may add transport-specific methods (e.g. the HTTP transport's `handle()`) beyond the interface.
- **Two-layer split: transport plus (Server, Client), with no abstract `Protocol` base.** Correlation and dispatch are shared via composition, not a trait. The protocol version is carried per-request in `_meta` (`io.modelcontextprotocol/protocolVersion`) and read by the protocol layer, not pushed onto the transport.

## Static analysis and CS gotchas

- **Inline `@var` survives only when it narrows to a valid subtype of the inferred type** (e.g. a union down to one member) and sits directly on an assignment. The CS fixer strips it two ways otherwise: `phpdoc_no_incorrect_var_annotation` removes a `@var` whose type contradicts inference, and `phpdoc_to_comment` rewrites a standalone (non-assignment) docblock to `// @var …`, which PHPStan ignores. When `@var` won't hold, or you want a runtime guard at an input boundary, use an `Assert::that(...)->isX()` chain (see [Runtime validation](#runtime-validation-use-nexusphpassert)).
- **`is_array()` narrows to `array<mixed, mixed>`**, not `array<string, mixed>`.
- **`treatPhpDocTypesAsCertain` stays `false`, and flipping it is not an improvement.** Forcing it true reports every `Assert` chain enforcing its own strict `@param` as already-narrowed, which is the whole shape described in [Constructor narrowing](#constructor-narrowing-strict-param-plus-promotion). Infection already catches genuinely redundant narrowing: an unnecessary call is an unkillable `MethodCallRemoval` mutant under the 100% MSI gate. All the flag uniquely adds is `instanceof.alwaysTrue` on the request handlers' `\assert($context instanceof ServerContext)` lines, and those are load-bearing for a different reason: intelephense does not resolve the `@implements RequestHandlerInterface<…, ServerContext>` generic, so removing them reds out every member access in the editor even though PHPStan is content. Verified by removing them all and re-running both.
- **`@return static` on a method whose PHP return type is `: static`** is flagged as superfluous and stripped. Document the parameter, let the return type speak for itself.
- **Widening `@param` on interface methods violates LSP contravariance when concrete implementations narrow the type.** Loosen an `Arrayable` implementation's input to `array<string, mixed>` and every sibling class must match or PHPStan flags it. Prefer keeping the generic `@param T` and using `Assert::that($value)->isMap(...)` at the call boundary.
- **`@phpstan-consistent-constructor`** is required on non-final classes that use `new static(...)` in static factory methods.
- **Use the native `: never` return type** on methods that unconditionally throw, even when the interface signature declares a concrete return type. `never` is a bottom type, so narrowing any return type to it is LSP-safe. Resort to `@phpstan-return never` only when you cannot change the PHP signature (e.g. an inherited third-party interface method that some tooling constraint forbids narrowing).
- **`final public function __construct`** is contagious. Don't add it unless you are sure subclasses will never need to declare their own constructor.
- **`@phpstan-sealed Foo|Bar|Baz`** on a parent's docblock closes the subtype set. PHPStan narrows through the union: after `instanceof` eliminates N−1 cases in a `match (true)`, use `default =>` for the Nth (a redundant `instanceof` arm trips `instanceof.alwaysTrue`). Declaring a new subtype without adding it to the seal list trips `interface.disallowedSubtype`. Use for marker interfaces with a spec-fixed implementation set (e.g. `JsonRpcMessage` closed to request / notification / response). Kills "unreachable default arm" Infection mutants.
- **PHPStan false positives go to the baseline, not to an inline ignore or an `Assert` workaround.** When PHPStan flags something provably safe at runtime (e.g. `[$a, $b] = explode($d, $s, 2)` after a `str_contains($s, $d)` guard), run `composer phpstan:baseline` and keep the audited entry. The baseline is the central, diff-friendly record of "PHPStan disagrees with us here". Do not add an `Assert::that()` chain to silence it (that tool is for runtime input validation at trust boundaries, not for narrowing already-guaranteed values), and avoid scattered inline `@phpstan-ignore`. This repo enables `reportPossiblyNonexistentGeneralArrayOffset` and `reportPossiblyNonexistentConstantArrayOffset`, which the phpstan.org/try playground does not expose, so offset-access false positives must be filed upstream directly, naming those flags.
- **A public class that implements an interface may expose only `__construct` plus interface or inherited methods.** `SourceClassConventionsRule`'s interface-faithfulness check flags any other public method (instance or static). It is about faithfulness to a *declared* contract, so read its three preconditions before designing around it: it skips `@internal` classes wholesale, skips classes whose `getInterfaceNames()` is empty, and skips any individual method tagged `@internal`. A class implementing nothing is never subject to it, which is why public value objects can carry behaviour. Where the rule does bite, it bites hard: every public static factory in the repo sits on an `@internal` helper class, and `INTERFACE_FAITHFULNESS_EXEMPT` is reserved for constraints a PHP interface genuinely cannot express (paired construction with a private constructor, a half-`Arrayable` envelope).

## Test patterns

- Tests mirror `src/` layout under `tests/` with namespace `Nexus\Mcp\Tests\`. Test-only fixtures live under `tests/Fixtures/{Core,Client,Server}/` (namespace `Nexus\Mcp\Tests\Fixtures\{Core,Client,Server}`): a single top-level tree so fixtures can be shared across suites without false ownership. Never place fixtures under `src/`.
- Every test class: `final`, `@internal`, extends `Nexus\Mcp\Tests\AbstractMcpTestCase` (never PHPUnit's `TestCase` directly), attributes `#[CoversClass(Foo::class)]`, `#[Group('unit-tests')]`, `#[Group('core-tests')]` (swap `core` for `client`/`server` as appropriate).
- **`AbstractMcpTestCase` declares itself twice**, picking the branch that matches the installed PHPUnit major, and is excluded from PHPStan analysis for that reason. PHPUnit 13 requires PHP 8.4.1, so the PHP 8.3 job resolves to PHPUnit 12, which has no `expectExceptionMessageIs()`. The fallback branch supplies it as a fully anchored `preg_quote` pattern, exact-equality by another route. The method is `final` in PHPUnit 13, so a trait or a plain subclass cannot supply it and the conditional declaration is the only shape that works. Delete the shim when the floor next rises.
- Data provider methods are named `provide{TestMethodSuffix}Cases`. The CS fixer will rename them otherwise.
- For happy-path void functions that merely need to "not throw," use `$this->expectNotToPerformAssertions()` rather than `self::assertTrue(true)`, which PHPStan flags.
- **`assertInstanceOf` narrows in both PHPStan and intelephense**, so use it directly for nested access after an instance check. PHPUnit 13 declares `@phpstan-assert =ExpectedType $actual` natively, the floor's PHPUnit 12 narrows through the phpstan-phpunit extension, and intelephense honours the tag. Native `assert($x instanceof Y)` is flagged by PHPStan as redundant after the assertion, so avoid it.
- **`assertSame` vs `assertEquals` for value objects**: `assertSame` compares identity and fails for distinct but structurally equal objects. For round-trip tests, compare `$original->toArray()` to `$reconstructed->toArray()`. The CS fixer `php_unit_strict` rule will convert `assertEquals` to `assertSame` and break object comparisons otherwise.
- **Cross-path encoding check on round-trip fixtures**: `AbstractRoundTripTestCase` asserts `json_encode($instance) === json_encode($instance->toArray())` so the `jsonSerialize` and `toArray` paths can't drift. Classes whose `jsonSerialize` substitutes `\stdClass` for an empty object slot must opt out by setting `'encodingPathsDiverge' => true` on their registry entry in `JsonRpcEnvelopeRoundTripTest` / `SchemaPayloadRoundTripTest`. Composition counts: an envelope whose payload contains such a class at any nesting level inherits the flag.
- **Exception-message assertions**: default to strict `expectExceptionMessageIs('full message')`. Asserting the whole message kills concat-swap and operand-removal mutants. PHPUnit deprecated the loose `expectExceptionMessage()` (substring matching), so do not reintroduce it. When the message carries a dynamic tail you cannot assert in full, anchor the stable prefix with `expectExceptionMessageMatches('/^prefix …/')`. Reserve `expectExceptionMessageIsOrContains()` for the rare case where neither exact nor anchored-regex matching fits.
- **Data providers for invalid-input tests**: to drive a value into a narrower chain (e.g. `Assert::that($x)->isMap()`) whose type-specifying extension fails at the type level for a bad input, accept the value via `mixed $value` from a data provider. PHPStan will not narrow `mixed` and won't flag the test.
- **Type-inference tests (`tests/AutoReview/data/*.php`) target production classes only**: the real spec classes in `src/` and their generics. Do not pad data files with assertions against test fixtures (`tests/Fixtures/...`), which change for scaffolding reasons unrelated to the SDK's public contract. If a generic surface has no concrete production class yet, skip the data file rather than substituting a fixture.
- **A deadline is not loop work, so an in-memory fixture cannot reach one.** `RecordingTransport` holds no I/O of its own, so with only an unreferenced deadline armed the loop runs dry and `Future::await()` throws `Error: Event loop terminated without resuming the current suspension`. A real transport always holds referenced I/O open while a request is in flight, so this is a fixture gap, not a production one. Anchor such a test with a self-terminating referenced timer (`Amp\delay()` past the deadline), which is what `ClientTest::awaitPastDeadline()` does. Never give a fixture a watcher for its own lifetime: that reintroduces the very leak the unreferencing exists to prevent.
- **Tests run in random order** (`executionOrder="random"` in [phpunit.dist.xml](phpunit.dist.xml)), so an order-dependent bug is a per-run lottery. `--random-order-seed=N` only takes effect alongside `--order-by=random`, so pin both when reproducing one, and sweep several seeds before calling it fixed.
- **`@phpstan-ignore` in tests is a narrow exception**, valid only when the test deliberately feeds malformed input to exercise a runtime guard that PHPStan would reject statically before the runtime code ever runs. Otherwise fix the code structurally. The `mixed $value` data-provider pattern above is usually the better alternative.

## Mutation testing tips

When `mutation:check` reports surviving mutants, categorise before writing tests:

- **Real gaps**: a code path has no covering test. Add one.
- **Equivalent mutants**: two code forms that truly do the same thing. Refactor the source to eliminate the duplication (e.g. an explicit match arm identical to `default`). Do not add a test that asserts equivalence.
- **Cosmetic constants**: defaulted exception codes (the `0` in `new RuntimeException($msg, 0, $e)`) generate mutation noise without matching real bugs. Use named args (`previous: $e`) or drop the defaulted arguments so there is no literal to mutate.
- **Investigate the code before adjusting tooling.** A surviving or timed-out mutant means some code has no observable effect any test asserts on. Two patterns recur:
  - *Reachable but unexercised behaviour* → add a test. Common with defensive validation where the happy path is covered but the failure path isn't.
  - *Structurally unreachable code* → remove it. Common with defensive `Assert::isMap` calls inside helpers fed by schema-typed input. Widen the helper's input type (`array<string, mixed>` → `array<array-key, mixed>`) and do strict typing at the outermost call site.
- **A timed-out mutant is a defect, not a result.** Infection scores a timeout as a kill, so MSI can read 100% while `T` marks are present. Never report such a run as green, and never raise `infection.json5`'s `timeout` to make one go away. Two causes produce a `T`, and they take opposite fixes. Tell them apart by re-running the file scoped (`mutation:check -- <file>`): cause 1 reproduces there, cause 2 does not.
  - *The mutated path does not terminate*, because something outlives the work it bounds or a loop lost its bound. The worked precedents are [RequestDeadline](src/Client/Dispatch/RequestDeadline.php), where timeouts plus an intermittent whole-suite hang traced to a referenced `EventLoop::delay()` watcher, and the pagination and pipeline walks, where inverting `while (null !== $cursor)` or widening an `array_slice` offset re-requests the same page or middleware forever. When no rewrite makes the mutated form terminate, the fix belongs in the test double: the paged store refuses to serve a page twice and the recording middleware refuses a re-entry, both real invariants.
  - *The mutant escaped the tests and the PHPStan run that killed it was slow.* Infection runs static analysis only on a mutant the test framework already let through, and that process is bound by the same `timeout`. One run costs several seconds and cannot save its result cache, so a full-tree run can cross the limit. Every static-analysis kill is therefore a latent timeout, and which ones surface varies per run. Fix by closing the test gap so the mutant dies before static analysis is reached, or, when the call is a pure narrowing device, by deleting it (see [Runtime validation](#runtime-validation-use-nexusphpassert)).

## Committing

Do **not** commit on the user's behalf. Leave the working tree modified so the user can review diffs and commit themselves.

`composer.lock` is intentionally not committed. Run `composer update` on setup.
