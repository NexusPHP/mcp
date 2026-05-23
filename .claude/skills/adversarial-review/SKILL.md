---
name: adversarial-review
description: Project-local adversarial review for the Nexus MCP SDK. Spawns four parallel red-team subagents (spec faithfulness, concurrency, mutation gaps, edge-case bug hunt) and pre-seeds each with the repo's durable review conventions so they skip already-decided ground. Use after a non-trivial design landing, before merging hard-to-reverse changes, or when reviewing a phase/subsystem at steady state. Skip for mechanical refactors, formatting, dependency bumps.
argument-hint: "[optional scope, e.g. 'server runtime', 'transport layer', PR number; defaults to uncommitted working-tree changes]"
---

# Adversarial review (mcp-sdk project-local)

This is a project-local skill committed to the repo so any contributor running it gets the same red-team shape. It overrides the user-level `adversarial-review` skill (if any) when invoked from this working tree.

Goal: spawn fresh, narrowly-scoped subagents that explicitly oppose the in-flight work. The point is to counter the agreement drift that builds up within a single building session. A session that has been collaborating on a design has accumulated context that biases it toward "yes, this works." Asking that same session to "double-check itself" hits the same bias. Fresh adversarial subagents have none of that history.

If the scope is a small mechanical change (one renamed file, a dependency bump, a typo fix), defer to a single-agent review or skip review entirely. The four-agent shape is for steady-state subsystems or non-trivial design landings.

## Step 1: determine scope

If the contributor passed an argument, take it as the scope. Otherwise default to "uncommitted working-tree changes."

Run, in order:

```bash
git status --short
git diff --stat
git diff
```

If the scope is a branch (not just uncommitted), use `git diff <base>...HEAD`.

If the scope is a phase or subsystem ("server runtime", "transport layer", "JSON-RPC parser") rather than a diff, treat it as steady-state: identify the file set via `find src/<area> -name '*.php'` plus matching `tests/<area>`. There is no diff to read in this mode. The four agents review the production code as it stands.

## Step 2: brief yourself before briefing the subagents

Each subagent starts with **zero context** from this session. Anything the contributor already settled in conversation but the agent does not know is wasted critique. Before spawning, harvest design context.

Skim:

- The recent conversation for decisions the contributor explicitly approved.
- Project `CLAUDE.md` and `.github/copilot-instructions.md` for repo conventions.
- Any in-repo design docs or roadmap files (e.g. under `docs/`).
- If the contributor's Claude session has memory configured, scan their MEMORY index for relevant project notes (cancellation-registry deferral status, RC pin, post-Path-A roadmap, etc.).

Identify three to five **design intentions that should NOT be flagged** as bugs and add them to the pre-seed block below for each subagent. Common categories: deferred initiatives (cancellation registry, Streamable HTTP, task layer), narrowing patterns that PHPStan requires (per-call `Assert::isMap` assertions where the interface declares no signature), encoding paths that deliberately diverge between `toArray()` and `jsonSerialize()` for empty-object slots.

## Step 3: durable repo-wide constraints (inline pre-seed)

The block below is repo-wide review-time conventions. **Include it verbatim in every subagent prompt** so all four frames respect the same boundaries regardless of which contributor invokes the skill. These constraints are inlined here (rather than referenced via memory paths or external docs) so the skill is self-contained for any contributor.

