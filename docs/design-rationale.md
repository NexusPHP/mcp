# Design rationale

This page explains why the SDK is shaped the way it is. The short version: it differentiates on
**substance** (strictness, verified correctness, explicit composition) rather than on surface novelty. If
you are evaluating it against other MCP SDKs, these are the deliberate choices behind it.

## Strict typing and static analysis

The codebase runs PHPStan at **level 10 with strict rules**, and production code carries no
`@phpstan-ignore`. Provably-safe narrowings that PHPStan disputes go to a central, audited baseline rather
than scattered inline suppressions. Runtime input validation at trust boundaries uses
[`nexusphp/assert`](https://github.com/NexusPHP/assert), whose type-specifying extension feeds the
narrowing back into PHPStan. The result is that the type information you see is the type information the
analyser enforces.

## Verified correctness, not just coverage

Tests are held to **100% line coverage and 100% Mutation Score Indicator** (MSI), including covered-code
MSI, via Infection. Mutation testing asks a sharper question than coverage: not "did a test execute this
line" but "would any test notice if this line's behaviour changed". Escaped mutants fail the build, so a
passing suite means the assertions actually pin the behaviour. This is why the SDK avoids constructs that
generate equivalent mutants (defaulted exception codes, redundant guards): they would be untestable noise
against that bar.

## Explicit composition over reflection magic

Servers and clients are assembled through fluent builders (`ServerBuilder`, `ClientBuilder`) with typed
registration methods. Dispatch is a method-name to handler table resolved at registration time, not a
polymorphic `supports()` walk or a runtime reflection scan. There is no service locator and no static
access to shared services. Dependencies are constructor-injected. The trade is a little more typing at
setup for a surface that an IDE and a static analyser can both follow end to end.

Attribute-based discovery (`#[AsTool]` and friends) is a deliberate, sequenced addition rather than a
foundational mechanism, so the explicit path stays the substrate it builds on. See [ROADMAP.md](../ROADMAP.md).

## A transport that is a dumb pipe

The transport contract is intentionally minimal: start, send, close, and listener setters.
It stops at JSON decode plus a shape check and hands the protocol layer a raw envelope. Parsing,
request/response correlation, and error-response construction all live in the protocol layer (`Server` /
`Client`), which owns the parser and the pending-request map. This split is shaped around streamable HTTP
(the constraining transport), which is why stdio falls out as the simple case and why the contract can
gain HTTP without a breaking change. See [docs/transports.md](transports.md).

## Strict spec compliance over SDK convenience

When a choice trades protocol strictness for SDK-side ease, the SDK takes the strict reading and pushes
the cost onto its own surrounding code, never onto the message contract. Schema classes are value objects
locked to the MCP shape. Internal micro-DRY is declined where it would add an abstraction layer over
byte-identical, spec-fixed structures. Spec-covered edge cases (failed handshake, malformed envelopes,
out-of-order notifications) are treated as required, not optional.

## Empty optional strings are absent

The spec types most optional descriptive strings as `?: string`, so an empty string is technically a legal
value. The SDK normalises that: an optional, human-readable string field (a description, a title,
server `instructions`, a cancellation `reason`, a progress `message`) treats `""` as absent. Constructors
reject it with an `\InvalidArgumentException` rather than storing a value that means nothing, so the object
model has exactly one representation for "no value": `null`. The rule holds on the decode boundary too, so
`CancelledNotificationParams::fromArray(['reason' => ''])` fails the same way as
`new CancelledNotificationParams(reason: '')`.

This is the one place the SDK is deliberately stricter than the spec. Value-bearing string fields are
exempt, because there an empty string is a real value rather than a missing one: a JSON Schema `default`
of `""` keeps its plain `?string` typing. The SDK's own emitters degrade gracefully instead of surfacing
the rejection, so `ServerContext::reportProgress(..., message: '')` sends no message rather than throwing.

## Enforced package boundaries

The codebase is organised into `Core`, `Server`, and `Client`, with `Server` and `Client` depending only
on `Core` and never on each other. That boundary is not just convention: it is enforced in CI by
StructArmed (`composer arch:check`), and dependency declarations are checked by composer-dependency-analyser
(`composer deps:check`). The layering is kept clean now so the package can split into per-component
packages cleanly at 1.0.

## Deliberate non-features

Some omissions are choices, not gaps:

- **No one-method `ServerInterface` or wide `ClientInterface`.** There is no second implementation to
  justify the lockstep an interface imposes, and `Client` is not a `Server`. The builders return concrete
  types on purpose.
- **No umbrella capability registry.** Each feature has its own constructor-injected store rather than a
  combined registry, so adding a feature mirrors an existing shape instead of growing a god object.
- **No back-compat for superseded spec revisions.** The SDK tracks one protocol revision at a time. The
  2026-07-28 migration is a single coordinated cut with no compatibility shim, because carrying two
  protocols permanently would cost more than the one-time port (see [ROADMAP.md](../ROADMAP.md)).

## See also

- **[Architecture](architecture.md)**: the dispatch kernel and layering in detail.
- **[Best practices](best-practices.md)**: how to work with the grain of these choices.
- **[ROADMAP.md](../ROADMAP.md)**: where the SDK is headed, including the SDK tiering target.
