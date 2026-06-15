# Shared review pre-seed (mcp-sdk)

Repo-wide, review-time constraints shared by the project-local `/adversarial-red-team-review` skill and `/simplify` runs. Both read this file and include the "Already-decided" and "Verified-correct" blocks verbatim in every subagent prompt, so every frame respects the same boundaries and skips already-settled ground. Committed to the repo, so it is self-contained for any contributor. When a review settles that something is correct-as-is or deferred, add it here so the next review does not re-litigate it.

## Already-decided review-time constraints (do NOT re-litigate)

**Schema-stability rule.** Schema classes under `src/Core/Schema/**` are stable value objects locked to the MCP spec shape. Internal micro-DRY refactors (helper extraction, trait lifting, sealed annotations, interface property hooks, abstract base lifting) are out of scope **unless they fix a real envelope-encoding bug**. The duplication is byte-identical except for class-name prefixes, the schema tracks the spec exactly and rarely changes, and each abstraction adds a layer the reader must follow. Dead-code removal in schema classes is allowed, but only after verifying no test, no direct-encode path, and no PHPStan-narrowing role exercises the "dead" branch.

**Load-bearing patterns that look dead but are not.** Do NOT propose removal of any of these:

1. **`CancelledNotification::jsonSerialize` empty-object substitution.** The override substitutes `\stdClass` for empty `params`. Looks dead because the spec mandates `requestId`. Load-bearing: the PHP constructor permits `new CancelledNotificationParams()` with no `requestId`. Without the `{}` substitution the JSON envelope drops the `params` key entirely and breaks the round-trip `fromArray` guard. Three tests pin this.

2. **`EnumValueValidator::parse` try/catch on `\TypeError`.** Looks dead because the outer `is_string || is_int` guard prevents wrong scalar types. Load-bearing: with `strict_types=1`, passing a numeric string to an int-backed enum's `tryFrom` throws `TypeError`. The catch converts it to a meaningful `ExpectationFailedException`. Removing the catch leaks raw `TypeError`.

3. **`Annotations::jsonSerialize` empty-object substitution.** Looks dead because all parent consumers filter the slot when empty. Load-bearing for the direct-encode case: `json_encode(new Annotations())` standalone would emit `[]` instead of `{}` without the substitution.

4. **`InMemoryTransport` per-envelope `isMap` assertion.** Looks structurally redundant at runtime since `toArray()` returns a string-keyed map by contract. Load-bearing for PHPStan narrowing: `JsonRpcMessage::toArray()` has no declared signature on the interface (reached via soft `assert(method_exists(...))`), so PHPStan types the envelope as `mixed`. The `isMap` chain narrows it to `array<string, mixed>` so downstream `$peer->receive($envelope)` typechecks.

5. **`NullLogger` short-circuit pattern.** Do NOT propose `if ($logger instanceof NullLogger) return;` in transport / dispatch log call-sites. `NullLogger::debug()` IS the no-op. An `instanceof NullLogger` check at the call site costs about the same as the no-op method dispatch. Callers should call `$logger->debug(...)` unconditionally and pay no branching cost.

6. **`JsonRpcMethodRegistry::requests()` and `notifications()` map ordering.** Looks accidental compared to class-name alphabetical. Actually sorted by the evaluated method literal (`completion/complete`, `elicitation/create`, `initialize`, ...) and enforced by `JsonRpcMethodRegistryTest::testRequestsAreSortedByEvaluatedMethodKey`. The PHPDoc on both accessors documents this.

**Deferred initiatives** (flag races / gaps that will matter once these land, but do not flag their absence):

- Cancellation registry. In-flight handlers cannot be cancelled today. Port is mechanical once consumers exist.
- Streamable HTTP transport. Blocked on the 2026-06-30 RC pin along with the task-layer rebuild.
- Server-initiated request paths. Outbound notifications work (`RequestBoundSender::sendNotification`, used for progress). Outbound requests do not yet: `RequestBoundSender::sendRequest` throws `OutboundRequestsNotSupportedException` until the path is built. Elicitation (`elicitation/create`) is the surviving server-initiated request after the SEP-2577 deletions. Do not flag the unimplemented `sendRequest` as a bug.
- Client success-response double-parse (`/simplify` S6). The client peek-parses to recover the `RequestId`, then re-parses with the result class. A single-parse parser entry point is deferred to the HTTP phase, where the MRTR `resultType` discriminator may remove the peek. Do not flag the double-parse as inefficiency.

**Deleted features (do NOT flag their absence as a spec gap).** Sampling, Roots, and Logging are intentionally absent per SEP-2577: a greenfield SDK SHOULD NOT adopt features the spec marks deprecated. The server advertises only `completions`, `prompts`, `resources`, and `tools`, registers no `logging/setLevel` handler, and emits no `notifications/message`. The `LoggingLevel` enum and `RequestMetaObject.logLevel` survive round-trip-only for the deprecated `_meta` field. Do not flag the missing logging, sampling, or roots capabilities or handlers.

## Verified-correct (do NOT re-flag as bugs)

These were raised by a prior review, investigated, and confirmed correct-as-is. Do not re-raise them without new evidence that the underlying code changed.

- **`StdioClientTransport` killing the subprocess without `join()` is accepted.** On close it closes stdin and calls `Process::kill()` without `join()`-ing to reap the exit status. amphp reaps the handle on GC, so there is no deterministic leak. An active-reap (`async(fn() => $process->join())->ignore()`) is deliberately not added: it is not unit-testable to the repo's 100% MSI bar (the `Process` is built internally, `->ignore()` swallows the result), it is stdio-specific, and the benefit is marginal. Revisit only if a real leak surfaces with a long-lived parent spawning many short-lived servers.
