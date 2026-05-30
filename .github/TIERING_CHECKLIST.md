# PHP SDK Tiering System Checklist

Based on [SEP-1730: SDKs Tiering System](https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1730)

**Target Tier**: Tier 2 (only Tier 3 is claimable pre-1.0, per "How tiering works" below)

**Target spec**: the draft (`DRAFT-2026-v1`, becomes `2026-07-28`). We aim for the latest spec, not the interim 2025-11-25. See "Conformance suite: scenarios to pass" for the exact server/client tests and the draft-vs-scored caveat.

**Last Updated**: 2026-05-30

---

## How tiering works (SEP-1730)

The tiering system covers **both official and community-driven SDKs**, so `nexusphp/mcp-sdk` is eligible. Tiers are **request-based**: self-assess, open an issue in `modelcontextprotocol/modelcontextprotocol` with supporting evidence, pass the automated conformance suite, then the SDK Working Group approves and makes the final assignment.

- **Conformance is the gate.** Scored against **applicable required tests only**: the spec version the SDK targets, excluding pending/skipped tests, experimental-feature tests, and legacy back-compat tests (unless legacy support is claimed). The actual `tier-check` tool diverges from this wording in several places (target-version filter off by default, draft scenarios non-scoring, SLA metrics reworded): see "Drift: SEP mandates vs conformance repo".
- **Relegation.** An SDK drops a tier if conformance tests on its latest stable release fail continuously for 4 weeks. (Not implemented by the tool, which is a point-in-time scorer. See Drift.)
- **Where we stand.** Pre-1.0, only **Tier 3** is claimable (no stable-release or conformance minimum). **Tier 2** needs a stable 1.0-class release plus 80% conformance (server-mode conformance runs over HTTP, so it needs the Streamable HTTP transport), landing with `v1.0.0` and the 2026-07-28 migration.

---

## Tier 3: Experimental

> Early-stage or specialized SDKs exploring the protocol space.

- [x] **SDK Implementation Started**
  - Reference: Basic MCP protocol support available
  - Notes: full server + client against MCP 2025-11-25 over stdio (tools, prompts, resources incl. RFC 6570 templates, completions, logging, ping). See ROADMAP.md

---

## Tier 2: Commitment to be Fully Supported

> Active implementations working toward full protocol support.

### Feature Completeness

