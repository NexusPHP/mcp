# PHP SDK Tiering System Checklist

Based on [SEP-1730: SDKs Tiering System](https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1730)

**Target Tier**: Tier 2, self-assessed (assignment currently runs for official SDKs only, per "How tiering works" below)

**Target spec**: `2026-07-28`, which the SDK implements exclusively.

**Last Updated**: 2026-09-04

---

## How tiering works (SEP-1730)

The published governance page ([`docs/community/sdk-tiers.mdx`](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/docs/community/sdk-tiers.mdx)) covers **both official and community-driven SDKs**, and tiers are **request-based**: self-assess, open an issue in `modelcontextprotocol/modelcontextprotocol` with supporting evidence, pass the automated conformance suite, then the SDK Working Group approves and makes the final assignment. Assignment practice has not caught up with that page: the one community application on record ([mcp#2814](https://github.com/modelcontextprotocol/modelcontextprotocol/issues/2814)) was declined with "at this time, the tiering only applies to official MCP SDKs that have broader community adoption", consistent with the page's own phased key dates (official SDK tiering published first, no date for community SDKs). Until community applications open, this checklist is a self-assessment, and the step before applying at a stable release is asking the working group whether they have.

- **Conformance is the gate.** Scored against **applicable required tests only**: the spec version the SDK targets, excluding pending/skipped tests, experimental-feature tests, and legacy back-compat tests (unless legacy support is claimed). The `tier-check` tool diverges from this wording in several places: see [Drift](#drift-sep-mandates-vs-the-conformance-repo).
- **Relegation.** An SDK drops a tier if conformance tests on its latest stable release fail continuously for 4 weeks. Not implemented by the tool, which is a point-in-time scorer.
- **Pre-1.0, only Tier 3 is claimable** (no stable-release or conformance minimum). Tier 2 needs a stable 1.0-class release plus 80% conformance, both met since `v1.0.0`. The assignment waits on community applications opening.

---

## Where we stand

This is the single source of truth for measured standing. Every requirement below links here rather than restating a number.

**Referee**: `@modelcontextprotocol/conformance@0.2.0-alpha.11`, pinned in [`conformance/run-server.sh`](../conformance/run-server.sh) and [`conformance/run-client.sh`](../conformance/run-client.sh). **Spec**: `2026-07-28`.

Two measurements answer different questions, and conflating them is what makes tier numbers look inconsistent:

| | Suite | Server | Client | What it answers |
| --- | --- | --- | --- | --- |
| **Tier score** | referee default (`active`) | 20/20 scenarios | 15/15 scenarios | What `tier-check` counts toward the tier percentage |
| **Full sweep** | `--suite all` | 38/50 scenarios, 180/192 checks | 36/39 scenarios, 358/367 checks | What this SDK's own CI gates on |

Full-sweep totals: **74/89 scenarios, 538/559 checks (96.2%)**, split 486/490 spec checks and 52/69 extension checks, with 0 unmet SHOULD checks and 14 skipped (excluded from the denominator).

Both tier-scored legs pass outright at 100%. The 15 failing full-sweep scenarios are the fifteen entries in [`conformance/expected-failures.yaml`](../conformance/expected-failures.yaml), none of which sit in the `active` suite, so they do not bear on the tier score. The three client entries are the DPoP and WIF scenarios, whose SEPs are open proposals.

Four need the server to emit an `InputRequest` for `sampling/createMessage` or `roots/list`, both of which `latest-schema.ts` marks `@deprecated` as of 2026-07-28 (SEP-2577). The upstream resolution is tracked at [conformance#439](https://github.com/modelcontextprotocol/conformance/issues/439).

The other eight are the `wire-schema-valid` check the referee validates each sent message with. A task-supporting `tools/call` answers with SEP-2663's `CreateTaskResult`, and the base 2026-07-28 schema defines no task shape, so the check reads that response as a `CallToolResult` and reports the missing `content`. The referee's own `tasks-per-request-meta-opt-in` passes on the same message for the opposite reason, and its `requirements/2026-07-28.yaml` marks every tasks scenario `not_scored` as `pending against the reference fixture`, so nothing on this side retires them. The upstream resolution is tracked at [conformance#424](https://github.com/modelcontextprotocol/conformance/issues/424).

Reproduce with `composer conformance:server`, `composer conformance:client`, then `composer conformance:score`. The referee exits non-zero on an unlisted failure and on a stale baseline entry, so the list burns down rather than rotting.

**Tier verdict**: **Tier 2**. `Stable Release`, the single check `tier-check` failed before `v1.0.0`, is met by that tag, and conformance, labels (12 of 12), triage, and P0 resolution all pass. Re-run `tier-check` at the assessment to record the tool's verdict. `Policy Signals` reports partial because this repository keeps `DEPENDENCY_POLICY.md`, `ROADMAP.md`, `BREAKING_CHANGES.md`, and `VERSIONING.md` at the root rather than under `docs/`, and uses `.github/dependabot.yml` in place of a Renovate config. The partial is accepted: every document the check wants exists and is current, root placement is where GitHub and consumers look for policy files, and dependency tooling is not chosen to satisfy a scorer's proxy. Do not add `docs/` pointer stubs or a Renovate config for this signal.

---

## Tier 3: Experimental

> Early-stage or specialized SDKs exploring the protocol space.

- [x] **SDK Implementation Started**
  - Reference: Basic MCP protocol support available
  - Notes: full server and client against MCP 2026-07-28 over stdio and Streamable HTTP (tools, prompts, resources incl. RFC 6570 templates, completions, subscriptions, the input-required flow), plus the three official extensions

---

## Tier 2: Commitment to be Fully Supported

> Active implementations working toward full protocol support.

### Feature Completeness

- [x] **80% Conformance Tests Pass**
  - Reference: [Conformance Test Suite](https://github.com/modelcontextprotocol/conformance)
  - Threshold: 80% of the tier-scored set. Both legs pass at 100%: see [Where we stand](#where-we-stand)
  - Run: `composer conformance:server` then `composer conformance:score`

### Implementation Timeline

- [x] **New Protocol Features Within 6 Months**
  - Reference: SEP-1730 Tier 2 requirement
  - Implemented: `2026-07-28`, the current revision, on both transports
  - Evidence/Notes: the dated tag published 2026-07-28 and the migration shipped in v0.6.0 on 2026-08-03

### Maintenance & Issue Management

- [ ] **Issue Triage Within a Month**
  - Reference: SEP-1730 Tier 2 requirement (triage = label + validity assessment, not resolution)
  - Evidence/Notes: 12 of 12 required labels present. No issues filed yet, so the metric passes over an empty set and demonstrates no track record

- [ ] **Critical Bug (P0) Resolution Within Two Weeks**
  - Reference: SEP-1730 Tier 2 requirement
  - Evidence/Notes: no P0 issues filed. Passes vacuously, same as above

- [x] **At Least One Stable Release**
  - Reference: Published release with stable API
  - Evidence/Notes: v1.0.0 on the 2026-07-28 revision, SemVer from that tag onward per [VERSIONING.md](../VERSIONING.md)
  - Release Tag: v1.0.0, preceded by v0.1.0 through v0.16.0 on Packagist

### Documentation

- [x] **Basic Documentation for Core Features**
  - Reference: README, getting started guide
  - Evidence/Notes: README.md plus docs/getting-started.md, docs/server.md, docs/client.md, docs/transports.md, docs/architecture.md

- [x] **Published Dependency Update Policy**
  - Reference: Document in repository (e.g., DEPENDENCY_POLICY.md)
  - Location: DEPENDENCY_POLICY.md (plus .github/dependabot.yml automation)
  - Covers: Security patches, minor updates, major updates, EOL policy, PHP version matrix

### Commitment & Roadmap

- [x] **Published Plan Toward Tier 1 (or explanation for remaining Tier 2)**
  - Reference: SEP-1730 Tier 2 requirement. ROADMAP.md or a GitHub project board
  - Location: [ROADMAP.md](../ROADMAP.md)

---

## Tier 1: Fully Supported

> SDKs in this tier provide full protocol implementation and are well supported.

### Feature Completeness

- [x] **100% Conformance Tests Pass**
  - Reference: [Conformance Test Suite](https://github.com/modelcontextprotocol/conformance)
  - Standing: both tier-scored legs at 100%: see [Where we stand](#where-we-stand)

- [x] **Conformance example server + client**
  - Reference: not a standalone SEP-1730 requirement. This is the harness behind the conformance score. Mirrors the canonical fixtures per [SDK_INTEGRATION.md](https://github.com/modelcontextprotocol/conformance/blob/main/SDK_INTEGRATION.md)
  - Location: `conformance/server.php` + `conformance/client.php`
  - Evidence/Notes: `conformance/EverythingServer.php` registers every server capability through attribute discovery, `conformance/client.php` is a scenario-name to closure registry covering the client leg, and `conformance/README.md` documents the run and bump procedure. Both halves key on the harness scenario names, matching the upstream [`everything-client.ts`](https://github.com/modelcontextprotocol/conformance/blob/main/examples/clients/typescript/everything-client.ts) registry shape

### Implementation Timeline

- [x] **New Protocol Features Before Spec Release**
  - Reference: SEP-1730 Tier 1 requirement, "before the new spec version release" (timeline agreed per release by feature complexity, not a fixed window)
  - Evidence/Notes: Tier 1 requires building against the RC rather than waiting for the final tag. The 2026-07-28 RC-to-final window ran about 9 weeks (RC tag 2026-05-29, dated tag 2026-07-28), and this SDK built against the RC

### Maintenance & Support

- [ ] **Issue Triage Within 2 Business Days**
  - Reference: SEP-1730 Tier 1 requirement
  - Evidence/Notes: label taxonomy in place. Demonstrating a rate requires real issue traffic, so this accrues with adoption

- [ ] **Security & Critical Bug Resolution Within 7 Days**
  - Reference: SEP-1730 Tier 1 requirement
  - Security Policy File: SECURITY.md
  - Evidence/Notes: SECURITY.md commits to acknowledgement within 3 business days and an assessment with a fix plan within 7 days. The Tier 1 7-day *resolution* SLA is deliberately not committed

- [x] **Stable Release & Versioning Clearly Documented**
  - Reference: Published versioning policy
  - Location/File: VERSIONING.md (SemVer scheme, breaking-change definition, deprecation path, spec-revision tracking), BREAKING_CHANGES.md (the port guide), ROADMAP.md (release sequencing)
  - Evidence/Notes: policy published and the stable release v1.0.0 tagged under it

- [x] **Published Roadmap**
  - Reference: SEP-1730 Tier 1 requirement
  - Location: [ROADMAP.md](../ROADMAP.md)

### Documentation

- [ ] **Comprehensive Documentation with Examples for All Features**
  - Reference: API docs, guides, tutorials
  - Coverage:
    - [x] Server implementation guide (docs/server.md)
    - [x] Client implementation guide (docs/client.md)
    - [x] Tool registration & handling (docs/server.md)
    - [x] Resource management (docs/server.md)
    - [x] Prompt handling (docs/server.md)
    - [x] OAuth/Authentication flows (docs/authorization.md plus the docs/auth/ pages)
    - [x] Transport configuration (docs/transports.md)
    - [x] Error handling (docs/error-handling.md)
    - [x] Best practices (docs/best-practices.md)
  - Evidence/Notes: scores 37 of 48 on the SEP-1730 canonical feature list. All 11 misses are features the SDK deliberately does not implement because 2026-07-28 removed or deprecated them, each named with its SEP in the table below. No feature the SDK ships is undocumented, and no documented feature lacks an example. The scoring model is raised upstream at [conformance#441](https://github.com/modelcontextprotocol/conformance/issues/441)
  - The 11 misses (canonical-list row, why absent, what replaces it):

    | # | Feature | Why absent, and the replacement |
    | --- | --- | --- |
    | 14 | Resources: subscribing | `resources/subscribe` removed by SEP-2575. The filter-based `subscriptions/listen` (docs/server/subscriptions.md) replaces it. |
    | 15 | Resources: unsubscribing | Removed with row 14. A client closes the stream instead (docs/client/subscriptions.md). |
    | 23 | Sampling | Deprecated by SEP-2577. See deliberate non-features in docs/design-rationale.md. |
    | 29 | Elicitation: complete notification | `notifications/elicitation/complete` was removed from the 2026-07-28 revision before its final tag and is absent from its schema. |
    | 30 | Roots: listing | Deprecated by SEP-2577. See deliberate non-features in docs/design-rationale.md. |
    | 31 | Roots: change notifications | Deprecated with row 30. |
    | 32 | Logging: log messages | `notifications/message` deprecated by SEP-2577. The SDK logs to PSR-3 instead (docs/server/configuration.md). |
    | 33 | Logging: setting level | `logging/setLevel` removed outright by SEP-2575. Its per-request replacement, the `io.modelcontextprotocol/logLevel` `_meta` field, is parsed and exposed to handlers (docs/server/context.md). |
    | 36 | Ping | Removed by SEP-2575 with the session it kept alive. The protocol is stateless. |
    | 39 | SSE transport, legacy (client) | The HTTP+SSE transport was deprecated in 2025-03-26 and is absent from the targeted revision. |
    | 40 | SSE transport, legacy (server) | Absent with row 39. |

- [x] **Published Dependency Update Policy**
  - Reference: SEP-1730 requirement
  - Location/File: DEPENDENCY_POLICY.md
  - Covers:
    - [x] Security patch timeline
    - [x] Minor version update policy
    - [x] Major version update policy
    - [x] End-of-life policy for old versions
    - [x] PHP version support matrix

### Quality Standards (internal gates, not SEP-1730 requirements)

- [x] **Static Analysis Passing (PHPStan Level 10)**
  - Command: `composer phpstan:check`
  - Notes: level 10 + strict rules, enforced in CI

- [x] **Code Style Compliance**
  - Command: `composer cs:check`
  - Notes: PHP-CS-Fixer, Nexus83 preset from Nexus CS Config, enforced in CI

- [x] **Full Test Coverage**
  - Command: `composer test:with-untracked`
  - Notes: 100% MSI, 100% covered-code MSI, 100% line coverage (infection.json5)

---

## Supporting Documentation

### Architecture & Code Quality

- [x] **CONTRIBUTING.md Updated**
  - Covers: Development setup, testing, code standards, PR process

- [x] **CODE_OF_CONDUCT.md Present**
  - Evidence/Notes: Contributor Covenant 2.1

- [x] **Proper Exception Handling**
  - Reference: every SDK exception implements the `Nexus\Mcp\Core\Exception\McpExceptionInterface` marker, so consumers can `catch (McpExceptionInterface $e)`
    - [x] `Nexus\Mcp\Core\Exception\*`
    - [x] `Nexus\Mcp\Server\Exception\*`
    - [x] `Nexus\Mcp\Client\Exception\*`
  - Evidence/Notes: marker enforced. PHPStan flags external use of @internal exceptions

### Release Management

- [x] **Changelog Maintained (CHANGELOG.md)**
  - Format: Keep a Changelog, semantic-versioning compatible

- [x] **Version Number Consistency**
  - Location of version definition: git tag (composer.json has no version field)
  - Current Version: v1.0.0
  - Evidence/Notes: one git tag names the release across the umbrella and the four component mirrors, which the Split components workflow tags in lockstep, so the version cannot diverge

### Metadata & Discoverability

- [x] **Package Published on Packagist**
  - Package Name: `nexusphp/mcp` (https://packagist.org/packages/nexusphp/mcp)
  - Evidence/Notes: published, latest v0.9.0

- [x] **GitHub Repository Properly Configured**
  - Repository URL: https://github.com/NexusPHP/mcp
  - Topics: `mcp`, `mcp-sdk`, `model-context-protocol`. Consider adding `php` / `php-sdk`
  - Description: "PHP SDK for the MCP specification"

---

## Running the assessment

`tier-check` runs from the pinned referee, so the assessment needs no source checkout. Boot `php conformance/server.php` first.

```bash
GITHUB_TOKEN="$(gh auth token)" npx -y @modelcontextprotocol/conformance@0.2.0-alpha.10 tier-check \
  --repo NexusPHP/mcp \
  --conformance-server-url http://127.0.0.1:3000/mcp \
  --client-cmd "php conformance/client.php" \
  --spec-version 2026-07-28 \
  --output json
```

Omitting `--conformance-server-url` skips server conformance, and omitting `--client-cmd` skips client conformance. Either skip blocks Tier 1 (a skipped check is not a pass) but is tolerated for the Tier 2 clause. `--skip-conformance` gives the repository-health half on its own.

**Always pin `--spec-version`.** Without it the referee negotiates 2025-11-25 and omits the `MCP-Protocol-Version` header, which this SDK requires on every request including `initialize`. Every server scenario then fails on `-32020` at the first POST. That is a protocol-negotiation artifact, not a measurement. `draft` is an accepted alias for `2026-07-28`.

### Scoring model

A scenario counts toward the tier percentage only when it is live at one of the **2025 dated versions** (`2025-03-26`, `2025-06-18`, `2025-11-25`). Scenarios live only at `2026-07-28`, and those tagged `extension`, land in a separate bucket the report prints under "Informational (not scored for tier)". The carried-forward scenarios this SDK passes do count. The ones the 2026-07-28 revision introduced do not.

Two rules differ from `composer conformance:score`, and both matter when reading a tier number:

- The tier scorer counts **scenarios**, not checks, and treats a scenario as passed when it has no `FAILURE`. An unmet SHOULD (`WARNING`) does not fail it. `conformance:score` counts checks and holds a `WARNING` against the total, so it reports the stricter figure.
- A scenario in the expected list that did not run is scored as failed.

`tier-check` also runs **server** conformance at the referee's default `active` suite and **client** conformance at `--suite all`. That asymmetry, plus the check-versus-scenario difference above, is why the two rows in [Where we stand](#where-we-stand) differ.

Inverted naming: **server-mode** scenarios are `ClientScenario` objects under `src/scenarios/server/` (the harness acts as a client against your server, over Streamable HTTP at `POST /mcp`). **Client-mode** scenarios run your client as a subprocess.

### Authorization-server mode

One scenario (`authorization-server-metadata-endpoint`) via a separate `conformance authorization --url <as-url>` path. It is not in the client denominator. Out of scope unless the SDK grows an OAuth authorization server.

### Client subprocess contract

The harness spawns `<command> <serverUrl>` (shell-parsed) and sets `MCP_CONFORMANCE_SCENARIO` (the scenario name, which the client routes on), `MCP_CONFORMANCE_PROTOCOL_VERSION`, and `MCP_CONFORMANCE_CONTEXT` (JSON, auth credentials). Exit 0 = pass, non-zero = fail (tolerated only for negative scenarios). 30s default timeout.

---

## Submission readiness

- [ ] **Evidence & Artifact Links Prepared**
  - For each requirement, collected documentation links
  - Location/File: _________________________

- [ ] **Issue Submission Ready for MCP Org**
  - Reference: SEP-1730 advancement is request-based. Open an **issue** (not a PR) in `modelcontextprotocol/modelcontextprotocol` with supporting evidence, pass conformance, await SDK Working Group approval
  - Target Repo: modelcontextprotocol/modelcontextprotocol
  - Format: tier assessment table with evidence links
  - Working Group context: [discussion #2238](https://github.com/modelcontextprotocol/modelcontextprotocol/discussions/2238)

---

## Drift: SEP mandates vs the conformance repo

> Verified against conformance v0.2.0-alpha.0 on 2026-05-30. Citations are file paths in the conformance repo.

### SEP-1730 vs the tier-check tool

The `mcp-sdk-tier-audit` skill docs are byte-unchanged since v0.1.16 and contradict the 0.2.0 code. Material divergences:

- **Triage metric.** SEP-1730 and the skill doc define triage as "time from issue creation to first label, within 2 business days." The tool measures only the ratio of **currently-open** issues that carry any label (no timestamp, closed issues ignored), gated at 90%. The "2 business days" value feeds an unused counter (`src/tier-check/checks/triage.ts`).
- **P0 window mis-anchored.** The SEP measures from P0-label application to close. The tool measures `closed_at - created_at` (full issue lifetime), and any open P0 fails regardless of age (`src/tier-check/checks/p0.ts`).
- **Stable-release regex looser than documented.** The doc says `^\d+\.\d+\.\d+$`, but the code accepts two-part `1.0` (`src/tier-check/checks/release.ts`).
- **Target-version filter off by default.** Without `--spec-version`, the denominator includes legacy `2025-03-26` scenarios (`src/tier-check/checks/test-conformance-results.ts`).
- **Draft is a superset, not exact-match.** The skill doc claims `--spec-version draft` is exact-match. The code, README, and a pinned test all make draft cumulative (`tier-requirements.md` vs `src/scenarios/index.ts`).
- **Stale `specVersions` field.** Skill docs reference a `specVersions` field that 0.2.0 removed, replaced by `source: {introducedIn | extensionId}`.
- **Relegation / advancement unimplemented.** The 4-week-continuous-failure relegation and request-based advancement in SEP-1730 are absent. The tool is a point-in-time scorer.
- **Labels and spec-tracking gate Tier 1 only.** `computeTier` never checks labels, triage, or spec-tracking for Tier 2, though the report template lists them for both.

Net: treat the tool's tier verdict as advisory. The conformance percentage is the meaningful gate. The SLA and label checks are proxies that diverge from the SEP wording, so those are self-attested against the SEP text in the tier sections above.

### Feature SEPs vs scenario coverage

Traceability lives in `src/seps/sep-NNNN.yaml` (hand-authored MUST enumeration) joined to scenario-emitted check IDs in `src/seps/traceability.json`. `tested` means "a scenario emits this check ID" against the reference TS-SDK, not "any SDK passes it". `npm run traceability` produces a coverage manifest, advisory only (`--strict` exits non-zero on untested rows but is not a PR gate).

This table is the reason the suite passing is not the same as the spec being met: where the referee tests nothing, the obligation still binds and has to be self-verified.

| SEP | Conformance coverage | Gap to self-verify |
|---|---|---|
| 2575 stateless | Strong (22 checks + 11 untracked): `_meta` population, `-32003` / HTTP 400, `server/discover`, version header, `-32601` to 404, subscription ack/filter | None major. Heaviest scenario, mirror it |
| 2322 MRTR | Strong (17 checks, server + client) | None major |
| 2549 TTL | `ttlMs`/`cacheScope` presence + `ttl >= 0` | **`cacheScope` value not validated** (a server returning `cacheScope: "banana"` passes) |
| 2243 headers | 18 checks, client + server | **2 server MUSTs untested**: reject-missing-required-param, not-expect-null (host scenarios are pending) |
| 2164 -32602 | 2 checks (no-empty-contents, `-32602`) | None |
| 2106 JSON Schema | 1 check (`$ref` no-deref) + 3 untracked keyword-preservation. Server-side SEP-1613 scenario is pending | Keyword-preservation not enumerated as requirements |
| 2567 sessionless | **None** (folded into 2575, no isolated check) | Full self-verify |
| 2663 tasks | **None** (excluded as an extension) | Full self-verify (extension, not core) |
| 2577 deprecate roots/sampling/logging | **None** | Moot: not implemented |
| 2260 server-req association | yaml exists, **0 checks** (all 12 reqs excluded as subsumed by 2322) | The MUST-NOT (no standalone server streams) is untested |
| 414 OTel `_meta` | **None** | Full self-verify |

Implemented-but-untraceable SEPs (scenarios exist, no `sep-*.yaml`, invisible to the manifest): 1034, 1330, 1613, 1699.

SEP-2484 meta-drift: the repo has no SEP-status field, so it cannot mechanically enforce its own "Final SEP needs a traceability file" rule. Enforcement is downstream (plan.modelcontextprotocol.io) and advisory in-repo. SEP-2260 is the clearest case, a yaml that maps no MUST to any check.

---

## Notes & References

- **SEP-1730 Issue**: https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1730
- **SDK Working Group Meeting (Feb 11, 2026)**: https://github.com/modelcontextprotocol/modelcontextprotocol/discussions/2238
- **Conformance Test Framework**: https://github.com/modelcontextprotocol/conformance
- **SDK Integration Guide**: https://github.com/modelcontextprotocol/conformance/blob/main/SDK_INTEGRATION.md
- **Tiering Assessment Tool**: https://github.com/modelcontextprotocol/conformance/blob/main/.claude/skills/mcp-sdk-tier-audit/README.md (skill docs diverge from the 0.2.0 code, see [Drift](#drift-sep-mandates-vs-the-conformance-repo))
- **MCP Spec (2026-07-28, the targeted revision)**: https://modelcontextprotocol.io/specification/2026-07-28

## Progress summary

| Tier | Items complete | Total items | Status |
| --- | ---: | ---: | --- |
| Tier 3 | 1 | 1 | Met (claimable now) |
| Tier 2 | 6 | 8 | Open: the two issue-management metrics |
| Tier 1 | 9 | 12 | Open: docs scoring and triage history |

The two open Tier 2 items are the issue-management metrics, which cannot be demonstrated without issue traffic. Tier 1 adds the documentation scoring question. None is a conformance gap.
