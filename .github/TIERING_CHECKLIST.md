# PHP SDK Tiering System Checklist

Based on [SEP-1730: SDKs Tiering System](https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1730)

**Target Tier**: Tier 2 (only Tier 3 is claimable pre-1.0, per "How tiering works" below)

**Last Updated**: 2026-05-23

---

## How tiering works (SEP-1730)

The tiering system covers **both official and community-driven SDKs**, so `nexusphp/mcp-sdk` is eligible. Tiers are **request-based**: self-assess, open an issue in `modelcontextprotocol/modelcontextprotocol` with supporting evidence, pass the automated conformance suite, then the SDK Working Group approves and makes the final assignment.

- **Conformance is the gate.** Scored against **applicable required tests only**: the spec version the SDK targets, excluding pending/skipped tests, experimental-feature tests, and legacy back-compat tests (unless legacy support is claimed).
- **Relegation.** An SDK drops a tier if conformance tests on its latest stable release fail continuously for 4 weeks.
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
  - Reference: [Conformance Test Suite](https://github.com/modelcontextprotocol/conformance)
  - Evidence/Notes: not run. Server-mode conformance needs the Streamable HTTP transport (phase 5). Client-mode can run our client as a subprocess
  - Run: `npm run --silent tier-check -- --repo NexusPHP/mcp-sdk`

### Implementation Timeline

- [ ] **New Protocol Features Within 6 Months**
  - Reference: SEP-1730 Tier 2 requirement
  - Current Spec Version: 2025-11-25
  - Next Spec Release: 2026-07-28
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
  - Evidence/Notes: none yet. 0.x is pre-stable (breaking changes allowed in minors). A stable release means v1.0.0, which lands with the 2026-07-28 migration
  - Release Tag: none

### Documentation

- [x] **Basic Documentation for Core Features**
  - Reference: README, getting started guide
  - Coverage: Core server features, core client features
  - Evidence/Notes: README.md plus docs/getting-started.md, docs/server.md, docs/client.md, docs/transports.md, docs/architecture.md

- [ ] **Published Dependency Update Policy**
  - Reference: Document in repository (e.g., DEPENDENCY_POLICY.md)
  - Location: .github/dependabot.yml (automation only)
  - Covers: Security patches, minor updates, major updates
  - Evidence/Notes: dependabot configured, but no published policy document yet

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
  - Reference: [Conformance Test Suite](https://github.com/modelcontextprotocol/conformance)
  - Conformance Version: v0.1.x (SDK-tiering baseline v0.1.11 on 2026-01-23, latest stable v0.1.16 on 2026-03-27)
  - Evidence/Notes: _________________________
  - Conformance Score: ___%

- [ ] **Conformance example server + client**
  - Reference: not a standalone SEP-1730 requirement. This is the harness behind the conformance score above. Mirror the canonical fixtures in the conformance repo's [`everything-server.ts`](https://github.com/modelcontextprotocol/conformance/blob/main/examples/servers/typescript/everything-server.ts), per [SDK_INTEGRATION.md](https://github.com/modelcontextprotocol/conformance/blob/main/SDK_INTEGRATION.md)
  - Location: `examples/conformance-server.php` + `examples/conformance-client.php` (planned)
  - Canonical fixtures (see `everything-server.ts` for the full set): tools `test_image_content` / `test_audio_content` / `test_embedded_resource` / `test_multiple_content_types` / `test_tool_with_logging` / `test_tool_with_progress` / `test_elicitation` / `test_sampling` / `test_error_handling` / `json_schema_2020_12_tool`, resources `static-text` / `static-binary` / `template` / `watched-resource`, prompts `test_simple_prompt` / `test_prompt_with_arguments` / `test_prompt_with_image` / `test_prompt_with_embedded_resource`
  - Evidence/Notes: not started

### Implementation Timeline

- [ ] **New Protocol Features Before Spec Release**
  - Reference: SEP-1730 Tier 1 requirement, "before the new spec version release" (timeline agreed per release by feature complexity, not a fixed window)
  - Context: the 2026-07-28 RC-to-final window is ~10 weeks (RC locked 2026-05-21). Tier 1 requires building against the RC, not waiting for the final tag
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
  - Location/File: ROADMAP.md (0.x pre-stable, 1.0 post-migration)
  - Documents: Semantic versioning, release schedule, breaking changes policy
  - Evidence/Notes: versioning policy documented in ROADMAP.md, but no stable release yet

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
    - [ ] OAuth/Authentication flows (not implemented yet)
    - [x] Transport configuration (docs/transports.md)
    - [ ] Error handling
    - [ ] Best practices
  - Documentation Location: docs/
  - Evidence/Notes: core covered. Auth, error-handling, and best-practices guides pending

- [ ] **Published Dependency Update Policy**
  - Reference: SEP-1730 requirement
  - Location/File: _________________________
  - Covers:
    - [ ] Security patch timeline
    - [ ] Minor version update policy
    - [ ] Major version update policy
    - [ ] End-of-life policy for old versions
    - [ ] PHP version support matrix
  - Evidence/Notes: _________________________

### Quality Standards (internal gates, not SEP-1730 requirements)

- [x] **Static Analysis Passing (PHPStan Level 10)**
  - Reference: PHPStan
  - Command: `composer phpstan:check`
  - Evidence: No errors in main codebase
  - Notes: level 10 + strict rules, enforced in CI

- [x] **Code Style Compliance**
  - Reference: PHP-CS-Fixer, Nexus83 preset from Nexus CS Config
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

- [ ] **Version Number Consistency**
  - All packages released at same version
  - Location of version definition: git tag (composer.json has no version field, umbrella-only during 0.x)
  - Current Version: unreleased
  - Evidence/Notes: umbrella package only until 1.0 (per ROADMAP.md)

### Metadata & Discoverability

- [ ] **Package Published on Packagist**
  - Reference: Composer registry
  - Package Name: `nexusphp/mcp-sdk`
  - Link: https://packagist.org/packages/___________
  - Evidence/Notes: not published yet (no released tags)

- [ ] **GitHub Repository Properly Configured**
  - Repository URL: https://github.com/NexusPHP/mcp-sdk
  - Topics: mcp, model-context-protocol, sdk, php-sdk
  - Description: Clear and up-to-date
  - Evidence/Notes: repository is public. Topics and description to verify

---

## Conformance & Validation

### Pre-Submission Self-Assessment

- [ ] **Run Tiering Assessment Tool**
  - Reference: [SDK Tier Assessment Tool](https://github.com/modelcontextprotocol/conformance)
  - Command: `npm run --silent tier-check -- --repo NexusPHP/mcp-sdk --skip-conformance`
  - Output/Evidence: _________________________
  - Date Run: _________

- [ ] **Run Full Conformance Tests**
  - Reference: [MCP Conformance Framework](https://github.com/modelcontextprotocol/conformance)
  - Conformance Version: _________
  - Server Test Results: _________________________
  - Client Test Results: _________________________
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

## Notes & References

- **SEP-1730 Issue**: https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1730
- **SDK Working Group Meeting (Feb 11, 2026)**: https://github.com/modelcontextprotocol/modelcontextprotocol/discussions/2238
- **Conformance Test Framework**: https://github.com/modelcontextprotocol/conformance
- **SDK Integration Guide**: https://github.com/modelcontextprotocol/conformance/blob/main/SDK_INTEGRATION.md
- **Tiering Assessment Tool**: https://github.com/modelcontextprotocol/conformance/blob/main/.claude/skills/mcp-sdk-tier-audit/README.md
- **MCP Spec (2025-11-25)**: https://modelcontextprotocol.io/specification/2025-11-25

### General Notes

---

### Progress Summary

| Tier | Items Complete | Total Items | Status |
|------|---|---|---|
| Tier 3 | 1 | 1 | Met (claimable now) |
| Tier 2 | 2 | 8 | In progress |
| Tier 1 | 10 | 26 | In progress |
| **TOTAL** | **13** | **35** | **Tier 3 met, Tier 2 gated on v1.0.0** |
