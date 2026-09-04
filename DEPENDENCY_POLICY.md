# Dependency and Version Support Policy

This document states how `nexusphp/mcp` manages its PHP version floor, its runtime and development
dependencies, and the cadence of security and version updates. It complements [SECURITY.md](SECURITY.md)
(vulnerability reporting) and [ROADMAP.md](ROADMAP.md) (release sequencing).

## PHP version support

The SDK targets a single minimum PHP version and uses the language features available in it (typed class
constants, readonly classes, constructor property promotion, `#[\Override]`).

| PHP version | Status |
| --- | --- |
| 8.3 | Supported (current minimum) |
| 8.4 | Supported |
| 8.5 | Supported. The floor moves to 8.5 in a future release, after which `Arrayable::fromArray()` relaxes from `: static` to `: self` |
| 8.2 and earlier | Not supported |

The floor is the oldest PHP still receiving security fixes, so the SDK installs into anything that is
itself still supported. It tracks the
[PHP supported-versions calendar](https://www.php.net/supported-versions.php) at the maintainers'
discretion, and expect at least one major SDK release per PHP minor that drops an end-of-life version.
Raising the floor is a breaking change and ships in a major version (see [VERSIONING.md](VERSIONING.md)).
Lowering it is not, so it may land in any release.

## Runtime dependencies

Production dependencies are kept deliberately small and are declared with caret constraints in
[composer.json](composer.json). They fall into three groups:

- **Async substrate:** `revolt/event-loop`, `amphp/amp`, `amphp/byte-stream`, `amphp/process`.
- **Validation and contracts:** `nexusphp/assert`, `opis/json-schema`, `psr/log`.
- **Attribute discovery:** `phpstan/phpdoc-parser` (derives tool input schemas from method signatures and docblocks).
- **HTTP:** `psr/http-message`, `psr/http-factory`, `psr/http-server-handler` (the Streamable HTTP
  transport).

`composer.lock` is intentionally not committed. Run `composer update` on setup. Development tooling
(PHP-CS-Fixer, Infection, StructArmed, composer-dependency-analyser) lives in a separate `tools/`
project so it never enters the SDK's own dependency graph. Shadow and unused dependencies are caught in
CI by `composer deps:check` (shipmonk/composer-dependency-analyser), which runs once against the umbrella
manifest and once per component manifest under `src/`. Each component declares only the subset its own tree
uses, at the umbrella's constraints, and `ComponentManifestTest` refuses any divergence between the five.

## Update cadence

- **Security patches.** Security fixes in a dependency are picked up as soon as they are released and
  shipped in the next patch. Report vulnerabilities in the SDK itself through
  [SECURITY.md](SECURITY.md). `composer audit --abandoned=report` runs in CI to surface advisories.
- **Minor and patch updates.** Routine dependency updates are batched through Dependabot
  ([.github/dependabot.yml](.github/dependabot.yml)) and merged once the full gate suite passes.
- **Major updates.** Major bumps of a runtime dependency are evaluated for breaking changes, land in an
  SDK major, and are noted in [CHANGELOG.md](CHANGELOG.md).

## End-of-life

Fixes land on the latest minor of the current major, and when a new major ships, the previous major
receives security fixes for six months after its successor's first stable release. An SDK release stops
receiving updates once a newer release supersedes it under these rules. The `0.x` line, which had no
long-term-support branches, receives none.

## See also

- **[SECURITY.md](SECURITY.md)**: reporting a vulnerability.
- **[ROADMAP.md](ROADMAP.md)**: release sequencing and the PHP-version trajectory.
- **[CONTRIBUTING.md](CONTRIBUTING.md)**: development setup and the gate suite.
- **[VERSIONING.md](VERSIONING.md)**: the versioning scheme and what counts as a breaking change.
