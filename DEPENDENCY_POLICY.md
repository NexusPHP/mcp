# Dependency and Version Support Policy

This document states how `nexusphp/mcp-sdk` manages its PHP version floor, its runtime and development
dependencies, and the cadence of security and version updates. It complements [SECURITY.md](SECURITY.md)
(vulnerability reporting) and [ROADMAP.md](ROADMAP.md) (release sequencing).

## PHP version support

The SDK targets a single minimum PHP version and uses the language features available in it (typed class
constants, readonly classes, constructor property promotion, asymmetric visibility, property hooks,
`#[\Override]`).

| PHP version | Status |
| --- | --- |
| 8.4 | Supported (current minimum) |
| 8.5 | Planned. The floor moves to 8.5 in a future release, after which `Arrayable::fromArray()` relaxes from `: static` to `: self` |
| 8.3 and earlier | Not supported |

The floor tracks the [PHP supported-versions calendar](https://www.php.net/supported-versions.php) at the
maintainers' discretion. Expect at least one major SDK release per PHP minor that drops an end-of-life
version. Raising the floor is a breaking change and ships in a major version (or, while in `0.x`, a minor,
per the [pre-1.0 breaking-changes policy](VERSIONING.md)).

## Runtime dependencies

Production dependencies are kept deliberately small and are declared with caret constraints in
[composer.json](composer.json). They fall into three groups:

- **Async substrate:** `revolt/event-loop`, `amphp/amp`, `amphp/byte-stream`, `amphp/process`.
- **Validation and contracts:** `nexusphp/assert`, `opis/json-schema`, `psr/log`.
- **Attribute discovery:** `phpstan/phpdoc-parser` (derives tool input schemas from method signatures and docblocks).
- **HTTP (added with the Streamable HTTP transport):** `psr/http-message`, `psr/http-factory`,
  `psr/http-server-handler`.

`composer.lock` is intentionally not committed. Run `composer update` on setup. Development tooling
(PHP-CS-Fixer, Infection, StructArmed, composer-dependency-analyser) lives in a separate `tools/`
project so it never enters the SDK's own dependency graph. Shadow and unused dependencies are caught in
CI by `composer deps:check` (shipmonk/composer-dependency-analyser).

## Update cadence

- **Security patches.** Security fixes in a dependency are picked up as soon as they are released and
  shipped in the next patch (or minor, while in `0.x`). Report vulnerabilities in the SDK itself through
  [SECURITY.md](SECURITY.md). `composer audit` runs in CI to surface advisories.
- **Minor and patch updates.** Routine dependency updates are batched through Dependabot
  ([.github/dependabot.yml](.github/dependabot.yml)) and merged once the full gate suite passes.
- **Major updates.** Major bumps of a runtime dependency are evaluated for breaking changes, land in an
  SDK minor while in `0.x` (or a major from 1.0 onward), and are noted in [CHANGELOG.md](CHANGELOG.md).

## End-of-life

While in `0.x`, only the latest minor receives fixes. There are no long-term-support branches before
1.0. From 1.0 onward, the supported-release window is defined alongside the 1.0 release. An SDK release
stops receiving updates once a newer release supersedes it under these rules.

## See also

- **[SECURITY.md](SECURITY.md)**: reporting a vulnerability.
- **[ROADMAP.md](ROADMAP.md)**: release sequencing and the PHP-version trajectory.
- **[CONTRIBUTING.md](CONTRIBUTING.md)**: development setup and the gate suite.
- **[VERSIONING.md](VERSIONING.md)**: the versioning scheme and what counts as a breaking change.
