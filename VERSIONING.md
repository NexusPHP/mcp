# Versioning Policy

This document states how `nexusphp/mcp` assigns version numbers, what counts as a breaking change,
and how deprecations and MCP spec revisions are handled. It complements
[DEPENDENCY_POLICY.md](DEPENDENCY_POLICY.md) (PHP floor and dependency cadence), [CHANGELOG.md](CHANGELOG.md)
(the per-release record), and [ROADMAP.md](ROADMAP.md) (release sequencing).

## Scheme

The SDK follows [Semantic Versioning 2.0.0](https://semver.org): `MAJOR.MINOR.PATCH`. What each component
is allowed to carry depends on whether the SDK has reached `1.0.0`.

## Pre-1.0 (the `0.x` line)

Until `1.0.0` the public API is still settling, so the SemVer guarantees are relaxed by one level:

| Bump | Example | May contain |
| --- | --- | --- |
| Minor | `0.2.0` to `0.3.0` | Breaking changes, new features, deprecations, bug fixes |
| Patch | `0.2.0` to `0.2.1` | Backward-compatible bug fixes only |

A `0.x` minor is the breaking-change vehicle before 1.0. Composer's caret operator already reflects this:
`^0.2` resolves to `>=0.2.0 <0.3.0`, so it will **not** silently upgrade you across a minor. Pin with `^0.2`
(or tighter) and read [CHANGELOG.md](CHANGELOG.md) before moving to the next minor.

Through `0.x` the project ships only the single umbrella package `nexusphp/mcp`. The split into
per-component packages is a 1.0-era change. See [ROADMAP.md](ROADMAP.md).

## From 1.0 onward

Standard SemVer applies:

| Bump | Contains |
| --- | --- |
| Major | Breaking changes to the public API |
| Minor | Backward-compatible features and deprecations |
| Patch | Backward-compatible bug fixes |

## Public API surface

SemVer guarantees apply to the **public, supported surface** only:

- **Covered:** non-`@internal` classes, interfaces, enums, and traits under the `Nexus\Mcp\` namespace,
  together with their public and protected members and their documented behaviour.
- **Not covered:** any symbol carrying the `@internal` PHPDoc tag (PHPStan flags external use), private
  members, exact exception **messages** (the exception **types** are covered), the `tools/` project,
  `tests/`, `examples/`, and anything a docblock marks as experimental.

If you depend on an `@internal` class you are outside the compatibility promise, and an update may break you
in any release.

## What counts as a breaking change

Shipped in a major (or a `0.x` minor while pre-1.0):

- Removing or renaming a public class, interface, enum case, method, or property.
- Adding a required parameter, narrowing a parameter type, or incompatibly changing a return type.
- Renaming a public parameter, since PHP 8 named arguments make the name part of the signature.
- Changing observable behaviour or the type of exception a public method throws.
- Raising the PHP version floor (see [DEPENDENCY_POLICY.md](DEPENDENCY_POLICY.md)).
- Removing a previously deprecated symbol, or dropping SDK surface for a feature the spec has removed (see below).

Shipped in a minor (backward-compatible):

- Adding a new class, interface, optional parameter, or method.
- Marking a symbol deprecated without removing it.

## How the promise is enforced

`composer bc:check` compares the public surface of `HEAD` against the latest stable tag with
[roave/backward-compatibility-check](https://github.com/Roave/BackwardCompatibilityCheck), installed as a
sidecar project under `bc/`. Through the `0.x` line, where the table above already permits breaks, the
result is read rather than enforced: [.github/RELEASE.md](.github/RELEASE.md) runs it before every tag to
write [BREAKING_CHANGES.md](BREAKING_CHANGES.md), and the `Backward Compatibility` workflow runs it on
pull requests and on demand. From 1.0 it becomes a blocking check on every change.

One gap in that tool is recorded in
[.roave-backward-compatibility-check.xml](.roave-backward-compatibility-check.xml):

- Static reflection cannot evaluate a `new X()` or enum-case parameter default, so a change to one of
  those defaults is not compared. The baseline names the two skip messages narrowly, so an unrecognised
  skip still fails the run.

## Deprecations

Symbols slated for removal are marked with the `@deprecated` PHPDoc tag, naming the replacement, and
recorded under a `Deprecated` heading in [CHANGELOG.md](CHANGELOG.md). The tag covers every symbol kind
the compatibility promise does (classes, interfaces, enum cases, methods, properties), and
`phpstan/phpstan-deprecation-rules` reports usages statically. Once the PHP floor reaches 8.4, the
native `#[\Deprecated]` attribute is added as a runtime signal where PHP supports it (methods and class
constants), alongside the tag rather than replacing it. A deprecated symbol survives for at least one
subsequent minor before it is removed in the next major. While in `0.x` a deprecation may be removed in the
following minor, so treat every `0.x` deprecation as imminent. This short window covers SDK-originated
deprecations only: a deprecation that mirrors a spec feature follows the spec's longer lifecycle instead
(see the MCP spec revisions section below).

## MCP spec revisions

Each release tracks one MCP spec revision. The current line tracks **2026-07-28**. Adopting a new
revision is not, on its own, a major release. The spec defines a feature lifecycle
([MCP SEP-2596](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/seps/2596-spec-feature-lifecycle-and-deprecation.md)):
a feature is first marked **Deprecated** (with an `@deprecated` schema annotation and a changelog entry) and
stays in the spec for at least twelve months before it becomes eligible for **Removal**. The SDK mirrors
that lifecycle:

- A revision that only adds features or marks deprecations is adopted as a backwards-compatible **minor**.
  The new surface is added, and the SDK symbols for any newly deprecated spec feature are marked `@deprecated`.
- A **major** (or, while in `0.x`, a minor) is reserved for when the SDK drops a removed feature or makes an
  otherwise incompatible change. Per SEP-2596 a spec removal does not oblige the SDK to drop the feature at
  once. The removal timeline is set by this policy, not by the spec's release date.

The SDK does not carry support for revisions before its declared floor. The tracked revision is stated in
[README.md](README.md) and [CHANGELOG.md](CHANGELOG.md) for every release.

## See also

- **[DEPENDENCY_POLICY.md](DEPENDENCY_POLICY.md)**: PHP version floor, runtime dependencies, update cadence.
- **[CHANGELOG.md](CHANGELOG.md)**: the per-release record, including `Deprecated` and breaking-change notes.
- **[ROADMAP.md](ROADMAP.md)**: release sequencing and the path to 1.0.
- **[SECURITY.md](SECURITY.md)**: supported versions for security fixes.