> ## Already-decided review-time constraints (do NOT re-litigate)
>
> **Schema-stability rule.** Schema classes under `src/Core/Schema/**` are stable value objects locked to the MCP spec shape. Internal micro-DRY refactors (helper extraction, trait lifting, sealed annotations, interface property hooks, abstract base lifting) are out of scope **unless they fix a real envelope-encoding bug**. The duplication is byte-identical except for class-name prefixes, the schema tracks the spec exactly and rarely changes, and each abstraction adds a layer the reader must follow. Dead-code removal in schema classes is allowed, but only after verifying no test, no direct-encode path, and no PHPStan-narrowing role exercises the "dead" branch.
>
> **Load-bearing patterns that look dead but are not.** Do NOT propose removal of any of these:
>
> 1. **`CancelledNotification::jsonSerialize` empty-object substitution.** The override substitutes `\stdClass` for empty `params`. Looks dead because the spec mandates `requestId`. Load-bearing: the PHP constructor permits `new CancelledNotificationParams()` with no `requestId`. Without the `{}` substitution the JSON envelope drops the `params` key entirely and breaks the round-trip `fromArray` guard. Three tests pin this.
>
> 2. **`EnumValueValidator::parse` try/catch on `\TypeError`.** Looks dead because the outer `is_string || is_int` guard prevents wrong scalar types. Load-bearing: with `strict_types=1`, passing a numeric string to an int-backed enum's `tryFrom` throws `TypeError`. The catch converts it to a meaningful `ExpectationFailedException`. Removing the catch leaks raw `TypeError`.
>
> 3. **`Annotations::jsonSerialize` empty-object substitution.** Looks dead because all parent consumers filter the slot when empty. Load-bearing for the direct-encode case: `json_encode(new Annotations())` standalone would emit `[]` instead of `{}` without the substitution.
>
> 4. **`InMemoryTransport` per-envelope `isMap` assertion.** Looks structurally redundant at runtime since `toArray()` returns a string-keyed map by contract. Load-bearing for PHPStan narrowing: `JsonRpcMessage::toArray()` has no declared signature on the interface (reached via soft `assert(method_exists(...))`), so PHPStan types the envelope as `mixed`. The `isMap` chain narrows it to `array<string, mixed>` so downstream `$peer->receive($envelope)` typechecks.
>
> 5. **`NullLogger` short-circuit pattern.** Do NOT propose `if ($logger instanceof NullLogger) return;` in transport / dispatch log call-sites. `NullLogger::debug()` IS the no-op. An `instanceof NullLogger` check at the call site costs about the same as the no-op method dispatch. Callers should call `$logger->debug(...)` unconditionally and pay no branching cost.
>
> 6. **`JsonRpcMethodRegistry::requests()` and `notifications()` map ordering.** Looks accidental compared to class-name alphabetical. Actually sorted by the evaluated method literal (`completion/complete`, `elicitation/create`, `initialize`, ...) and enforced by `JsonRpcMethodRegistryTest::testRequestsAreSortedByEvaluatedMethodKey`. The PHPDoc on both accessors documents this.
>
> **Deferred initiatives** (flag races / gaps that will matter once these land, but do not flag their absence):
>
> - Cancellation registry. In-flight handlers cannot be cancelled today. Port is mechanical once consumers exist.
> - Streamable HTTP transport. Blocked on the 2026-06-30 RC pin along with the task-layer rebuild.
> - Server-initiated request paths (sampling, elicitation). `RequestBoundSender::sendRequest` throws `\BadMethodCallException` until wired.

If the contributor has additional do-not-critique context from the conversation (specific renames, intentional duplication, behavior covered in conversation), append those under "Session-specific do-not-critique" so the agent sees both.

**Harvest the actual composer script names.** Do not invent by analogy.

```bash
jq -r '.scripts | keys[]' composer.json
```

Map the names verbatim into per-agent prompts where the agent is expected to verify a hypothesis. The scripts that typically matter:

- `composer test:all`: full suite (cs + phpstan + doc lints + auto-review + static-analysis + unit + full-tree mutation)
- `composer test:with-untracked`: same suite, mutation step is diff-based
- `composer test:core`, `test:client`, `test:server`: per-suite groups
- `composer test:auto-review`: AutoReview group (cross-cutting invariants)
- `composer test:stan`: PHPStan type-inference lock-in (the `static-analysis` PHPUnit group)
- `composer phpstan:check`: standalone PHPStan run
- `composer mutation:filter`: diff-based Infection (picks up untracked files). In a steady-state review of a clean tree the diff is empty, so this tests nothing. Use the scoped form below instead.
- `composer mutation:check`: bare full-tree Infection (avoid this form, 7+ minutes, never run full-tree from inside a review).
- `composer mutation:check -- --filter="src/Path/To/File.php"`: scoped single-file Infection, and the only authoritative verifier. Fast (about 30s for one file) and, crucially, it runs with `--static-analysis-tool=phpstan`, so it matches the gate. See the SA-flag lesson in the A3 frame. Comma-separate paths to batch a few suspects.
- `composer schema:generate`: regenerate `latest-schema.json` and `sorted-schema.json`

## Step 4: choose frames

