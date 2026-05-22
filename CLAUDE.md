# Claude Code Instructions

This file primes Claude sessions for the Nexus MCP SDK repo. For the general project overview, architecture, and command list, read [.github/copilot-instructions.md](.github/copilot-instructions.md); it is the canonical description of the repo and should not be duplicated here. This file only captures Claude-specific workflow guidance and gotchas learned while working in this codebase.

## Before making changes

- Target PHP 8.4 minimum. Use typed class constants (`public const string FOO = 'x';`), readonly classes, constructor property promotion, `#[\Override]`, asymmetric visibility (`public private(set)`), property hooks, and the `#[\Deprecated]` attribute where they fit.
- The SDK tracks **MCP spec version 2025-11-25 and later**. Do not add back-compat for earlier revisions (e.g. 2025-03-26 batching). The live schema sits at `latest-schema.json`; the class-to-schema map is in `sorted-schema.json`. If either file is missing, run `composer schema:generate` to regenerate them.
- `Core/Schema/` is types-only; behavior (parsers, codecs, registries) lives in sibling namespaces under `Core/`, not under `Core/Schema/`. Prefer editing existing files over introducing new layers.

## Spec compliance

For spec-driven work, do **NOT** introduce types, params, or response shapes that are not in the official spec. Ask before adding extension types. When in doubt, cite the section of `latest-schema.json` (or the upstream [modelcontextprotocol/modelcontextprotocol](https://github.com/modelcontextprotocol/modelcontextprotocol) schema.ts) that justifies the type. PHP-only scaffolding classes (parsers, guards, exceptions) are allowed, but any class meant to represent a JSON-RPC envelope or schema payload must correspond to a spec def.

## Workflow gates

Every change must survive these checks before being considered done:

```bash
composer test:all              # cs + phpstan + doc lints + auto-review + static-analysis + unit + full-tree mutation
# OR (mid-development, with unstaged/untracked changes):
composer test:with-untracked   # same suite, but mutation step is diff-based via `mutation:filter`
```

For fast iteration while coding, the single-concern scripts work: `composer test:core`, `composer test:auto-review`, `composer test:stan` (PHPStan type-inference lock-in assertions under `tests/AutoReview/data/`, run as the `static-analysis` PHPUnit group).

Mutation testing has two modes:

- `composer mutation:filter`: diff-based against `origin/1.x`. Picks up committed, staged, modified-but-unstaged, and untracked files (the script transiently marks untracked files as `--intent-to-add` so `git diff` sees them, clearing on exit). Prefer this during iteration.
- `composer mutation:check`: full tree. Reserve for pre-merge final verification, `infection.json5` changes, or when you suspect cross-file mutation interactions.

Both enforce **100% MSI, 100% Code Coverage, 100% Covered Code MSI** per [infection.json5](infection.json5). Escaped mutants fail the build; do not add ignores, improve the tests instead.

## Doc linters

`composer lint:docs` bundles three linters: **typos** (whole-repo spell check), **markdownlint**, and **lychee** (the last two scoped to `.md` files). Configs: `_typos.toml`, `.markdownlint-cli2.yaml`, `lychee.toml`. Auto-fix via `composer lint:fix` (typos + markdownlint; lychee has no fixer). Native binaries auto-install via Homebrew after `composer update`. Note: `composer lint:typos:fix` rewrites identifiers in source files too, not just docs. Review the diff before staging.

## Conventions worth internalising

### Namespaces and layout

- `Nexus\Mcp\Core\Schema\*`: protocol types only (value objects, enums, interfaces). No behavior.
- Abstract bases sit at the root and concrete subclasses live in same-named subfolders: `Schema/Result.php` + `Schema/Result/EmptyResult.php`, `Schema/Request.php` + `Schema/Request/PingRequest.php`, `Schema/Notification.php` + `Schema/Notification/InitializedNotification.php`, `Schema/RequestParams.php` + `Schema/RequestParams/EmptyRequestParams.php`, `Schema/NotificationParams.php` + `Schema/NotificationParams/EmptyNotificationParams.php`.
- `Nexus\Mcp\Core\JsonRpc\*`: JSON-RPC envelope behavior (parser, guards). The envelope-parsing guard lives here, not under `Schema\`, even though schema classes consume it.
- `Nexus\Mcp\Core\Exception\*`: every SDK exception implements the `McpExceptionInterface` marker so consumers can `catch (McpExceptionInterface $e)`.

### PHPDoc style

- **Prefer native phpdoc tags over `@phpstan-*` variants** across the codebase. Use `@return`, `@param`, `@var`, `@implements`, `@extends`, `@throws`, `@template`, etc. Reach for `@phpstan-*` only when there is no native equivalent (`@phpstan-assert`, `@phpstan-consistent-constructor`, `@phpstan-type`, `@phpstan-import-type`, `@phpstan-ignore`).
- **Class and method docblocks stay concise and behavior-focused.** Describe what the class *is/does* in 1–2 sentences plus structural tags. Do not narrate why the class exists in its current form. Decision history belongs in commits and PRs. The same rule applies to inline comments in data files and fixtures: no "this verifies that we fixed X" headers.

### `@internal`, `@final`, `@no-final`

- **Do** apply the `@internal` phpdoc tag to implementation-detail classes that should not be consumed externally. PHPStan flags external callers that use an `@internal` class, which is exactly what we want.
- **If a class should be final but cannot use the `final` keyword** (typical reason: the class is mocked in tests), add a `@final` phpdoc tag to communicate the intent to PHPStan and humans.
- **If an `@internal` class is explicitly meant to be extended by subclasses in this package**, add `@no-final` so the `final_internal_class` CS fixer does not promote it to `final`.

### Spec mapping

- PHP class names map to spec defs via a basename-normalizer in the schema processor under `tools/`. If a new class's name diverges from the spec's, extend the normalizer rather than renaming the class.
- `$ref` aliases (where one spec def points to another shape) are inlined at load time so aliased schemas expose the full shape for conformance testing.

### Server-side composition

- **Registration lives on `ServerBuilder`, not on `Server`.** Tools, prompts, resources, request and notification handlers, loggers, and request-id factories all register via fluent `ServerBuilder::add*` / `set*` methods before `build()`. After `Server::run(TransportInterface)` is called, no further registration is possible. Don't add convenience setters like `Server::addTool()`. The immutable-after-build shape is intentional.
- **Built-in request handlers depend on exactly one per-feature store** (`ToolStore`, `PromptStore`, `ResourceStore`, etc.), constructor-injected. There is no umbrella registry. When adding a new feature, mirror the per-feature-store shape rather than reaching for a combined `Capability\Registry`.
- **Dispatch is method-name → handler at registration time** (TS-SDK style), not the official PHP-SDK's polymorphic `supports($method)` walk. This is what motivates the phantom `@template-covariant TMethod` on `RequestHandlerInterface` / `NotificationHandlerInterface` (see [Per-method request/notification/result classes](#per-method-requestnotificationresult-classes)).
- **No attribute-driven discovery yet.** `#[AsTool]` / `#[AsResource]` / reflection scanners / `symfony/finder` / PSR-14 event dispatch are explicit non-features. Adding any of them is a milestone of its own and changes the public surface; it doesn't fit incidentally.
- **`ServerBuilder::addRequestHandler(method, handler)` is the power-user escape hatch.** It looks redundant next to the typed per-feature builders, but it's the seam consumers reach for to register handlers for spec methods that have no typed builder. Preserve it.

### Per-method request/notification/result classes

The spec defines methods as named JSON-RPC operations. Each gets a concrete request (and optionally a notification or result) class.

- Each concrete request/notification overrides `static method(): string` to return the JSON-RPC method name as a literal. That accessor is the single source of truth: registry, parser, error messages, and tests all read it. Do not introduce a `JSONRPC_METHOD` constant; the method name is polymorphic per-subclass behavior and belongs in a method, not a constant.
- Pin the literal at the type level. Bases declare `@template-covariant TMethod of non-empty-string`; concrete subclasses must bind it via `@extends Base<'method-name'>`. Without that binding, `method()` widens to `non-empty-string` and the type-inference tests under `tests/AutoReview/data/` will fail.
- When adding a new spec-defined request/notification class, register it in the method registry under `Core/JsonRpc/` so callers get it automatically. User-supplied maps passed to the parser merge over the defaults per-key; callers need only specify overrides or non-default method classes.
- For methods with no typed params, reuse the shared `EmptyRequestParams`/`EmptyNotificationParams` (under `Core/Schema/RequestParams/` and `Core/Schema/NotificationParams/`). Only add a new subclass of the abstract `RequestParams`/`NotificationParams` base when the method carries typed fields beyond `_meta`. Same rule for `Result` subclasses: use `EmptyResult` unless the method carries a typed payload.

### `Arrayable` and the success-response exception

Schema classes implement `Arrayable` (`fromArray()` / `toArray()` / `jsonSerialize()`) for round-trip JSON-RPC envelope construction. The one documented exception is the JSON-RPC success-response wrapper: a success-response payload has no method-name or discriminator in its envelope, so the parser requires caller-supplied context (the expected `Result` subclass) to decode it. That wrapper therefore has no `fromArray()` and is constructed only via the parser's success-response entry point.

When the spec defines two structurally similar shapes that differ only in optional extra fields, model them as peer final classes, not parent/child. Inheritance implies LSP substitutability that doesn't hold when the schemas diverge.

### Runtime validation: use `nexusphp/assert`

[`nexusphp/assert`](https://github.com/NexusPHP/assert) is a production dependency. Reach for it in constructors and `fromArray()` methods instead of inline `is_int`/`is_string`/`sprintf` + `new \InvalidArgumentException(...)`. Its PHPStan type-specifying extension is auto-registered via `phpstan/extension-installer`, so the narrowing lands without extra config.

- `Assert::ExpectationFailedException` extends `\InvalidArgumentException`, so existing `catch (\InvalidArgumentException $e)` wrap-and-rethrow patterns keep working.
- Messages are templates interpolated via `strtr`: `{value}` (value-exported) and `{type}` (produced by `get_debug_type()`). Example: `'JSON-RPC envelope "method" must be a non-empty string, {type} given.'`.
- **String-keyed arrays**: `Assert::that($x)->isArray('… {type} given.')->isMap('… string-keyed object.')`. The two-step chain preserves distinct messages for "not an array" vs. "int-keyed array". A single `isMap` call collapses both into one message.
- **Must-have keys on a known array**: `Assert::that($data)->hasOffset('key', 'missing message.')`. The bundled `isArray` check is redundant on already-typed input but harmless; preferred over a bare `array_key_exists` when you want the message to live next to the other Assert chains.
- **Conditional keys** (`if (\array_key_exists('_meta', $data))`): leave as native PHP; Assert has no "optional key" shape.
- Expectations used in this repo: `isMap`, `hasOffset`, `isArrayKey` (narrows to `int|string`), `isNonEmptyString`, `isInt`, `isString`, and `nullOr()->isX()` for nullable inputs.

### String composition and logging

- **Prefer `\sprintf('%s …', $value)` over concat (`$value.' …'`)** when composing strings with dynamic pieces. The codebase uses `\sprintf` ubiquitously for exception messages, Assert templates, and string-building helpers. Match that style instead of reaching for concat. Simple two-piece literals like `$prefix.': '.$line` are still fine.
- **Logger messages are the exception.** Pass a literal PSR-3 template (`'{label} transport sent {kind}.'`) as the message and the dynamic values via the context array (`['label' => $this->label, 'kind' => self::describe($message)]`). Do not pre-render with `sprintf`. Aggregators index `label`/`kind`/etc. as structured fields, and tests match against the raw template via `ArrayLogger::recordsMatching('{label} transport sent {kind}.')` plus a context-equality assertion.
- The `{type}` and `{value}` tokens in `nexusphp/assert` messages are interpolated by the library at throw time, not by `sprintf`. Leave them as-is inside a `sprintf` template when injecting a class const or computed prefix: `\sprintf('%s command must be a list, {type} given.', self::LABEL)`.

## Static analysis and CS gotchas

These all bit me at least once; note them up-front so you don't relearn:

- **Inline `@var` survives only when it narrows to a valid subtype of the inferred type** (e.g. a union down to one member) and sits directly on an assignment. The CS fixer strips it two ways otherwise: `phpdoc_no_incorrect_var_annotation` removes a `@var` whose type contradicts inference, and `phpdoc_to_comment` rewrites a standalone (non-assignment) docblock to `// @var …` which PHPStan ignores. When `@var` won't hold, or you want a runtime guard at an input boundary, use an `Assert::that(...)->isX()` chain instead (see [Runtime validation](#runtime-validation-use-nexusphpassert)).
- **`is_array()` narrows to `array<mixed, mixed>`**, not `array<string, mixed>`.
- **`@return static` on a method whose PHP return type is `: static`** is flagged as superfluous and stripped. Document the parameter, let the return type speak for itself.
- **Widening `@param` on interface methods violates LSP contravariance when concrete implementations narrow the type.** If you loosen an `Arrayable` implementation's input to `array<string, mixed>`, every sibling class must match or PHPStan flags it. Prefer keeping the generic `@param T` and using `Assert::that($value)->isMap(...)` at the call boundary.
- **`@phpstan-consistent-constructor`** is required on non-final classes that use `new static(...)` in static factory methods.
- **Use the native `: never` return type** on methods that unconditionally throw, even when the interface signature declares a concrete return type. `never` is a bottom type, so narrowing any return type to `never` is LSP-safe. Prefer the native type over `@phpstan-return never`; resort to the phpdoc only when you cannot change the PHP signature (e.g. the method is inherited from a third-party interface that forbids narrowing via some tooling constraint).
- **`final public function __construct`** is contagious. Don't add it unless you are sure subclasses will never need to declare their own constructor.
- **`@phpstan-sealed Foo|Bar|Baz`** on a parent's docblock closes the subtype set. PHPStan narrows through the union: after `instanceof` eliminates N−1 cases in a `match (true)`, use `default =>` for the Nth (writing a redundant `instanceof` arm trips `instanceof.alwaysTrue`). Declaring a new subtype without adding it to the seal list trips `interface.disallowedSubtype`. Use for marker interfaces with a spec-fixed implementation set (e.g. `JsonRpcMessage` closed to request / notification / response). Kills "unreachable default arm" Infection mutants.

## Test patterns

- Tests mirror `src/` layout under `tests/` with namespace `Nexus\Mcp\Tests\`. Test-only fixtures live under `tests/Fixtures/{Core,Client,Server}/` (namespace `Nexus\Mcp\Tests\Fixtures\{Core,Client,Server}`): a single top-level tree so fixtures can be shared across suites without false ownership. Never place fixtures under `src/`.
- Every test class: `final`, `@internal`, attributes `#[CoversClass(Foo::class)]`, `#[Group('unit-tests')]`, `#[Group('core-tests')]` (swap `core` for `client`/`server` as appropriate).
- Data provider methods are named `provide{TestMethodSuffix}Cases`. The CS fixer will rename them if they do not match.
- For happy-path void functions that merely need to "not throw," use `$this->expectNotToPerformAssertions()` rather than `self::assertTrue(true)`; the latter is flagged by PHPStan.
- **PHPStan + PHPUnit stubs narrow `assertInstanceOf`, but intelephense does not.** For nested access after an instance check, use the `if (! $x instanceof Y) { self::fail(...); }` pattern; both tools narrow through it. Native `assert($x instanceof Y)` is also flagged by PHPStan as redundant after the PHPUnit assertion, so avoid it.
- **`assertSame` vs `assertEquals` for value objects**: `assertSame` compares identity (fails for distinct but structurally equal objects). For round-trip tests, compare `$original->toArray()` to `$reconstructed->toArray()`; the CS fixer `php_unit_strict` rule will convert `assertEquals` to `assertSame` and break object comparisons otherwise.
- **Cross-path encoding check on round-trip fixtures**: `AbstractRoundTripTestCase` asserts `json_encode($instance) === json_encode($instance->toArray())` so the `jsonSerialize` and `toArray` paths can't drift. Classes whose `jsonSerialize` substitutes `\stdClass` for an empty object slot must opt out by setting `'encodingPathsDiverge' => true` on their registry entry in `JsonRpcEnvelopeRoundTripTest` / `SchemaPayloadRoundTripTest`. Composition counts: an envelope whose payload contains such a class at any nesting level inherits the flag.
- **Positional string assertions**: for messages produced by concat, use `expectExceptionMessageMatches('/^prefix …/')` with a `^`-anchored regex so that concat-swap and operand-removal mutants are killed. A plain `expectExceptionMessage` does substring matching and misses them.
- **Data providers for invalid-input tests**: to drive a value into a narrower chain (e.g. `Assert::that($x)->isMap()`) whose type-specifying extension fails at the type level for a bad input, accept the value via `mixed $value` from a data provider. PHPStan will not narrow `mixed` and won't flag the test.
- **Type-inference tests (`tests/AutoReview/data/*.php`) target production classes only**: the real spec classes in `src/` and their generics. Do not pad data files with assertions against test fixtures (`tests/Fixtures/...`); fixtures change for scaffolding reasons unrelated to the SDK's public contract. If a generic surface has no concrete production class yet, skip the data file rather than substituting a fixture.
- **`@phpstan-ignore` in tests is a narrow exception**, not a general escape hatch. Valid only when the test deliberately feeds malformed / out-of-contract input to exercise a runtime guard that PHPStan would reject statically before the runtime code ever runs. Otherwise fix the code structurally; the `mixed $value` data-provider pattern above is usually the better alternative.

## Mutation testing tips

When `mutation:check` reports surviving mutants, categorise before writing tests:

- **Real gaps**: a code path has no covering test. Add one.
- **Equivalent mutants**: two code forms that truly do the same thing. Refactor the source to eliminate the duplication (e.g. an explicit match arm that is identical to `default`). Do not add a test that asserts equivalence.
- **Cosmetic constants**: defaulted exception codes (the `0` in `new RuntimeException($msg, 0, $e)`) generate mutation noise without matching real bugs. Use named args (`previous: $e`) or drop the defaulted arguments entirely so there is no literal to mutate.
- **Investigate the code before adjusting tooling.** A surviving or timed-out mutant means some code has no observable effect any test asserts on. Two patterns recur:
  - *Reachable but unexercised behavior* → add a test. Common with defensive validation where the happy path is covered but the failure path isn't.
  - *Structurally unreachable code* → remove it. Common with defensive `Assert::isMap` calls inside helpers fed by schema-typed input; widen the helper's input type (`array<string, mixed>` → `array<array-key, mixed>`) and do strict typing at the outermost call site.

  Bumping `infection.json5`'s `timeout` is a last resort, only justified after confirming the slow mutants don't represent dead/untested code.

## Committing

Do **not** commit on the user's behalf. Leave the working tree modified so the user can review diffs and commit themselves.

`composer.lock` is intentionally not committed; run `composer update` on setup.
