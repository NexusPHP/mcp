# Claude Code Instructions

This file primes Claude sessions for the Nexus MCP SDK repo. For the general project overview, architecture, and command list, read [.github/copilot-instructions.md](.github/copilot-instructions.md); it is the canonical description of the repo and should not be duplicated here. This file only captures Claude-specific workflow guidance and gotchas learned while working in this codebase.

## Before making changes

- Target PHP 8.4 minimum. Use typed class constants (`public const string FOO = 'x';`), readonly classes, constructor property promotion, `#[\Override]`, asymmetric visibility (`public private(set)`), property hooks, and the `#[\Deprecated]` attribute where they fit.
- The SDK tracks **MCP spec version 2025-11-25 and later**. Do not add back-compat for earlier revisions (e.g. 2025-03-26 batching). The live schema sits at [latest-schema.json](latest-schema.json); the class-to-schema map is in [sorted-schema.json](sorted-schema.json). If either file is missing, run `composer schema:generate` to regenerate them.
- `Core/Schema/` is types-only; behavior (parsers, codecs, registries) lives in sibling namespaces under `Core/`, not under `Core/Schema/`. Prefer editing existing files over introducing new layers.

## Spec compliance

For spec-driven work, do **NOT** introduce types, params, or response shapes that are not in the official spec. Ask before adding extension types. When in doubt, cite the section of [latest-schema.json](latest-schema.json) (or the upstream [modelcontextprotocol/modelcontextprotocol](https://github.com/modelcontextprotocol/modelcontextprotocol) schema.ts) that justifies the type. PHP-only scaffolding classes (parsers, guards, exceptions) are allowed, but any class meant to represent a JSON-RPC envelope or schema payload must correspond to a spec def.

## Workflow gates

Every change must survive these checks before being considered done:

```bash
composer test:all              # cs + phpstan + auto-review + static-analysis + unit + full-tree mutation
# OR (mid-development, with unstaged/untracked changes):
composer test:with-untracked   # same suite, but mutation step is diff-based via `mutation:filter`
```

For fast iteration while coding, the single-concern scripts work: `composer test:core`, `composer test:auto-review`, `composer test:stan` (PHPStan type-inference lock-in assertions under `tests/AutoReview/data/`, run as the `static-analysis` PHPUnit group).

Mutation testing has two modes:

- `composer mutation:filter`: diff-based against `origin/1.x`. Picks up committed, staged, modified-but-unstaged, and untracked files (the script transiently marks untracked files as `--intent-to-add` so `git diff` sees them, clearing on exit). Prefer this during iteration.
- `composer mutation:check`: full tree. Reserve for pre-merge final verification, `infection.json5` changes, or when you suspect cross-file mutation interactions.

Both enforce **100% MSI, 100% Code Coverage, 100% Covered Code MSI** per [infection.json5](infection.json5). Escaped mutants fail the build; do not add ignores, improve the tests instead.

## Conventions worth internalizing

### Namespaces and layout

- `Nexus\Mcp\Core\Schema\*`: protocol types only (value objects, enums, interfaces). No behavior.
- Abstract bases sit at the root and concrete subclasses live in same-named subfolders: `Schema/Result.php` + `Schema/Result/EmptyResult.php`, `Schema/Request.php` + `Schema/Request/PingRequest.php`, `Schema/Notification.php` + `Schema/Notification/InitializedNotification.php`, `Schema/RequestParams.php` + `Schema/RequestParams/EmptyRequestParams.php`, `Schema/NotificationParams.php` + `Schema/NotificationParams/EmptyNotificationParams.php`.
- `Nexus\Mcp\Core\JsonRpc\*`: JSON-RPC envelope behavior (parser, guards). The envelope-parsing guard lives here, not under `Schema\`, even though schema classes consume it.
- `Nexus\Mcp\Core\Exception\*`: every SDK exception implements the `McpExceptionInterface` marker so consumers can `catch (McpExceptionInterface $e)`.

### PHPDoc style

Prefer **native phpdoc tags over `@phpstan-*` variants** across the entire codebase, not just on return types. Use `@return`, `@param`, `@var`, `@implements`, `@extends`, `@throws`, `@template`, etc. Reach for a `@phpstan-*` tag only when there is no native equivalent (e.g. `@phpstan-assert`, `@phpstan-consistent-constructor`, `@phpstan-type`, `@phpstan-import-type`, `@phpstan-ignore`). Mixing variants where both exist fragments the docblocks and makes them harder for other static analysers (Psalm, Phan, IDE tooling) to consume.

Keep class and method docblocks **concise and behavior-focused**. A class docblock describes what the class *is/does* in 1–2 sentences plus structural tags (`@internal`, `@template`, `@implements`, `@see` to spec); it does not narrate why the class exists in its current form. Decision history belongs in commits/PRs; code reads as though it was always this way. The same rule applies to inline comments in data files and fixtures: no "this verifies that we fixed X" headers.

### `@internal`, `@final`, `@no-final`

- **Do** apply the `@internal` phpdoc tag to implementation-detail classes that should not be consumed externally. PHPStan flags external callers that use an `@internal` class, which is exactly what we want.
- **If a class should be final but cannot use the `final` keyword** (typical reason: the class is mocked in tests), add a `@final` phpdoc tag to communicate the intent to PHPStan and humans.
- **If an `@internal` class is explicitly meant to be extended by subclasses in this package**, add `@no-final` so the `final_internal_class` CS fixer does not promote it to `final`.

### Spec mapping

- PHP class names map to spec defs via a basename-normalizer in the schema processor under `tools/`. If a new class's name diverges from the spec's, extend the normalizer rather than renaming the class.
- `$ref` aliases (where one spec def points to another shape) are inlined at load time so aliased schemas expose the full shape for conformance testing.

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

## Static analysis and CS gotchas

These all bit me at least once; note them up-front so you don't relearn:

- **Do not use inline `@var`** to narrow types. The CS fixer rule `phpdoc_no_incorrect_var_annotation` will remove it, or `phpdoc_to_comment` will convert it to `// @var …` which PHPStan ignores. Use an `Assert::that(...)->isX()` chain (see [Runtime validation](#runtime-validation-use-nexusphpassert)) for a narrowing that the CS fixer leaves alone.
- **`is_array()` narrows to `array<mixed, mixed>`**, not `array<string, mixed>`.
- **`@return static` on a method whose PHP return type is `: static`** is flagged as superfluous and stripped. Document the parameter, let the return type speak for itself.
- **Widening `@param` on interface methods violates LSP contravariance when concrete implementations narrow the type.** If you loosen an `Arrayable` implementation's input to `array<string, mixed>`, every sibling class must match or PHPStan flags it. Prefer keeping the generic `@param T` and using `Assert::that($value)->isMap(...)` at the call boundary.
- **`@phpstan-consistent-constructor`** is required on non-final classes that use `new static(...)` in static factory methods.
- **Use the native `: never` return type** on methods that unconditionally throw, even when the interface signature declares a concrete return type. `never` is a bottom type, so narrowing any return type to `never` is LSP-safe. Prefer the native type over `@phpstan-return never`; resort to the phpdoc only when you cannot change the PHP signature (e.g. the method is inherited from a third-party interface that forbids narrowing via some tooling constraint).
- **`final public function __construct`** is contagious. Don't add it unless you are sure subclasses will never need to declare their own constructor.
- **`@phpstan-sealed` declares the closed set of subtypes** for an interface or abstract base. Format: `@phpstan-sealed Foo|Bar|Baz` on the parent's docblock. PHPStan narrows through the union: after eliminating N−1 cases via `instanceof` (e.g. in `match (true)`), the Nth case is implied — use `default =>` to let PHPStan narrow structurally instead of writing a redundant final `instanceof` arm that PHPStan flags as `instanceof.alwaysTrue`. If a new subtype is declared without being added to the seal list, PHPStan raises `interface.disallowedSubtype`. Use this when a marker interface has a known-fixed implementation set per the spec (e.g. `JsonRpcMessage` is closed to request / notification / response per JSON-RPC 2.0) — it kills "unreachable default arm" Infection mutants that would otherwise survive.

## Test patterns

- Tests mirror `src/` layout under `tests/` with namespace `Nexus\Mcp\Tests\`. Test-only fixtures live under `tests/Fixtures/{Core,Client,Server}/` (namespace `Nexus\Mcp\Tests\Fixtures\{Core,Client,Server}`): a single top-level tree so fixtures can be shared across suites without false ownership. Never place fixtures under `src/`.
- Every test class: `final`, `@internal`, attributes `#[CoversClass(Foo::class)]`, `#[Group('unit-tests')]`, `#[Group('core-tests')]` (swap `core` for `client`/`server` as appropriate).
- Data provider methods are named `provide{TestMethodSuffix}Cases`. The CS fixer will rename them if they do not match.
- For happy-path void functions that merely need to "not throw," use `$this->expectNotToPerformAssertions()` rather than `self::assertTrue(true)`; the latter is flagged by PHPStan.
- **PHPStan + PHPUnit stubs narrow `assertInstanceOf`, but intelephense does not.** For nested access after an instance check, use the `if (! $x instanceof Y) { self::fail(...); }` pattern; both tools narrow through it. Native `assert($x instanceof Y)` is also flagged by PHPStan as redundant after the PHPUnit assertion, so avoid it.
- **`assertSame` vs `assertEquals` for value objects**: `assertSame` compares identity (fails for distinct but structurally equal objects). For round-trip tests, compare `$original->toArray()` to `$reconstructed->toArray()`; the CS fixer `php_unit_strict` rule will convert `assertEquals` to `assertSame` and break object comparisons otherwise.
- **Positional string assertions**: for messages produced by concat, use `expectExceptionMessageMatches('/^prefix …/')` with a `^`-anchored regex so that concat-swap and operand-removal mutants are killed. A plain `expectExceptionMessage` does substring matching and misses them.
- **Data providers for invalid-input tests**: to drive a value into a narrower chain (e.g. `Assert::that($x)->isMap()`) whose type-specifying extension fails at the type level for a bad input, accept the value via `mixed $value` from a data provider. PHPStan will not narrow `mixed` and won't flag the test.
- **Type-inference tests (`tests/AutoReview/data/*.php`) target production classes only**: the real spec classes in `src/` and their generics. Do not pad data files with assertions against test fixtures (`tests/Fixtures/...`); fixtures change for scaffolding reasons unrelated to the SDK's public contract. If a generic surface has no concrete production class yet, skip the data file rather than substituting a fixture.
- **`@phpstan-ignore` in tests is a narrow exception**, not a general escape hatch. Valid only when the test is deliberately feeding malformed / out-of-contract input to exercise a runtime guard that PHPStan would reject statically before the runtime code ever runs. Any other PHPStan complaint in a test gets fixed structurally the same way as in `src/`. Before adding an ignore, ask: "Is PHPStan refusing the input precisely *because* the input is invalid, and is the test's purpose to verify runtime rejection of that invalid input?" If the answer is no, fix the code. The `mixed $value` data-provider pattern above is usually the better alternative.

## Mutation testing tips

When `mutation:check` reports surviving mutants, categorize before writing tests:

- **Real gaps**: a code path has no covering test. Add one.
- **Equivalent mutants**: two code forms that truly do the same thing. Refactor the source to eliminate the duplication (e.g. an explicit match arm that is identical to `default`). Do not add a test that asserts equivalence.
- **Cosmetic constants**: defaulted exception codes (the `0` in `new RuntimeException($msg, 0, $e)`) generate mutation noise without matching real bugs. Use named args (`previous: $e`) or drop the defaulted arguments entirely so there is no literal to mutate.
- **Investigate the code before adjusting tooling.** A mutant that escapes or times out is the framework telling you that some piece of code has no observable effect any test asserts on. The fix lives in the source or the tests, not in `infection.json5`. Two patterns recur:
  - *Reachable behavior, no test exercises it* → add the test. Common with defensive validation (`Assert::that(...)->isX(...)`, guard clauses, error-message branches) where the happy path is well-covered but the failure path was never asserted on.
  - *Structurally unreachable code* → remove it. Common with defensive paranoia inside helpers that callers can only feed valid input through (e.g. an `Assert::isMap` inside a recursive walker fed by a schema-typed `toArray()`). Adjust types at the helper boundary so static analysis still narrows: usually means widening the helper's input type (`array<string, mixed>` → `array<array-key, mixed>`) and doing the strict-typing work at the outermost call site.

  Bumping `infection.json5`'s `timeout` is a last resort, only justified after you've confirmed the slow mutants don't represent dead/untested code.

## Committing

Do **not** commit on the user's behalf. Leave the working tree modified so the user can review diffs and commit themselves.

`composer.lock` is intentionally not committed; run `composer update` on setup.
