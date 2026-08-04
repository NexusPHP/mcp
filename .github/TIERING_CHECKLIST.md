# PHP SDK Tiering System Checklist

Based on [SEP-1730: SDKs Tiering System](https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1730)

**Target Tier**: Tier 2, self-assessed (only Tier 3 is claimable pre-1.0, and assignment currently runs for official SDKs only, per "How tiering works" below)

**Target spec**: `2026-07-28`, which the SDK implements exclusively. See "Conformance suite: scenarios to pass" for the measured standing and for which scenarios the tier percentage is scored over.

**Last Updated**: 2026-08-04

---

## How tiering works (SEP-1730)

The published governance page ([`docs/community/sdk-tiers.mdx`](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/docs/community/sdk-tiers.mdx)) covers **both official and community-driven SDKs**, and tiers are **request-based**: self-assess, open an issue in `modelcontextprotocol/modelcontextprotocol` with supporting evidence, pass the automated conformance suite, then the SDK Working Group approves and makes the final assignment. Assignment practice has not caught up with that page: the one community application on record ([mcp#2814](https://github.com/modelcontextprotocol/modelcontextprotocol/issues/2814)) was declined with "at this time, the tiering only applies to official MCP SDKs that have broader community adoption", consistent with the page's own phased key dates (official SDK tiering published first, no date for community SDKs). Until community applications open, this checklist is a self-assessment, and the step before applying at a stable release is asking the working group whether they have.

- **Conformance is the gate.** Scored against **applicable required tests only**: the spec version the SDK targets, excluding pending/skipped tests, experimental-feature tests, and legacy back-compat tests (unless legacy support is claimed). The actual `tier-check` tool diverges from this wording in several places (target-version filter off by default, draft scenarios non-scoring, SLA metrics reworded): see "Drift: SEP mandates vs conformance repo".
- **Relegation.** An SDK drops a tier if conformance tests on its latest stable release fail continuously for 4 weeks. (Not implemented by the tool, which is a point-in-time scorer. See Drift.)
- **Where we stand.** Pre-1.0, only **Tier 3** is claimable (no stable-release or conformance minimum). **Tier 2** needs a stable 1.0-class release plus 80% conformance, and the conformance side already measures 100% on both legs. The assignment itself waits on `v1.0.0` and on community applications opening.

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
  - Threshold: 80% of the scored set, which is the carried-forward scenarios (see "Scoring model" below). Server **20 of 20**, client **15 of 15**: both legs pass outright
  - Evidence/Notes: both modes run and are measured at `--spec-version 2026-07-28 --suite all`. As of 2026-07-28: server **102 of 106 checks**, client **332 of 332**, combined **434 of 438 (99.1%)**
  - Run: `composer conformance:server` then `composer conformance:score`

### Implementation Timeline

- [ ] **New Protocol Features Within 6 Months**
  - Reference: SEP-1730 Tier 2 requirement
  - Currently implemented: 2025-11-25 (stdio). Target: the dated `2026-07-28` release, adopted at v1.0
  - Next Spec Release: 2026-07-28 (dated tag published 2026-07-28)
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
  - Release Tag: none stable. Pre-stable v0.1.0 to v0.5.0 are published on Packagist

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
  - Conformance Version: pinned in [`conformance/run-server.sh`](../conformance/run-server.sh)
  - Threshold: server `30 of 30`, client `18 of 18` today. Upstream has now dated the version, but the pinned referee still scores only the 2025-dated set, so the 2026-07-28 scenarios (17 server + 17 client) stay informational until a referee release adds `2026-07-28` to its dated list. They become Tier 1 blockers at that point
  - Evidence/Notes: _________________________
  - Conformance Score: ___%

- [ ] **Conformance example server + client**
  - Reference: not a standalone SEP-1730 requirement. This is the harness behind the conformance score above. Mirror the canonical fixtures in the conformance repo's [`everything-server.ts`](https://github.com/modelcontextprotocol/conformance/blob/main/examples/servers/typescript/everything-server.ts), per [SDK_INTEGRATION.md](https://github.com/modelcontextprotocol/conformance/blob/main/SDK_INTEGRATION.md)
  - Location: `conformance/server.php` + `conformance/client.php`, both built
  - Canonical fixtures + the full server/client capability contract: see "Conformance suite: scenarios to pass" below. Route the client off the harness scenario names, not the stale keys in `everything-client.ts`
  - Evidence/Notes: both halves are built and running. `conformance/EverythingServer.php` registers every server capability through attribute discovery, `conformance/client.php` is a scenario-name to closure registry covering the client leg, and `conformance/README.md` documents the run and bump procedure

### Implementation Timeline

- [ ] **New Protocol Features Before Spec Release**
  - Reference: SEP-1730 Tier 1 requirement, "before the new spec version release" (timeline agreed per release by feature complexity, not a fixed window)
  - Context: the 2026-07-28 RC-to-final window ran ~9 weeks (RC tag cut 2026-05-29, dated tag published 2026-07-28). Tier 1 requires building against the RC, not waiting for the final tag, which is what this SDK did
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
    - [x] OAuth/Authentication flows (docs/authorization.md plus the docs/auth/ pages)
    - [x] Transport configuration (docs/transports.md)
    - [x] Error handling (docs/error-handling.md)
    - [x] Best practices (docs/best-practices.md)
  - Documentation Location: docs/
  - Evidence/Notes: per-feature pages under docs/server/, docs/client/, and docs/auth/, plus docs/features.md mapping the conformance repo's 48-row canonical feature list to its documentation

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
  - Current Version: v0.5.0
  - Evidence/Notes: umbrella package only until 1.0 (per ROADMAP.md), so the single version is trivially consistent. Component packages split at v1.0

### Metadata & Discoverability

- [x] **Package Published on Packagist**
  - Reference: Composer registry
  - Package Name: `nexusphp/mcp`
  - Link: https://packagist.org/packages/nexusphp/mcp
  - Evidence/Notes: published. Latest v0.5.0. Umbrella package only during 0.x. Component packages split at v1.0

- [x] **GitHub Repository Properly Configured**
  - Repository URL: https://github.com/NexusPHP/mcp
  - Topics: `mcp`, `mcp-sdk`, `model-context-protocol` (set). Consider adding `php` / `php-sdk`
  - Description: "PHP SDK for the MCP specification" (set, clear)
  - Evidence/Notes: verified 2026-05-30: public, description set, 3 topics set

---

## Conformance & Validation

### Pre-Submission Self-Assessment

- [ ] **Run Tiering Assessment Tool**
  - Reference: [SDK Tier Assessment Tool](https://github.com/modelcontextprotocol/conformance) (build first: `npm ci && npm run build` in `../mcp-conformance`)
  - Command (policy only, no conformance): `GITHUB_TOKEN="$(gh auth token)" npx -y @modelcontextprotocol/conformance@<pin> tier-check --repo NexusPHP/mcp --skip-conformance --output json`
  - Output/Evidence: _________________________
  - Date Run: _________

- [ ] **Run Full Conformance Tests**
  - Reference: [MCP Conformance Framework](https://github.com/modelcontextprotocol/conformance). Scenario lists + run commands in "Conformance suite: scenarios to pass"
  - Command: `GITHUB_TOKEN="$(gh auth token)" npx -y @modelcontextprotocol/conformance@<pin> tier-check --repo NexusPHP/mcp --conformance-server-url <url> --client-cmd "php conformance/client.php" --spec-version 2026-07-28 --output json`
  - Conformance Version: the pin in [`conformance/run-server.sh`](../conformance/run-server.sh)
  - Server Test Results: _________________________ (target 20/20 scored)
  - Client Test Results: _________________________ (target 15/15 scored)
  - Baseline File: `conformance/expected-failures.yaml`
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

> The scenario inventory below was mapped against `modelcontextprotocol/conformance` **v0.2.0-alpha.0** on 2026-05-30 and has since drifted. The suite is now actually run, so treat `conformance/` as the source of truth for what passes: the referee version is pinned in [`conformance/run-server.sh`](../conformance/run-server.sh), what fails is listed in [`conformance/expected-failures.yaml`](../conformance/expected-failures.yaml), and `composer conformance:score` prints the current number. See "Drift" below for where the tooling diverges from SEP-1730.

**Target spec: `--spec-version 2026-07-28`.** The SDK implements that revision only. The referee filters scenarios by a `removedIn` field, so pinning the version is also what drops the 2025-era scenarios for features the SDK deliberately does not implement (`initialize`, `logging/setLevel`, sampling, `resources/subscribe`) rather than failing them. `draft` is an accepted alias for the same version.

**Standing, measured 2026-07-28**, both legs at `--suite all`:

- **Server mode:** 102 of 106 checks. The four failures all need an input request this SDK does not model: the spec's `InputRequest` union is `CreateMessageRequest | ListRootsRequest | ElicitRequest`, and `latest-schema.ts` marks the first two `@deprecated` as of 2026-07-28 (SEP-2577). Named in the baseline.
- **Client mode:** 290 of 304 checks. What remains is five OAuth scenarios each missing one obligation, plus the MRTR client leg, all named individually in the baseline.

### Scoring model (verified against the pinned referee)

`tier-check` runs from the pinned referee, so the assessment needs no source checkout:

```bash
GITHUB_TOKEN="$(gh auth token)" npx -y @modelcontextprotocol/conformance@<pin> tier-check \
  --repo NexusPHP/mcp \
  --conformance-server-url http://127.0.0.1:3000/ \
  --client-cmd "php conformance/client.php" \
  --spec-version 2026-07-28
```

Boot `php conformance/server.php` first. `--skip-conformance` gives the repository-health half on its own.

A scenario counts toward the tier percentage only when it is live at one of the **2025 dated versions** (`2025-03-26`, `2025-06-18`, `2025-11-25`). Scenarios live only at `2026-07-28`, and those tagged `extension`, land in a separate bucket the report prints under "Informational (not scored for tier)". So the carried-forward scenarios this SDK passes do count, while the ones the 2026-07-28 revision introduced do not.

Two rules differ from `composer conformance:score` and both matter when reading a tier number:

- The tier scorer counts **scenarios**, not checks, and treats a scenario as passed when it has no `FAILURE`. An unmet SHOULD (`WARNING`) does not fail it. `conformance:score` counts checks and holds a `WARNING` against the total, so it reports the stricter figure.
- A scenario in the expected list that did not run is scored as failed.

**Tier split at `--spec-version 2026-07-28`**, as `tier-check` reports it:

| Leg | 2025-06-18 | 2025-11-25 | Tier-scored | Status |
| --- | --- | --- | --- | --- |
| Server | 18/18 | 20/20 | **20/20 (100%)** | `pass` |
| Client: Core | 1/1 | 1/1 | 1/1 | |
| Client: Auth | 3/3 | 14/14 | 14/14 (100%) | |
| Client total | | | **15/15 (100%)** | `pass` |

Both legs pass the tier-scored set outright, and the informational bucket reads server 20 of 20 and client core 7 of 7 and auth 25 of 25 at `2026-07-28`. The client leg now passes every scenario at every version, so the tier figure and the badge figure agree on it.

Two things keep these numbers apart from the badge figures. `tier-check` runs **server** conformance at the referee's default `--suite active`, so the draft and pending scenarios stay out of the tier denominator: the four MRTR scenarios this SDK fails are among them and do not count against the tier. Client conformance it runs at `--suite all`.

### The deterministic gate

Tier 2 is met when all four hold. Tier 1 additionally requires 100% on both conformance legs, a triage compliance rate of at least 90%, every P0 closed within 7 days, an SDK release within 30 days of the latest spec release, and no missing issue labels.

| Requirement | Standing |
| --- | --- |
| Server conformance at or above 80% | 100% |
| Client conformance at or above 80% | 100% |
| Every P0 resolved within 14 days | No P0 issues, and no open issues |
| A stable release | **Not met.** Latest is `v0.5.0` |

The stable release is the only outstanding Tier 2 requirement. It lands with `v1.0.0` and the final dated spec. `tier-check` accordingly reports **Tier 3** today, with `Stable Release` as the single failing repository-health check.

The other health checks already pass: `Labels` at 12 of 12 required, `Triage` at 100% within two business days, and `P0 Resolution` with none open. `Policy Signals` reports partial, and the one artefact genuinely absent is `BREAKING_CHANGES.md`. The rest of its misses are alternative paths to files this repository keeps elsewhere (`DEPENDENCY_POLICY.md`, `ROADMAP.md`, and `VERSIONING.md` at the root rather than under `docs/`, and `.github/dependabot.yml` in place of a Renovate config).

Inverted naming: **server-mode** scenarios are `ClientScenario` objects under `src/scenarios/server/` (the harness acts as a client against your server, over Streamable HTTP at `POST /mcp`). **Client-mode** scenarios run your client as a subprocess.

### Server-mode (draft): 47 active scenarios

Stand up a Streamable-HTTP server at `POST /mcp` mirroring the `everything-server.ts` fixtures, then:

```bash
conformance server --url http://localhost:PORT/mcp --suite all --spec-version 2026-07-28 -o ./results
```

**Scored (20 carried-forward, as `tier-check` counts them):** Tier 1 = all 20, Tier 2 = at least 16 of 20 (`pass_rate >= 0.80`). The SDK passes all 20. Scenarios introduced at 2026-07-28 are informational. Read the split off a `tier-check` run rather than from the inventory below, which predates it.

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

Canonical fixtures our `conformance/EverythingServer.php` must expose (from `everything-server.ts`): identity `{name: mcp-conformance-test-server, version: 1.0.0}`, endpoint `POST /mcp` (plus `GET`/`DELETE`), capabilities `tools.listChanged`, `resources.subscribe`+`listChanged`, `prompts.listChanged`, `logging`, `completions`. Tools: `test_simple_text`, `test_image_content`, `test_audio_content`, `test_embedded_resource`, `test_multiple_content_types`, `test_tool_with_logging`, `test_tool_with_progress`, `test_error_handling`, `test_sampling`, `test_elicitation`, `test_elicitation_sep1034_defaults`, `test_elicitation_sep1330_enums`, `json_schema_2020_12_tool`. Resources: `test://static-text`, `test://static-binary`, `test://template/{id}/data`, `test://watched-resource` (plus subscribe/unsubscribe). Prompts: `test_simple_prompt`, `test_prompt_with_arguments`, `test_prompt_with_embedded_resource`, `test_prompt_with_image`. The draft-only scenarios add the stateless lifecycle (`server/discover`, `subscriptions/listen`), TTL hints, `-32602` errors, and the MRTR `resultType` / `inputRequests` / `requestState` flow. The README's `test_dynamic_*` fixtures are stale (absent from current source). Do not implement them.

### Client-mode (draft): 35 scenarios

Provide a client entry that reads the server URL as its last argv and routes on `MCP_CONFORMANCE_SCENARIO`, then:

```bash
conformance client --command "php conformance/client.php" --suite all --spec-version 2026-07-28 -o ./results
```

`--spec-version draft` excludes the 3 OAuth `extension` scenarios and the 2 `2025-03-26` back-compat scenarios. `--suite core` runs exactly the 18 scored scenarios.

**Scored (15 carried-forward, measured at `--spec-version 2026-07-28`):** Tier 1 = all 15, Tier 2 = at least 12 of 15 (`pass_rate >= 0.80`). The SDK passes all 15. The remaining 17 scenarios the suite runs were introduced at 2026-07-28 and are informational.

- Core (4): `initialize`, `tools_call`, `elicitation-sep1034-client-defaults`, `sse-retry`
- OAuth (14): `auth/metadata-default`, `-var1`, `-var2`, `-var3`, `auth/basic-cimd`, `auth/scope-from-www-authenticate`, `-from-scopes-supported`, `-omitted-when-undefined`, `-step-up`, `-retry-limit`, `auth/token-endpoint-auth-basic`, `-post`, `-none`, `auth/pre-registration`

**Draft-only (17, informational in scoring until the date bump):**

- SEP-2575 (1): `request-metadata` (per-request `_meta` + `MCP-Protocol-Version` header)
- SEP-2322 MRTR (1): `sep-2322-client-request-state`
- SEP-2243 headers (3): `http-standard-headers`, `http-custom-headers`, `http-invalid-tool-headers`
- SEP-2106 (1): `json-schema-ref-no-deref`
- Draft OAuth (11): `auth/resource-mismatch`, `auth/offline-access-scope`, `auth/offline-access-not-supported`, `auth/authorization-server-migration`, `auth/iss-supported`, `auth/iss-not-advertised`, `auth/iss-supported-missing`, `auth/iss-wrong-issuer`, `auth/iss-unexpected`, `auth/iss-normalized`, `auth/metadata-issuer-mismatch`

**Extension (3, `--suite extensions` only, never scored):** `auth/client-credentials-jwt`, `auth/client-credentials-basic`, `auth/enterprise-managed-authorization`.

Capabilities our `conformance/client.php` must implement. For the 18 carried-forward: Streamable HTTP client transport, `initialize` + `tools/list` + `tools/call`, an elicitation client capability that applies schema defaults for omitted fields, a full OAuth 2.1 client (PKCE, PRM + AS metadata discovery, DCR + CIMD, scope handling incl. step-up and retry-cap, token-endpoint auth basic/post/none, RFC 8707 `resource`, pre-registered creds), and SSE retry/reconnect with `Last-Event-ID`. For the draft-only set additionally: per-request `_meta` + protocol-version header, MRTR `input_required` result handling (echo `requestState`, fulfill `inputRequests`), `Mcp-Method` / `Mcp-Name` + `x-mcp-header` propagation, no-network `$ref` dereferencing, and draft OAuth (issuer validation, `offline_access`, AS migration). Subprocess contract: the harness spawns `<command> <serverUrl>` (shell-parsed), sets `MCP_CONFORMANCE_SCENARIO` (= scenario name, route on this), `MCP_CONFORMANCE_PROTOCOL_VERSION`, and `MCP_CONFORMANCE_CONTEXT` (JSON, auth credentials). Exit 0 = pass, non-zero = fail (tolerated only for negative scenarios). 30s default timeout. Route on the registry scenario names above, not the stale keys baked into `everything-client.ts`.

### Authorization-server mode

One scenario (`authorization-server-metadata-endpoint`) via a separate `conformance authorization --url <as-url>` path. It is NOT in the client denominator. Out of scope unless the SDK grows an OAuth authorization server.

### Running tier-check

```bash
conformance tier-check --repo NexusPHP/mcp \
  --conformance-server-url http://localhost:PORT/mcp \
  --client-cmd "php conformance/client.php" \
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
- **MCP Spec (2026-07-28, target)**: https://modelcontextprotocol.io/specification/2026-07-28
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