- [ ] **80% Conformance Tests Pass**
  - Reference: [Conformance Test Suite](https://github.com/modelcontextprotocol/conformance). Exact scenarios in "Conformance suite: scenarios to pass" below
  - Threshold: server `>= 24 of 30`, client `>= 15 of 18` (the carried-forward date-versioned set that scores even under `--spec-version draft`)
  - Evidence/Notes: not run. Server-mode conformance needs the Streamable HTTP transport (phase 5). Client-mode can run our client as a subprocess
  - Run: `conformance tier-check --repo NexusPHP/mcp-sdk --conformance-server-url <url> --client-cmd "<cmd>" --spec-version draft --output json`

### Implementation Timeline

- [ ] **New Protocol Features Within 6 Months**
  - Reference: SEP-1730 Tier 2 requirement
  - Currently implemented: 2025-11-25 (stdio). Target: the draft (`DRAFT-2026-v1` / `2026-07-28`), adopted at v1.0
  - Next Spec Release: 2026-07-28 (RC tag `2026-07-28-RC` cut 2026-05-29, final pending)
  - Evidence/Notes: forward commitment, assessed once we publish a stable release

### Maintenance & Issue Management

- [ ] **Issue Triage Within a Month**
  - Reference: SEP-1730 Tier 2 requirement (triage = label + validity assessment, not resolution)
  - Evidence/Notes: _________________________

- [ ] **Critical Bug (P0) Resolution Within Two Weeks**
  - Reference: SEP-1730 Tier 2 requirement
  - Evidence/Notes: _________________________

- [ ] **At Least One Stable Release**
  - Reference: Published release with stable API
  - Evidence/Notes: no stable release yet. 0.x is pre-stable (breaking changes allowed in minors). A stable release means v1.0.0, which lands with the 2026-07-28 migration
  - Release Tag: none stable. Pre-stable v0.1.0 / v0.2.0 / v0.3.0 are published on Packagist

### Documentation

- [x] **Basic Documentation for Core Features**
  - Reference: README, getting started guide
  - Coverage: Core server features, core client features
  - Evidence/Notes: README.md plus docs/getting-started.md, docs/server.md, docs/client.md, docs/transports.md, docs/architecture.md

- [x] **Published Dependency Update Policy**
  - Reference: Document in repository (e.g., DEPENDENCY_POLICY.md)
  - Location: DEPENDENCY_POLICY.md (plus .github/dependabot.yml automation)
  - Covers: Security patches, minor updates, major updates
  - Evidence/Notes: DEPENDENCY_POLICY.md published 2026-05-25 (PHP version matrix, security timeline, minor/major/EOL policy)

### Commitment & Roadmap

- [x] **Published Plan Toward Tier 1 (or explanation for remaining Tier 2)**
  - Reference: SEP-1730 Tier 2 requirement. ROADMAP.md or a GitHub project board
  - Location: ROADMAP.md
  - Evidence/Notes: ROADMAP.md (phases through the 2026-07-28 migration)

---

## Tier 1: Fully Supported

> SDKs in this tier provide full protocol implementation and are well supported.

### Feature Completeness

- [ ] **100% Conformance Tests Pass**
  - Reference: [Conformance Test Suite](https://github.com/modelcontextprotocol/conformance). Exact scenarios in "Conformance suite: scenarios to pass" below
  - Conformance Version: v0.2.0-alpha.0 (HEAD `bcfd400`, mapped 2026-05-30)
  - Threshold: server `30 of 30`, client `18 of 18` today. The draft-only scenarios (17 server + 17 client) flip to scoring once upstream dates the version, at which point they also become Tier 1 blockers
  - Evidence/Notes: _________________________
  - Conformance Score: ___%

- [ ] **Conformance example server + client**
  - Reference: not a standalone SEP-1730 requirement. This is the harness behind the conformance score above. Mirror the canonical fixtures in the conformance repo's [`everything-server.ts`](https://github.com/modelcontextprotocol/conformance/blob/main/examples/servers/typescript/everything-server.ts), per [SDK_INTEGRATION.md](https://github.com/modelcontextprotocol/conformance/blob/main/SDK_INTEGRATION.md)
  - Location: `examples/conformance-server.php` + `examples/conformance-client.php` (planned)
  - Canonical fixtures + the full server/client capability contract: see "Conformance suite: scenarios to pass" below (verified against `everything-server.ts` / `everything-client.ts` at v0.2.0-alpha.0). Route the client off the harness scenario names, not the stale keys in `everything-client.ts`
  - Evidence/Notes: not started

### Implementation Timeline

- [ ] **New Protocol Features Before Spec Release**
  - Reference: SEP-1730 Tier 1 requirement, "before the new spec version release" (timeline agreed per release by feature complexity, not a fixed window)
  - Context: the 2026-07-28 RC-to-final window is ~10 weeks (RC tag `2026-07-28-RC` published 2026-05-29, content locked 2026-05-21). Tier 1 requires building against the RC, not waiting for the final tag
  - Next Spec Release: 2026-07-28
  - Evidence/Notes: _________________________

### Maintenance & Support

- [ ] **Issue Triage Within 2 Business Days**
  - Reference: SEP-1730 Tier 1 requirement
  - GitHub Policy/Template: _________________________
  - Process Documentation: _________________________
  - Evidence/Notes: _________________________

- [ ] **Security & Critical Bug Resolution Within 7 Days**
  - Reference: SEP-1730 Tier 1 requirement
  - Security Policy File: SECURITY.md
  - Location: repository root
  - Evidence/Notes: SECURITY.md present. The 7-day resolution SLA is not formally committed yet

- [ ] **Stable Release & Versioning Clearly Documented**
  - Reference: Published versioning policy
  - Location/File: VERSIONING.md (versioning scheme, breaking-change policy), ROADMAP.md (release sequencing)
  - Documents: Semantic versioning, release schedule, breaking changes policy
  - Evidence/Notes: versioning policy now published in VERSIONING.md (SemVer scheme, pre-1.0 caveat, breaking-change definition, deprecation path, spec-revision tracking). Stable 1.0 release still pending

- [x] **Published Roadmap**
  - Reference: SEP-1730 Tier 1 requirement
  - Location: ROADMAP.md
  - Evidence/Notes: ROADMAP.md

### Documentation

- [ ] **Comprehensive Documentation with Examples for All Features**
  - Reference: API docs, guides, tutorials
  - Coverage:
    - [x] Server implementation guide (docs/server.md)
    - [x] Client implementation guide (docs/client.md)
    - [x] Tool registration & handling (docs/server.md)
    - [x] Resource management (docs/server.md)
    - [x] Prompt handling (docs/server.md)
    - [ ] OAuth/Authentication flows (committed pre-v1.0.0, see ROADMAP.md "Authorization (OAuth 2.1)". The majority of scored client-mode scenarios are OAuth, so this gates client-mode conformance)
    - [x] Transport configuration (docs/transports.md)
    - [x] Error handling (docs/error-handling.md)
    - [x] Best practices (docs/best-practices.md)
  - Documentation Location: docs/
  - Evidence/Notes: core, attribute-discovery, error-handling, best-practices, and a design-rationale page all published. Auth pending (see ROADMAP.md Authorization)

- [x] **Published Dependency Update Policy**
  - Reference: SEP-1730 requirement
  - Location/File: DEPENDENCY_POLICY.md
  - Covers:
    - [x] Security patch timeline
    - [x] Minor version update policy
    - [x] Major version update policy
    - [x] End-of-life policy for old versions
    - [x] PHP version support matrix
  - Evidence/Notes: DEPENDENCY_POLICY.md (published 2026-05-25)

### Quality Standards (internal gates, not SEP-1730 requirements)

- [x] **Static Analysis Passing (PHPStan Level 10)**
  - Reference: PHPStan
  - Command: `composer phpstan:check`
  - Evidence: No errors in main codebase
  - Notes: level 10 + strict rules, enforced in CI

- [x] **Code Style Compliance**
  - Reference: PHP-CS-Fixer, Nexus84 preset from Nexus CS Config
  - Command: `composer cs:check`
  - Evidence: All files compliant
  - Notes: enforced in CI

- [x] **Full Test Coverage**
  - Reference: PHPUnit tests
  - Coverage Target: 100% (Infection MSI + code coverage, enforced)
  - Command: `composer test:all`
  - All Tests Pass: Yes
  - Notes: 100% MSI, 100% covered-code MSI, 100% line coverage (infection.json5)

---

## Supporting Documentation

### Architecture & Code Quality

- [x] **CONTRIBUTING.md Updated**
  - Location: Repository root
  - Covers: Development setup, testing, code standards, PR process
  - Evidence/Notes: CONTRIBUTING.md

- [x] **CODE_OF_CONDUCT.md Present**
  - Location: Repository root
  - Evidence/Notes: CODE_OF_CONDUCT.md (Contributor Covenant 2.1)

- [x] **Proper Exception Handling**
  - Reference: every SDK exception implements the `McpExceptionInterface` marker, so consumers can `catch (McpExceptionInterface $e)`
  - Namespaces (every exception implements the `Nexus\Mcp\Core\Exception\McpExceptionInterface` marker):
    - [x] `Nexus\Mcp\Core\Exception\*`
    - [x] `Nexus\Mcp\Server\Exception\*`
    - [x] `Nexus\Mcp\Client\Exception\*`
  - Evidence/Notes: marker enforced. PHPStan flags external use of @internal exceptions

### Release Management

- [x] **Changelog Maintained (CHANGELOG.md)**
  - Location: Repository root
  - Format: Semantic versioning compatible
  - Evidence/Notes: CHANGELOG.md (Keep a Changelog format, Unreleased section)

- [x] **Version Number Consistency**
  - All packages released at same version
  - Location of version definition: git tag (composer.json has no version field, umbrella-only during 0.x)
  - Current Version: v0.3.0
  - Evidence/Notes: umbrella package only until 1.0 (per ROADMAP.md), so the single version is trivially consistent. Component packages split at 1.0

### Metadata & Discoverability

- [x] **Package Published on Packagist**
  - Reference: Composer registry
  - Package Name: `nexusphp/mcp-sdk`
  - Link: https://packagist.org/packages/nexusphp/mcp-sdk
  - Evidence/Notes: published. Latest v0.3.0 (also v0.2.0, v0.1.0). Umbrella package only during 0.x. Component packages split at 1.0

- [x] **GitHub Repository Properly Configured**
  - Repository URL: https://github.com/NexusPHP/mcp-sdk
  - Topics: `mcp`, `mcp-sdk`, `model-context-protocol` (set). Consider adding `php` / `php-sdk`
  - Description: "PHP SDK for the MCP specification" (set, clear)
  - Evidence/Notes: verified 2026-05-30: public, description set, 3 topics set

---

## Conformance & Validation

### Pre-Submission Self-Assessment

- [ ] **Run Tiering Assessment Tool**
  - Reference: [SDK Tier Assessment Tool](https://github.com/modelcontextprotocol/conformance) (build first: `npm ci && npm run build` in `../mcp-conformance`)
  - Command (policy only, no conformance): `node dist/index.js tier-check --repo NexusPHP/mcp-sdk --skip-conformance --output json`
  - Output/Evidence: _________________________
  - Date Run: _________

- [ ] **Run Full Conformance Tests**
  - Reference: [MCP Conformance Framework](https://github.com/modelcontextprotocol/conformance). Scenario lists + run commands in "Conformance suite: scenarios to pass"
  - Command: `node dist/index.js tier-check --repo NexusPHP/mcp-sdk --conformance-server-url <url> --client-cmd "<cmd>" --spec-version draft --output json`
  - Conformance Version: v0.2.0-alpha.0
  - Server Test Results: _________________________ (target 30/30 scored, plus 17 draft-only informational)
  - Client Test Results: _________________________ (target 18/18 scored, plus 17 draft-only informational)
  - Baseline File: `conformance-baseline.yml`
  - Evidence/Notes: _________________________

### Submission Readiness

- [ ] **Evidence & Artifact Links Prepared**
  - For each requirement, collected documentation links
  - Created summary document with references
  - Location/File: _________________________

- [ ] **Issue Submission Ready for MCP Org**
  - Reference: SEP-1730 advancement is request-based. Open an **issue** (not a PR) in `modelcontextprotocol/modelcontextprotocol` with supporting evidence, pass conformance, await SDK Working Group approval
  - Target Repo: modelcontextprotocol/modelcontextprotocol
  - Format: tier assessment table with evidence links
  - Working Group context: [discussion #2238](https://github.com/modelcontextprotocol/modelcontextprotocol/discussions/2238)
  - Evidence/Notes: _________________________

---

## Conformance suite: scenarios to pass

> Mapped against `modelcontextprotocol/conformance` **v0.2.0-alpha.0** (HEAD `bcfd400`) on 2026-05-30, verified with `node dist/index.js list`. Local checkout at `../mcp-conformance`. See "Drift" below for where the tooling diverges from SEP-1730.

**Target spec: the draft (`DRAFT-2026-v1`), not the interim 2025-11-25.** We aim for the latest spec, so the conformance target is `--spec-version draft`. `DRAFT-2026-v1` is the symbolic id for the revision that becomes `2026-07-28` (no dated identifier exists upstream yet). This matches "Tier 2 lands with v1.0.0 and the 2026-07-28 migration."

### Scoring model (verified against the tooling)

`tier-check` computes the tier percentage over **date-versioned, active scenarios only**. Three exclusions apply to the denominator:

- **Draft-only** scenarios (tagged `DRAFT-2026-v1`) do not score. They run and are reported, but `pass_rate` ignores them (`src/sdk-runner/index.ts` `NON_SCORING_TAGS`).
- **Extension** scenarios (tagged `extension`: OAuth client-credentials, enterprise-managed-auth) do not score.
- **Pending** scenarios (`pendingClientScenariosList`) are not in the scenario map, so they run only via `--suite pending`.

**The catch when targeting draft.** Because draft-tagged scenarios are non-scoring, running `--spec-version draft` produces the *same* tier percentage as `--spec-version 2025-11-25`: the denominator is the carried-forward date-versioned set (30 server, 18 client). The draft-only scenarios (the actual v1.0 SEP work) run and report pass/fail but do not move the tier number until upstream dates the version (renames their `introducedIn` from `DRAFT-2026-v1` to `2026-07-28` and adds it to `DATED_SPEC_VERSIONS`). For v1.0 we implement and pass them regardless: they are the substance of the migration and flip to scoring at the date bump.

Inverted naming: **server-mode** scenarios are `ClientScenario` objects under `src/scenarios/server/` (the harness acts as a client against your server, over Streamable HTTP at `POST /mcp`). **Client-mode** scenarios run your client as a subprocess.

### Server-mode (draft): 47 active scenarios

Stand up a Streamable-HTTP server at `POST /mcp` mirroring the `everything-server.ts` fixtures, then:

```bash
conformance server --url http://localhost:PORT/mcp --suite all --spec-version draft -o ./results
```

**Scored today (30, carried-forward, what `tier-check` counts even under draft):** Tier 1 = all 30, Tier 2 = at least 24 of 30 (`pass_rate >= 0.80`).

- Lifecycle / utility (4): `server-initialize`, `ping`, `logging-set-level`, `completion-complete`
- Tools (11): `tools-list`, `tools-call-simple-text`, `-image`, `-audio`, `-embedded-resource`, `-mixed-content`, `-with-logging`, `-error`, `-with-progress`, `-sampling`, `-elicitation`
- Resources (6): `resources-list`, `-read-text`, `-read-binary`, `-templates-read`, `-subscribe`, `-unsubscribe`
- Prompts (5): `prompts-list`, `prompts-get-simple`, `-with-args`, `-embedded-resource`, `-with-image`
- Elicitation schema (2): `elicitation-sep1034-defaults`, `elicitation-sep1330-enums`
- Transport / security (2): `server-sse-multiple-streams`, `dns-rebinding-protection`

**Draft-only, active (17, the v1.0 SEP work, informational in scoring until the date bump):**

- SEP-2575 stateless (1): `server-stateless` (`server/discover`, per-request `_meta`, version negotiation 400/404, `subscriptions/listen`, `-32003`)
- SEP-2549 TTL (1): `caching` (`ttlMs` + `cacheScope`)
- SEP-2164 (1): `sep-2164-resource-not-found` (`-32602`, no empty contents)
- SEP-2322 MRTR (14): the `input-required-result-*` set (`-basic-elicitation`, `-basic-sampling`, `-basic-list-roots`, `-request-state`, `-multiple-input-requests`, `-multi-round`, `-missing-input-response`, `-non-tool-request`, `-result-type`, `-unsupported-methods`, `-tampered-state`, `-capability-check`, `-ignore-extra-params`, `-validate-input`)

**Pending (4, run only via `--suite pending`):** `json-schema-2020-12` (SEP-1613/2106), `server-sse-polling` (SEP-1699), `http-header-validation`, `http-custom-header-server-validation` (SEP-2243).

Canonical fixtures our `examples/conformance-server.php` must expose (from `everything-server.ts`): identity `{name: mcp-conformance-test-server, version: 1.0.0}`, endpoint `POST /mcp` (plus `GET`/`DELETE`), capabilities `tools.listChanged`, `resources.subscribe`+`listChanged`, `prompts.listChanged`, `logging`, `completions`. Tools: `test_simple_text`, `test_image_content`, `test_audio_content`, `test_embedded_resource`, `test_multiple_content_types`, `test_tool_with_logging`, `test_tool_with_progress`, `test_error_handling`, `test_sampling`, `test_elicitation`, `test_elicitation_sep1034_defaults`, `test_elicitation_sep1330_enums`, `json_schema_2020_12_tool`. Resources: `test://static-text`, `test://static-binary`, `test://template/{id}/data`, `test://watched-resource` (plus subscribe/unsubscribe). Prompts: `test_simple_prompt`, `test_prompt_with_arguments`, `test_prompt_with_embedded_resource`, `test_prompt_with_image`. The draft-only scenarios add the stateless lifecycle (`server/discover`, `subscriptions/listen`), TTL hints, `-32602` errors, and the MRTR `resultType` / `inputRequests` / `requestState` flow. The README's `test_dynamic_*` fixtures are stale (absent from current source). Do not implement them.

### Client-mode (draft): 35 scenarios

Provide a client entry that reads the server URL as its last argv and routes on `MCP_CONFORMANCE_SCENARIO`, then:

```bash
conformance client --command "php examples/conformance-client.php" --suite all --spec-version draft -o ./results
```

`--spec-version draft` excludes the 3 OAuth `extension` scenarios and the 2 `2025-03-26` back-compat scenarios. `--suite core` runs exactly the 18 scored scenarios.

**Scored today (18, carried-forward):** Tier 1 = all 18, Tier 2 = at least 15 of 18 (`pass_rate >= 0.80`).

- Core (4): `initialize`, `tools_call`, `elicitation-sep1034-client-defaults`, `sse-retry`
- OAuth (14): `auth/metadata-default`, `-var1`, `-var2`, `-var3`, `auth/basic-cimd`, `auth/scope-from-www-authenticate`, `-from-scopes-supported`, `-omitted-when-undefined`, `-step-up`, `-retry-limit`, `auth/token-endpoint-auth-basic`, `-post`, `-none`, `auth/pre-registration`

**Draft-only (17, informational in scoring until the date bump):**

- SEP-2575 (1): `request-metadata` (per-request `_meta` + `MCP-Protocol-Version` header)
- SEP-2322 MRTR (1): `sep-2322-client-request-state`
- SEP-2243 headers (3): `http-standard-headers`, `http-custom-headers`, `http-invalid-tool-headers`
- SEP-2106 (1): `json-schema-ref-no-deref`
- Draft OAuth (11): `auth/resource-mismatch`, `auth/offline-access-scope`, `auth/offline-access-not-supported`, `auth/authorization-server-migration`, `auth/iss-supported`, `auth/iss-not-advertised`, `auth/iss-supported-missing`, `auth/iss-wrong-issuer`, `auth/iss-unexpected`, `auth/iss-normalized`, `auth/metadata-issuer-mismatch`

**Extension (3, `--suite extensions` only, never scored):** `auth/client-credentials-jwt`, `auth/client-credentials-basic`, `auth/enterprise-managed-authorization`.

Capabilities our `examples/conformance-client.php` must implement. For the 18 carried-forward: Streamable HTTP client transport, `initialize` + `tools/list` + `tools/call`, an elicitation client capability that applies schema defaults for omitted fields, a full OAuth 2.1 client (PKCE, PRM + AS metadata discovery, DCR + CIMD, scope handling incl. step-up and retry-cap, token-endpoint auth basic/post/none, RFC 8707 `resource`, pre-registered creds), and SSE retry/reconnect with `Last-Event-ID`. For the draft-only set additionally: per-request `_meta` + protocol-version header, MRTR `input_required` result handling (echo `requestState`, fulfill `inputRequests`), `Mcp-Method` / `Mcp-Name` + `x-mcp-header` propagation, no-network `$ref` dereferencing, and draft OAuth (issuer validation, `offline_access`, AS migration). Subprocess contract: the harness spawns `<command> <serverUrl>` (shell-parsed), sets `MCP_CONFORMANCE_SCENARIO` (= scenario name, route on this), `MCP_CONFORMANCE_PROTOCOL_VERSION`, and `MCP_CONFORMANCE_CONTEXT` (JSON, auth credentials). Exit 0 = pass, non-zero = fail (tolerated only for negative scenarios). 30s default timeout. Route on the registry scenario names above, not the stale keys baked into `everything-client.ts`.

### Authorization-server mode

One scenario (`authorization-server-metadata-endpoint`) via a separate `conformance authorization --url <as-url>` path. It is NOT in the client denominator. Out of scope unless the SDK grows an OAuth authorization server.

### Running tier-check

```bash
conformance tier-check --repo NexusPHP/mcp-sdk \
  --conformance-server-url http://localhost:PORT/mcp \
  --client-cmd "php examples/conformance-client.php" \
  --spec-version draft --output json
```

Omitting `--conformance-server-url` skips server conformance. Omitting `--client-cmd` skips client conformance. Either skip blocks Tier 1 (a skipped check is not a pass) but is tolerated for the Tier 2 clause. For a fast policy-only self-check that skips both: add `--skip-conformance`.

---

## Drift: SEP mandates vs conformance repo

> Verified 2026-05-30 against conformance v0.2.0-alpha.0. The drift hypothesis holds in both the tiering meta-SEP and the feature SEPs. Citations are file paths in `../mcp-conformance`.

### A. SEP-1730 (tiering) vs the tier-check tool

The `.claude/skills/mcp-sdk-tier-audit` docs are byte-unchanged since v0.1.16 and now contradict the 0.2.0 code. Material divergences:

- **Triage metric.** SEP-1730 and the skill doc define triage as "time from issue creation to first label, within 2 business days." The tool measures only the ratio of **currently-open** issues that carry any label (no timestamp, closed issues ignored), gated at 90%. The "2 business days" value feeds an unused counter (`src/tier-check/checks/triage.ts`).
- **P0 window mis-anchored.** The SEP measures from P0-label application to close. The tool measures `closed_at - created_at` (full issue lifetime), and any open P0 fails regardless of age (`src/tier-check/checks/p0.ts`).
- **Stable-release regex looser than documented.** The doc says `^\d+\.\d+\.\d+$`, but the code accepts two-part `1.0` (`src/tier-check/checks/release.ts`).
- **Target-version filter off by default.** Without `--spec-version`, the denominator includes legacy `2025-03-26` scenarios. Always pin the version (`src/tier-check/checks/test-conformance-results.ts`).
- **Draft is a superset, not exact-match.** The skill doc claims `--spec-version draft` is exact-match. The code, README, and a pinned test all make draft cumulative (`tier-requirements.md` vs `src/scenarios/index.ts`).
- **Stale `specVersions` field.** Skill docs reference a `specVersions` field that 0.2.0 removed (replaced by `source: {introducedIn | extensionId}`).
- **Relegation / advancement unimplemented.** The 4-week-continuous-failure relegation and request-based advancement in SEP-1730 are absent. The tool is a point-in-time scorer.
- **Labels and spec-tracking gate Tier 1 only.** `computeTier` never checks labels, triage, or spec-tracking for Tier 2, though the report template lists them for both.

Net for us: run `tier-check` with `--spec-version draft` and treat its tier verdict as advisory (the scored percentage is identical to `2025-11-25` today, since draft scenarios are non-scoring). The conformance percentage is the meaningful gate. The SLA and label checks are proxies that diverge from the SEP wording, so we self-attest those against the SEP text in the tier sections above.

### B. Feature SEPs vs scenario coverage

Traceability lives in `src/seps/sep-NNNN.yaml` (hand-authored MUST enumeration) joined to scenario-emitted check IDs in `src/seps/traceability.json`. `tested` means "a scenario emits this check ID" against the reference TS-SDK, NOT "any SDK passes it." `npm run traceability` produces a coverage manifest, advisory only (`--strict` exits non-zero on untested rows but is not a PR gate).

Coverage of the SEPs on our 2026-07-28 migration path (all currently tagged `DRAFT-2026-v1`):

| SEP | Conformance coverage today | Gap to self-verify |
|---|---|---|
| 2575 stateless | Strong (22 checks + 11 untracked): `_meta` population, `-32003` / HTTP 400, `server/discover`, version header, `-32601` to 404, subscription ack/filter | None major. Heaviest scenario, mirror it |
| 2322 MRTR | Strong (17 checks, server + client) | None major |
| 2549 TTL | `ttlMs`/`cacheScope` presence + `ttl >= 0` | **`cacheScope` value not validated** (a server returning `cacheScope: "banana"` passes) |
| 2243 headers | 18 checks, client + server | **2 server MUSTs untested**: reject-missing-required-param, not-expect-null (host scenarios are pending) |
| 2164 -32602 | 2 checks (no-empty-contents, `-32602`) | None |
| 2106 JSON Schema | 1 check (`$ref` no-deref) + 3 untracked keyword-preservation. Server-side SEP-1613 scenario is pending | Keyword-preservation not enumerated as requirements |
| 2567 sessionless | **None** (folded into 2575, no isolated check) | Full self-verify |
| 2663 tasks | **None** (explicitly excluded as an extension) | Full self-verify (extension, not core) |
| 2577 deprecate roots/sampling/logging | **None** | Moot: we delete these at the cut |
| 2260 server-req association | yaml exists, **0 checks** (all 12 reqs excluded as subsumed by 2322) | The MUST-NOT (no standalone server streams) is untested |
| 414 OTel `_meta` | **None** (only a unit-test fixture) | Full self-verify |

Implemented-but-untraceable SEPs (scenarios exist, no `sep-*.yaml`, invisible to the manifest): 1034, 1330, 1613, 1699.

SEP-2484 meta-drift: the repo has no SEP-status field, so it cannot mechanically enforce its own "Final SEP needs a traceability file" rule. Enforcement is downstream (plan.modelcontextprotocol.io) and advisory in-repo. SEP-2260 is the clearest case (a yaml that maps no MUST to any check). Post-migration we will be scored under `--spec-version draft` until upstream dates the version and migrates the `introducedIn` tags.

---

## Notes & References

- **SEP-1730 Issue**: https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1730
- **SDK Working Group Meeting (Feb 11, 2026)**: https://github.com/modelcontextprotocol/modelcontextprotocol/discussions/2238
- **Conformance Test Framework**: https://github.com/modelcontextprotocol/conformance (mapped at v0.2.0-alpha.0, local clone `../mcp-conformance`)
- **SDK Integration Guide**: https://github.com/modelcontextprotocol/conformance/blob/main/SDK_INTEGRATION.md
- **Tiering Assessment Tool**: https://github.com/modelcontextprotocol/conformance/blob/main/.claude/skills/mcp-sdk-tier-audit/README.md (skill docs are stale vs the 0.2.0 code, see "Drift")
- **MCP Spec (draft, target)**: https://modelcontextprotocol.io/specification/draft
- **MCP Spec (2025-11-25, currently implemented)**: https://modelcontextprotocol.io/specification/2025-11-25

### General Notes

---

### Progress Summary

| Tier | Items Complete | Total Items | Status |
|------|---|---|---|
| Tier 3 | 1 | 1 | Met (claimable now) |
| Tier 2 | 3 | 8 | In progress |
| Tier 1 | 18 | 26 | In progress |
| **TOTAL** | **22** | **35** | **Tier 3 met, Tier 2 gated on v1.0.0** |
