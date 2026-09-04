# Roadmap

This document is the SDK's forward plan: what is queued for the next releases, and how the package
tracks MCP spec revisions and PHP language versions.

It carries no history. For what already ships, see [docs/architecture.md](docs/architecture.md) and the
[docs index](docs/README.md). For what changed in a given release, see
[CHANGELOG.md](CHANGELOG.md), and for porting between revisions, [BREAKING_CHANGES.md](BREAKING_CHANGES.md).

The SDK targets MCP spec **2026-07-28** and is published on Packagist as `nexusphp/mcp` plus four
component packages, stable since `1.0.0`.

## Official extensions

The three official extensions ship enabled-by-opt-in per the extensions framework (SEP-2133). One
follow-up remains.

- [ ] **`notifications/tasks` delivered via `subscriptions/listen`.** SEP-2663 makes these
  notifications optional, so the polling-only `TaskClient` loop is conformant on its own. The upstream
  conformance scenario is a placeholder pending a harness rewrite against the subscriptions channel,
  and that rewrite is the trigger to pick this up. Settling the loop onto push updates starts with the
  SEP's opt-in shape for the listen filter, which `SubscriptionFilter` has no slot for.

DPoP (SEP-1932) and workload identity federation (SEP-1933) are open proposals upstream and stay
unmodelled until ratified.

## Language compatibility

The SDK targets **PHP 8.3** minimum, the oldest release still receiving security fixes, and uses the
language features available in it (typed class constants, readonly classes, constructor property
promotion, `#[\Override]`).

The floor tracks the [supported-versions calendar](https://www.php.net/supported-versions.php) at the
SDK's own discretion. Expect at least one major release per PHP minor that drops EOL versions.

- [ ] **Raise the floor to PHP 8.4.** Unlocks asymmetric visibility, property hooks, `#[\Deprecated]`,
  `new Foo()->bar()` without parentheses, and the `array_find` / `array_any` / `array_all` family. It
  also makes PHPUnit 13 the floor (it requires 8.4.1), which retires three pieces of scaffolding: the
  dual declaration in `tests/AbstractMcpTestCase.php` that supplies `expectExceptionMessageIs()` on
  PHPUnit 12, that file's `excludePaths.analyse` entry in `phpstan.dist.neon`, and the 8.3 leg of the
  CI matrix.
- [ ] **Raise the floor to PHP 8.5.** Brings covariant `static` return types for factory methods.
- [ ] **Relax `Arrayable::fromArray(): static` to `: self`** after the 8.5 bump, so final
  implementations may narrow their return types. Until then the floor strictly enforces `: static`
  invariance and the contract stays as it is.

## Ecosystem standing

Tracked, but not engineering work in this repository.

- **Server conformance ceiling.** Four `input-required` scenarios require the server to emit the half of
  the `InputRequest` union the 2026-07-28 revision deprecates (SEP-2577), which a new implementation must
  not adopt. Baselined with rationale in `conformance/expected-failures.yaml` and raised upstream as
  [conformance#439](https://github.com/modelcontextprotocol/conformance/issues/439). Until it lands, the
  server score caps at 33/37 with nothing actually missing.
- **Documentation scoring.** The SEP-1730 canonical feature list is evaluated against the union of all
  spec revisions rather than the revision an SDK targets, so features this SDK correctly omits (removed
  or deprecated by 2026-07-28) score as undocumented. No feature the SDK ships is undocumented, and no
  documented feature lacks an example. Raised upstream as
  [conformance#441](https://github.com/modelcontextprotocol/conformance/issues/441). Until it lands,
  the docs score caps below full marks with nothing actually missing.
- **Issue triage track record.** The label taxonomy and process scaffolding are in place. Demonstrating
  a triage rate requires real issue traffic, so this accrues with adoption rather than with a change.

## See also

- **[Getting started](docs/getting-started.md)**: install + minimal server.
- **[Server API](docs/server.md)**: builder reference.
- **[Client API](docs/client.md)**: client builder + typed request reference.
- **[Transports](docs/transports.md)**: stdio contract + HTTP planning.
- **[Architecture](docs/architecture.md)**: dispatch kernel and layering. **[Spec compliance](docs/spec-compliance.md)**: coverage against the targeted revision.
- **[Tiering checklist](.github/TIERING_CHECKLIST.md)**: the SEP-1730 assessment, including the features the targeted revision removed.