The four default frames are below. **Pick the ones that fit the scope.** Do not auto-spawn all four for a small diff.

Rule of thumb:

- **Spec faithfulness (A1)**: include for anything touching `src/Core/Schema/`, `src/Core/JsonRpc/`, `src/Server/Dispatch/`, `src/Server/Tool/`, or capability advertising.
- **Concurrency / runtime correctness (A2)**: include for `src/Server/`, `src/Client/`, `src/Core/Transport/`.
- **Test / mutation gaps (A3)**: include whenever a code change ships without a test diff that matches it, or when the change is in a guard/branch-heavy area (parsers, dispatchers, validators).
- **Edge-case / adversarial-peer bug hunt (A4)**: include for anything that sits on the network/IPC boundary (parsers, transports, framing, dispatch entry points).

For a tiny diff (one file, one method), drop to two frames or skip the skill entirely.

## Step 5: spawn agents in parallel

Use the `Agent` tool with `subagent_type: "general-purpose"`. Spawn all selected frames in a single message containing multiple `Agent` tool calls so they run concurrently. Use `run_in_background: true` if the contributor has other work in flight, otherwise foreground.

Each agent prompt MUST include all of the following (in addition to its frame-specific body):

1. **Adversarial framing, blunt.** Example: "Your job is to find what is **wrong**, not to praise what is right. Assume there are bugs and that the implementer (a senior engineer) has blind spots. Be sceptical."

2. **Concrete file list / scope.** New files in one bucket, modified in another. For steady-state review, list the directories and any files of unusual interest.

3. **The verbatim pre-seed block from Step 3.** Plus any session-specific do-not-critique items harvested in Step 2.

4. **Frame-specific failure-mode checklist.** Concrete, not abstract. Frame templates below.

5. **Verification permission with git-safety guardrails.** Copy verbatim into every subagent prompt:

    > **Git safety rules:**
    >
    > - Do not run `git stash` in any form (push, apply, pop, drop, create, branch). Pre-existing stashes may belong to unrelated work.
    > - Do not run `git checkout -- <file>`, `git restore -- <file>` or `--staged`, `git reset` (any mode), `git revert`, `git clean`, `git rm`, or `git mv` against the working tree.
    > - Do not modify, create, or delete files in the working tree.
    > - For inspection, use only `git diff`, `git show`, `git log`, `git cat-file`, `git blame`.
    > - If you need to run tests against a different state, create an isolated worktree: `git worktree add /tmp/adversarial-review-<random> <ref>`, run tests inside it, then `git worktree remove /tmp/adversarial-review-<random>`. Never mutate the primary working tree.
    > - Do not run the bare full-tree `composer mutation:check` (7+ minutes). For a targeted check use the scoped, static-analysis-enabled form: `composer mutation:check -- --filter="src/Path/To/File.php"` (comma-separate paths to batch a few). Do NOT verify with a raw `tools/vendor/bin/infection --filter=...` lacking `--static-analysis-tool=phpstan`: the SA flag lives in the composer script, not `infection.json5`, so the raw binary misreports every SA-killed mutant as a false escape. An Infection summary line reading `caught by Static Analysis` is a KILL, not an escape.

6. **Ground every finding on the current code and tests, not on hypothesis.** Copy verbatim into every subagent prompt:

    > **Ground every finding. Do NOT speculate.**
    >
    > Before writing a finding, prove it against the actual code and tests as they stand right now. A finding that boils down to "predicted X would escape" or "if a mutator did Y this assertion wouldn't catch it" is worth zero unless you have actually checked.
    >
    > Concretely:
    >
    > - For "this branch is unreachable" / "this default arm is dead" claims: read every caller of the function and the relevant test cases. State the call sites you inspected.
    > - For "this test wouldn't kill mutant X" claims: run the scoped, static-analysis-enabled command `composer mutation:check -- --filter="src/Path/To/File.php"` (see the A3 SA-flag lesson) and report whether X actually escaped, or trace through the test inputs to show the mutant's behaviour is unobservable. Do NOT use a raw `tools/vendor/bin/infection --filter=...` without `--static-analysis-tool=phpstan`, which misreports SA-killed mutants as escapes. A line reading `caught by Static Analysis` is a kill. Quote the assertion (or the SA kill) that settles it.
    > - For "API surface drifts from spec" claims: cite the spec line (`latest-schema.json` path or upstream `schema.ts` link).
    > - For "concurrency race" claims: trace the specific event-loop ordering. State which fiber is in which state at each step.
    >
    > If you cannot ground the finding, mark it `severity: low` and prefix the headline with **`UNVERIFIED:`**. The contributor would rather see five grounded findings and one unverified hunch than ten unverified hunches. Treat unverified findings as the **exception**, not the default. The prior session burned hours triaging mutation-noise predictions that did not actually escape; do not repeat that.
    >
    > It is fine, even good, to report a frame as "no findings" when the system genuinely holds up under your inspection. Saying "I checked X, Y, Z and they all hold" beats inventing critique to fill quota.

