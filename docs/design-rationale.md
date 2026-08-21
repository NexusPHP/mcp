# Design rationale

This page explains why the SDK is shaped the way it is. The short version: it differentiates on **substance**
(strictness, verified correctness, explicit composition) rather than on surface novelty. If you are evaluating it
against other MCP SDKs, these are the deliberate choices behind it.

## Strict typing and static analysis

The codebase runs PHPStan at **level 10 with strict rules**. Production code carries no `@phpstan-ignore`.
Provably-safe narrowings that PHPStan disputes go to a central, audited baseline, not to scattered inline
suppressions.

Runtime input validation at trust boundaries uses [`nexusphp/assert`](https://github.com/NexusPHP/assert). Its
type-specifying extension feeds the narrowing back into PHPStan. The type information you see is the type
information the analyser enforces.

Type-inference lock-in tests under `tests/AutoReview/data/` pin the generic contracts of the spec classes. A
refactor that widens a return type fails the build.

## Verified correctness, not just coverage

Tests are held to **100% line coverage and 100% Mutation Score Indicator** (MSI), including covered-code MSI,
through Infection. Mutation testing asks a sharper question than coverage. Coverage asks "did a test execute this
line". Mutation testing asks "would any test notice if this line's behaviour changed".

Escaped mutants fail the build, so a passing suite means the assertions pin the behaviour. Infection also runs with
`--static-analysis-tool=phpstan`, so the type system acts as a second mutant killer.

This is why the SDK avoids constructs that generate equivalent mutants, such as defaulted exception codes and
redundant guards. They would be untestable noise against that bar.

## Explicit composition over reflection magic

Servers and clients are assembled through fluent builders, `ServerBuilder` and `ClientBuilder`, with typed
registration methods. Dispatch is a method-name to handler table resolved at registration time. It is not a
polymorphic `supports()` walk, and it is not a runtime reflection scan.

There is no service locator and no static access to shared services. Dependencies are constructor-injected. The
trade is a little more typing at setup, for a surface that an IDE and a static analyser can both follow end to end.

Attribute-based discovery (`#[AsTool]` and friends, registered through `ServerBuilder::register()`) is sugar over
that surface, not a foundational mechanism. It calls the same `add*` and `set*` methods, so the explicit path stays
the substrate and the two compose freely. See [attribute discovery](attribute-discovery.md).

## A transport that is a dumb pipe

The transport contract is intentionally minimal: start, send, close, and the listener setters. It stops at JSON
decode plus a shape check, and hands the protocol layer a raw envelope.

Parsing, request and response correlation, and error-response construction all live in the protocol layer
(`Server` and `Client`), which owns the parser and the pending-request map. This split is shaped around
streamable HTTP, the constraining transport. That is why stdio falls out as the simple case, and why the contract
can gain HTTP without a breaking change. See [docs/transports.md](transports.md).

## Strict spec compliance over SDK convenience

When a choice trades protocol strictness for SDK-side ease, the SDK takes the strict reading. It pushes the cost
onto its own surrounding code, never onto the message contract.

Schema classes are value objects locked to the MCP shape. Internal micro-DRY is declined where it would add an
abstraction layer over byte-identical, spec-fixed structures. Spec-covered edge cases are treated as required, not
optional: failed discovery, malformed envelopes, and out-of-order notifications.

## Empty optional strings are absent

The spec types most optional descriptive strings as `?: string`, so an empty string is technically a legal value.
The SDK normalises that. An optional, human-readable string field treats `""` as absent. That covers a description,
a title, the server `instructions`, a cancellation `reason`, and a progress `message`.

Constructors reject `""` with an `\InvalidArgumentException` rather than store a value that means nothing, so the
object model has exactly one representation for "no value": `null`. The rule holds on the decode boundary too.
`CancelledNotificationParams::fromArray(['reason' => ''])` fails the same way as
`new CancelledNotificationParams(reason: '')`.

This is the one place the SDK is deliberately stricter than the spec. Value-bearing string fields are exempt,
because there an empty string is a real value rather than a missing one. A JSON Schema `default` of `""` keeps its
plain `?string` typing. The SDK's own emitters degrade gracefully instead of surfacing the rejection, so
`ServerContext::reportProgress(..., message: '')` sends no message rather than throw.

## Enforced package boundaries

The codebase is organised into `Core`, `Server`, and `Client`. `Server` and `Client` depend only on `Core`, and
never on each other. That boundary is not just convention. StructArmed enforces it in CI (`composer arch:check`),
and composer-dependency-analyser checks the dependency declarations (`composer deps:check`). The layering is kept
clean now so the package can split into per-component packages cleanly at 1.0.

## Deliberate non-features

Some omissions are choices, not gaps.

### No `ServerInterface` or `ClientInterface`

There is no second implementation to justify the lockstep an interface imposes, and `Client` is not a `Server`.
The builders return concrete types on purpose.

### No umbrella capability registry

Each feature has its own constructor-injected store rather than a combined registry. Adding a feature mirrors an
existing shape instead of growing a god object.

### No sampling, roots, or logging

SEP-2577 deprecated all three, and the spec tells new implementations not to adopt a deprecated feature. A
greenfield SDK carries none of them, rather than ship surface it would have to remove.

### No server-initiated requests

The 2026-07-28 revision replaces the `ServerRequest` union with `InputRequest`. Its members ride an
`InputRequiredResult` payload rather than travel as dispatchable JSON-RPC requests. When
`RequestBoundSender::sendRequest()` rejects an outbound request, that is the finished behaviour, not a stub that
awaits an implementation.

### No back-compat for superseded spec revisions

The SDK tracks one protocol revision at a time. Porting to a new revision is a single coordinated cut with no
compatibility shim, documented in [BREAKING_CHANGES.md](../BREAKING_CHANGES.md).

## See also

- **[Architecture](architecture.md)**: the dispatch kernel and layering in detail.
- **[Best practices](best-practices.md)**: how to work with the grain of these choices.
- **[ROADMAP.md](../ROADMAP.md)**: where the SDK is headed.
- **[TIERING_CHECKLIST.md](../.github/TIERING_CHECKLIST.md)**: the SEP-1730 self-assessment against the tier
  requirements.
