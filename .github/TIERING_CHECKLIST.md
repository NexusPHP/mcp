# PHP SDK Tiering System Checklist

Based on [SEP-1730: SDKs Tiering System](https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1730)

**Target Tier**: ___ (Tier 1 / Tier 2 / Tier 3)

**Last Updated**: 2026-03-02

---

## Tier 3: Experimental

> Early-stage or specialized SDKs exploring the protocol space.

- [ ] **SDK Implementation Started**
  - Reference: Basic MCP protocol support available
  - Notes: _________________________

---

## Tier 2: Commitment to be Fully Supported

> Active implementations working toward full protocol support.

### Feature Completeness

- [ ] **80% Conformance Tests Pass**
  - Reference: [Conformance Test Suite](https://github.com/modelcontextprotocol/conformance)
  - Evidence/Notes: _________________________
  - Run: `npm run --silent tier-check -- --repo modelcontextprotocol/php-sdk`

### Implementation Timeline

- [ ] **New Protocol Features Within 6 Months**
  - Reference: SEP-1730 Tier 2 requirement
  - Current Spec Version: 2025-11-25
  - Next Spec Release: _________
  - Evidence/Notes: _________________________

### Maintenance & Issue Management

- [ ] **Active Issue Tracking & Management**
  - Reference: GitHub issues configured and actively monitored
  - Evidence/Notes: _________________________

- [ ] **At Least One Stable Release**
  - Reference: Published release with stable API
  - Evidence/Notes: _________________________
  - Release Tag: _________________________

### Documentation

- [ ] **Basic Documentation for Core Features**
  - Reference: README, getting started guide
  - Coverage: Core server features, core client features
  - Evidence/Notes: _________________________

- [ ] **Published Dependency Update Policy**
  - Reference: Document in repository (e.g., DEPENDENCY_POLICY.md)
  - Location: _________________________
  - Covers: Security patches, minor updates, major updates
  - Evidence/Notes: _________________________

### Commitment & Roadmap

- [ ] **Published Roadmap Showing Intent to Achieve Tier 1**
  - Reference: ROADMAP.md or GitHub project board
  - Location: _________________________
  - Evidence/Notes: _________________________

---

## Tier 1: Fully Supported

> SDKs in this tier provide full protocol implementation and are well supported.

### Feature Completeness

- [ ] **100% Conformance Tests Pass**
  - Reference: [Conformance Test Suite](https://github.com/modelcontextprotocol/conformance)
  - Conformance Version: 0.11+ (as of Feb 2026)
  - Evidence/Notes: _________________________
  - Conformance Score: ___%

- [ ] **Everything Server Implementation**
  - Reference: Example server covering all MCP features
  - Location: `examples/everything-server.php` or similar
  - Features Covered:
    - [ ] Tool "say_hello" - simple text output
    - [ ] Tool "show_image" - image output
    - [ ] Tool "tool_with_logging" - structured output with logging events
    - [ ] Tool "tool_with_notifications" - structured output with notifications
    - [ ] Resources with templates
    - [ ] Prompts
    - [ ] Completions
  - Evidence/Notes: _________________________

### Implementation Timeline

- [ ] **New Protocol Features Before Spec Release**
  - Reference: SEP-1730 Tier 1 requirement - "before the new spec version release"
  - Context: 2-week window between Release Candidate and full release
  - Next Major Spec Release: _________
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
  - Location: _________________________
  - Evidence/Notes: _________________________

- [ ] **Stable Release & Versioning Clearly Documented**
  - Reference: Published versioning policy
  - Location/File: _________________________
  - Documents: Semantic versioning, release schedule, breaking changes policy
  - Evidence/Notes: _________________________

### Documentation

- [ ] **Comprehensive Documentation with Examples for All Features**
  - Reference: API docs, guides, tutorials
  - Coverage:
    - [ ] Server implementation guide
    - [ ] Client implementation guide
    - [ ] Tool registration & handling
    - [ ] Resource management
    - [ ] Prompt handling
    - [ ] OAuth/Authentication flows
    - [ ] Transport configuration
    - [ ] Error handling
    - [ ] Best practices
  - Documentation Location: _________________________
  - Evidence/Notes: _________________________

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

### Quality Standards

- [ ] **Static Analysis Passing (PHPStan Level 10)**
  - Reference: PHP-CS-Fixer, PHPStan
  - Command: `composer phpstan:check`
  - Evidence: No errors in main codebase
  - Notes: _________________________

- [ ] **Code Style Compliance**
  - Reference: Nexus83 preset from Nexus CS Config
  - Command: `composer cs:check`
  - Evidence: All files compliant
  - Notes: _________________________

- [ ] **Full Test Coverage**
  - Reference: PHPUnit tests
  - Coverage Target: 80%+
  - Command: `composer test:all`
  - All Tests Pass: Yes / No
  - Notes: _________________________

---

## Supporting Documentation

### Architecture & Code Quality

- [ ] **CONTRIBUTING.md Updated**
  - Location: Repository root
  - Covers: Development setup, testing, code standards, PR process
  - Evidence/Notes: _________________________

- [ ] **CODE_OF_CONDUCT.md Present**
  - Location: Repository root
  - Evidence/Notes: _________________________

- [ ] **Proper Exception Handling**
  - Reference: All exceptions extend package-specific base exception class
  - Base Classes:
    - [ ] `Nexus\Core\Exception`
    - [ ] `Nexus\Server\Exception`
    - [ ] `Nexus\Client\Exception`
  - Evidence/Notes: _________________________

### Release Management

- [ ] **Changelog Maintained (CHANGELOG.md)**
  - Location: Repository root
  - Format: Semantic versioning compatible
  - Evidence/Notes: _________________________

- [ ] **Version Number Consistency**
  - All packages released at same version
  - Location of version definition: _________________________
  - Current Version: _________________________
  - Evidence/Notes: _________________________

### Metadata & Discoverability

- [ ] **Package Published on Packagist**
  - Reference: Composer registry
  - Package Name: `nexusphp/mcp-sdk`
  - Link: https://packagist.org/packages/___________
  - Evidence/Notes: _________________________

- [ ] **GitHub Repository Properly Configured**
  - Repository URL: https://github.com/NexusPHP/mcp-sdk
  - Topics: mcp, model-context-protocol, sdk, php-sdk
  - Description: Clear and up-to-date
  - Evidence/Notes: _________________________

---

## Conformance & Validation

### Pre-Submission Self-Assessment

- [ ] **Run Tiering Assessment Tool**
  - Reference: [SDK Tier Assessment Tool](https://github.com/modelcontextprotocol/conformance)
  - Command: `npm run --silent tier-check -- --repo modelcontextprotocol/php-sdk --skip-conformance`
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

- [ ] **PR Submission Ready for MCP Org**
  - Target Repo: modelcontextprotocol/modelcontextprotocol
  - PR Format: Tier assessment table with evidence links
  - Reference: [Tiering Submission Process](https://github.com/modelcontextprotocol/modelcontextprotocol/discussions/2238)
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
| Tier 3 | _ | 1 | _ |
| Tier 2 | _ | 8 | _ |
| Tier 1 | _ | 28 | _ |
| **TOTAL** | **_** | **37** | **_** |