7. **Output format.** Demand structure:

    ```text
    **Finding N (severity: critical|high|medium|low):** one-sentence claim
    `file:line` markdown link, explanation, with offending snippet
    Repro / proof: concrete input that surfaces the divergence (envelope, byte sequence, call sequence). If you cannot construct one, downgrade severity by one notch.
    Grounding: which code paths you read, which tests you ran, which mutation:filter output you inspected. One line.
    Suggested fix: one sentence. Do not write code.
    ```

    File and line references MUST use markdown link syntax: `[file.php:42](src/file.php#L42)`.

8. **Per-agent finding cap: 10.** If more are found, list the titles and let the contributor request expansion. Hard cap so triage is not drowned.

9. **Honesty instruction.** "If you find nothing in this frame, say so honestly. Do not manufacture critique. A 'no real findings' report is also useful information."

10. **Length cap: 1500 words per agent.**

## Frame templates

### A1: Spec faithfulness

Sources of truth, in priority order:

1. `latest-schema.json` at repo root.
2. `sorted-schema.json` (class-to-schema map).
3. Upstream TypeScript spec at `https://github.com/modelcontextprotocol/modelcontextprotocol` (fetch via WebFetch only if the JSON files do not answer the question).
4. `JsonRpcMethodRegistry` (authoritative for what server/client must understand).

Hunt for:

- Method coverage gaps. Every spec-defined method has a handler contract? Capabilities advertised only when supported?
- Required vs optional params drift.
- Error code drift (must use `ProtocolErrorCode` values for the correct failure modes).
- Notification semantics (notifications MUST NOT produce a response).
- Cancellation semantics (in-flight only, ignore unknown ids, MUST NOT cancel `initialize`).
- Capability-advertising honesty (advertised then handler wired, in both directions).
- Initialize handshake (protocolVersion negotiation, capability echo).
- JSON-RPC envelope shape on the protocol layer (id is string|int|null, method is string, params is object|array|omitted, result/error mutually exclusive, `jsonrpc: "2.0"` required).
- Batch handling (spec 2025-11-25 REMOVED batches; receiver MUST reject).
- Stdio framing (newline-delimited JSON, no embedded newlines, exactly one envelope per line, CRLF/BOM/empty-line handling).

### A2: Concurrency / runtime correctness

The SDK uses amphp v3 + Revolt. Transport API is sync-looking on the outside, async internally.

Hunt for:

- Race conditions in in-flight tracking (mutation from multiple coroutines, track-then-untrack interleave, close-signal-during-await).
- Drain ordering (transport close vs pending dispatches, window between message-received and message-tracked).
- Coroutine leaks (`async(...)` whose future is dropped, handler exceptions that escape the async wrapper).
- Backpressure / unbounded queues (read loop flow control, in-flight set size, logger buffers).
- Transport lifecycle (open then receive loop then close, send-after-close, double-close, receive-after-close-started).
- Cancellation propagation (in-flight handler visibility, spec conformance).
- LoggingLevelGate concurrency (level/levelIndex torn read, future suspending refactors).
- Stdio framing edge cases (mid-frame EOF, partial line on shutdown, broken pipe, multi-read frames).
- Error path discipline (handler throw isolation, JsonRpcException during parse).
- Logger interactions (slow logger as backpressure source).

### A3: Test / mutation gaps

The repo enforces 100% MSI via Infection. **Do not run the bare full-tree `composer mutation:check`** (7+ min). Predict survivors by reading source and tests, then **verify** any suspect mutation gap with the scoped, static-analysis-enabled command before reporting it: `composer mutation:check -- --filter="src/Path/To/File.php"` (about 30s for one file). Comma-separate a few paths to batch them.

**SA-flag lesson (a real prior mistake, do NOT repeat it).** The repo's gate runs `infection --with-uncovered --static-analysis-tool=phpstan`, so a large share of mutants are killed by PHPStan rather than by a test assertion. That `--static-analysis-tool=phpstan` flag lives in the composer script, NOT in `infection.json5`, so a raw `tools/vendor/bin/infection --filter=<File>.php` invocation silently runs without static analysis and reports every SA-killed mutant as a false escape. A previous run of this skill did exactly that: it reported four "escaped" mutants and an alarming "the released tree is not at 100% MSI" claim, all of which were this artefact. Re-running through `composer mutation:check -- --filter=...` showed every one `caught by Static Analysis`, MSI 100%. Always verify through the composer scoped form (or, only if you must use the raw binary, add `--static-analysis-tool=phpstan`). Treat a `caught by Static Analysis` line as a KILL.

This frame has the highest false-positive rate of all four. The prior session closed six "predicted survivor" findings as won't-fix after running scoped mutation on the implicated files and seeing the predicted mutants killed in milliseconds (often by tests the predictor had not read). Pattern-matching for "this branch looks unguarded" without checking is exactly what produces noise. **Run the tool. If the mutant is killed, drop the finding.**

Hunt for the mutator patterns that survive when nothing asserts on them:

- Branches with no assertion (`if (! $valid) { return; }` where every test passes valid input).
- Boundary conditions (`<` vs `<=`, `>` vs `>=`, off-by-one).
- Equivalent default arms in match/switch (mutation swaps explicit arm to default and survives).
- Throw expressions vs nullable returns (`?? throw new X()` mutated to drop the throw).
- Cached/derived state staleness (write updates two slots, read uses one).
- String literals in error paths (unanchored `expectExceptionMessage` substring matches).
- Return-value mutations (test asserts side effect but not return).
- Same-shape branches (two arms produce identical observables).
- Defensive guards on already-validated input (helper assertion never exercised).
- Untested error-message wording on dispatch/parser paths.

For each suspect: predict the specific Infection mutator that would survive AND the assertion that would kill it. Then run scoped mutation testing on that file and report the actual outcome. If the prediction was wrong (mutant killed), drop the finding. If you cannot run mutation testing for some reason, mark the finding `UNVERIFIED` per Step 5 rule 6.

Per the project convention, error-path tests use `expectExceptionMessageMatches('/^anchored …$/')` with both `^` and `$` anchors. Substring-matching `expectExceptionMessage` is acceptable when the wording is generated from a literal and scoped mutation shows the source's string mutators are still killed; do not flag it as a gap on appearance alone.

### A4: Edge-case / adversarial-peer bug hunt

Threat model: treat the peer (client to server, server to client) as adversarial. They control the byte stream, JSON structure, ids, methods, params, timing. Do NOT model malicious handler implementers. Assume handlers are correct and focus on the framing/dispatch/parse layer.

Hunt for:

- Parser DoS (single envelope O(n²)+ to parse).
- Memory exhaustion (unbounded single envelope, unbounded in-flight, unbounded log buffers).
- Type confusion at the parser boundary (`id: true`, `id: 1.5`, `id: {}`, `id: []`; correct rejection with `-32600` and `id: null`).
- Reused request ids (concurrent dispatch with same id, peer-side response misattribution).
- id collisions across types (`id: 1` vs `id: "1"`).
- Notification-dressed-as-request and vice versa.
- Method confusion (trailing space, case, null byte, RTL override).
- Stdio framing attacks (mid-frame EOF, giant single line, binary injection, CRLF/BOM/LFCR, log injection via control chars in echoed messages).
- Unicode + JSON-escape edge cases (paired/lone surrogates, escaped null bytes, RTL overrides in method names).
- Initialize bypass (pre-initialize calls beyond `ping` and `initialize`).
- Capability spoof (unknown keys, missing required, additional-properties tolerance per spec).
- Response-to-no-request (orphan envelope handling).
- Server-initiated request, peer disconnect (pending future cleanup).
- Logger as attack surface (filling log destinations, injection via newlines in formatted output).

## Step 6: triage, then report

When all agents return, **do not just relay raw output**. You MUST consolidate into `ADVERSARIAL_REVIEW.md` at the repo root, and you MUST draft that file before reporting to the contributor in chat, so the findings survive a context loss or compaction. This is mandatory, not optional, even for a clean review with few findings. The file contains:

1. Brief context block (agents spawned, scope, date).
2. Findings grouped by **severity** (Critical / High / Medium / Low), de-duplicated across agents. When the same root cause surfaces from multiple frames, merge with a `[A1, A2]` tag.
3. "Already-decided / out-of-scope" block at the end so the contributor can audit what was deliberately not flagged.
4. **Triage section** assigning each finding to one of:
    - **Phase A: blockers for current phase landing**: spec violations, real bugs, pre-auth DoS vectors. Must fix now.
    - **Phase A+: quick wins to bundle**: single-file, low controversy, small net diff.
    - **Phase B: same-release follow-up batch**: needs design or affects multiple files.
    - **Phase C: defer (blocked or out-of-phase)**: blocked on a deferred initiative (cancellation registry, RC pin, Client work).
    - **Phase D: verify or skip**: low-value, already covered, or needs a focused test to confirm.

5. Recommended batch shape (how to land Phase A, A+, B as discrete PRs).

`ADVERSARIAL_REVIEW.md` is a working artefact, not a committed deliverable. Leave it untracked. Delete it once findings are addressed. Before deletion, recover any durable conventions to the appropriate in-repo location (CLAUDE.md, this skill, or a dedicated docs page).

When reporting to the contributor in chat:

1. **Honest meta-assessment** in one or two sentences: did the exercise earn its keep, or was it mostly noise?
2. **Triage summary** by phase with finding counts and one-line headlines.
3. **Specific ask**: "Want me to apply Phase A findings?" Wait for explicit approval. Do NOT apply fixes autonomously. The contributor triages and approves before any change.

## Doc-linter compatibility

The repo ships three project-scoped `PostToolUse` hooks under `.claude/hooks/`, wired in `.claude/settings.json`, that fire on every `Write` or `Edit`:

- `no-wire.sh`: blocks the word "wire" in authored source and comments. Prefer "envelope" or "message" (the schema-layer code already uses both terms).
- `no-em-dash.sh`: blocks em-dashes (U+2014) in authored text. Use periods, colons, parens, or recast.
- `no-prose-semicolon.sh`: flags semicolons used as prose connectors in PHPDoc, line comments, and quoted string literals. Use a period if the semicolon is prose. Leave it alone if it is intentional (regex, SQL, CSS, shell, env-var lists).

Do not assume these skip `.claude/` paths. The no-prose-semicolon hook was observed firing while editing this skill itself, so author skill text by the same rules the hooks enforce: no em-dashes, no prose semicolons, and none of the blocked vocabulary. The consolidated `ADVERSARIAL_REVIEW.md` lives at the repo root and WILL trip them if drafted carelessly. If a hook blocks an artefact emission, read the hook output, adjust the text, and re-emit. Do not work around hooks.

## When this skill is appropriate

- After a non-trivial design landing (server-shell rebuild, transport refactor, parser overhaul).
- Before merging hard-to-reverse changes (public API, schema, anything downstream consumers depend on).
- When capping off a phase or subsystem so it can be declared "done" with confidence.
- When the building session has been running long enough that agreement drift is a real concern.

## When to skip

- Mechanical refactors (renames, formatting, dependency bumps).
- Already through external review.
- Trivial changes (one-line fixes, single typo edits).
- The session is short and clearly fresh on the work.
- Pure schema-VO edits that the schema-stability rule already governs.

## Notes on prompt strength

Stronger framing produces stronger critique. *"Be sceptical"* is okay; *"Assume this subsystem has at least three real bugs in each frame and find them"* is better at flushing out concerns the agent would otherwise dismiss as nitpicks. Match framing to confidence that real findings exist. Overshoot if anything.

The four-agent shape works precisely because each frame is narrow. Do not stack multiple frames into one agent ("security + performance + API design" all at once produces surface-level critique across the board). If a frame is not applicable to the scope, drop it. Do not merge it into another.
