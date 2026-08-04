# Conformance harness

Runs the official [`modelcontextprotocol/conformance`][suite] suite against this SDK and scores the
result. The referee acts as an MCP client, drives the fixture in [`EverythingServer.php`](EverythingServer.php)
over Streamable HTTP, and checks every response against the spec.

[suite]: https://github.com/modelcontextprotocol/conformance

## Running it

```bash
composer conformance:server            # server mode, active suite
composer conformance:client            # client mode
composer conformance:score             # score the last run
composer conformance:badge             # rewrite the README score badges
```

To try a different referee without editing the pin:

```bash
CONFORMANCE_VERSION=0.2.0-alpha.9 ./conformance/run-server.sh --suite all
```

Both runners pass arguments straight through to the referee:

```bash
./conformance/run-server.sh --suite all               # adds draft and pending scenarios
./conformance/run-server.sh --scenario tools-list -v  # one scenario, verbose
./conformance/run-client.sh --scenario tools_call
```

The ten `tasks-*` scenarios are tagged `[extension]` upstream, which keeps them out of every
suite, `all` included. They run individually, and `--force` is required because the tag also makes
the referee consider them inapplicable at the pinned spec version:

```bash
./conformance/run-server.sh --scenario tasks-lifecycle --force
```

`tasks-status-notifications` is a SKIPPED placeholder upstream, pending its subscriptions/listen
rewrite. The other nine pass against [`TasksServer.php`](TasksServer.php).

The two modes invert. In **server mode** the referee is the client, so `run-server.sh` boots
[`server.php`](server.php) first and tears it down after. In **client mode** the referee is the
server: it stands a mock up per scenario and spawns [`client.php`](client.php) once per scenario,
so nothing needs starting first. Both write into the same `results/` directory, so score a run
before starting the other.

Needs Node (for `npx`) and a free port. `PORT` and `HOST` override the default `127.0.0.1:3000`.
Results land in `results/`, which is gitignored. The referee declares no engine constraint, so any
maintained Node works. CI tracks the active LTS.

`URL_HOST` overrides only the authority the referee reaches the fixture by, leaving the bind address
to `HOST`. The fixture admits both spellings of loopback, and `dns-rebinding-protection` is the one
scenario that can tell them apart, since it sends an `Origin` matching whichever spelling the URL
carried. Deriving both from `HOST` would mean a run never exercises the other one, so CI re-runs that
scenario with `URL_HOST=localhost`:

```bash
URL_HOST=localhost ./conformance/run-server.sh --scenario dns-rebinding-protection
```

Running [`server.php`](server.php) by hand rather than through the script is fine, and Ctrl-C stops
it. That takes an explicit `trapSignal()`: with ext-pcntl loaded, Revolt's loop consumes a signal
that no callback is registered for, so an untrapped fixture ignores Ctrl-C and goes on squatting the
port. `run-server.sh` refuses to start on a taken port rather than silently scoring a stale
listener, so a leaked one announces itself on the next run.

None of this runs inside `composer test:with-untracked`. The gate suite is hermetic and offline, and
conformance is neither. The harness sources are still held to the repo's standards though: `conformance/`
is in the PHPStan paths (as is `examples/`), the PHP-CS-Fixer finder, and the dependency analyser, so a
fixture that stops type-checking fails the normal gates rather than waiting for a conformance run.

## Production posture

[`server.php`](server.php) forces itself into the posture production code runs under: xdebug off and
`assert()` not executing. When the invoking PHP has xdebug active, [`composer/xdebug-handler`][handler]
restarts the script once with the extension dropped from the loaded ini, and `zend.assertions` is
lowered at runtime (`-1` is startup-only, so runtime lowering stops at compiled-but-not-executed).
Nothing needs setting up front: a bare `php conformance/server.php` from a development shell lands in
the same posture CI measures.

[handler]: https://github.com/composer/xdebug-handler

This is load-bearing for the scores, not hygiene. An instrumented fixture can stall long enough for
the HTTP host to time a streaming response out mid-body, and the referee reads the truncated stream
as a missing `tools/call` result, so timing-sensitive scenarios such as `tools-call-with-progress`
fail intermittently under xdebug while the production posture passes them reliably. Score only
against the posture the fixture forces.

Two operational notes. To step-debug the fixture itself, set `MCP_CONFORMANCE_ALLOW_XDEBUG=1`, which
skips the restart. And the restart leaves the original process waiting as a parent, which is why
`run-server.sh` tears the fixture down by process group rather than by single PID.

The fixture also closes the connection after every response. On macOS loopback, a kept-alive
connection the referee's Node client reuses after an idle gap can stall into TCP retransmission
for 10+ seconds, tripping the referee's request timeout on checks that pause between requests
(the tasks TTL probe was the reproducible case, and a bare `amphp/http-server` echo fixture
reproduces it with no SDK code involved). One connection per response sidesteps the interaction
at a cost conformance traffic never notices.

## What is being targeted

`--spec-version 2026-07-28`, pinned in [`run-server.sh`](run-server.sh). This SDK implements that
revision only, and the referee filters scenarios by a `removedIn` field, so pinning the version is
also what drops the 2025-era scenarios for features this SDK deliberately does not implement
(`initialize`, `logging/setLevel`, sampling, `resources/subscribe`) instead of failing them.

The SEP-1730 tier percentage is scored over a narrower set than the badges report, and the referee's own
`tier-check` is what computes it. A scenario counts only when it is live at one of the 2025 dated
versions, so the ones the 2026-07-28 revision introduced are reported as informational. The tier scorer
also counts scenarios rather than checks and tolerates an unmet SHOULD, so it reads higher than
`composer conformance:score` does, and it runs server conformance at the referee's default
`--suite active`, which leaves the draft and pending scenarios out of the denominator entirely.

As it stands: server 20 of 20 (100%), client 15 of 15 (100%), verdict Tier 3 with the stable release the
only failing check. The verdict is a self-assessment: the SDK Working Group currently assigns tiers to
official SDKs only. See [.github/TIERING_CHECKLIST.md](../.github/TIERING_CHECKLIST.md) for the gate,
the command to reproduce it, and the assignment-practice caveat.

## The README badges

`conformance/badges/server.json` and `client.json` are shields.io endpoint payloads, committed and read
straight off the default branch by the README. Regenerate them from the last run with:

```bash
composer conformance:badge
```

Only the modes the run covered are rewritten, so scoring a client run cannot blank the server badge. CI
regenerates and diffs them, and a stale file fails the build rather than quietly advertising a score
nobody measured.

The scorer folds in every result under `conformance/results/`, so leftover targeted runs (the
`tasks-*` scenarios, a single `--scenario` repro) inflate a locally regenerated badge beyond what
CI measures with `--suite all`, and the drift check then rejects it. Clear `conformance/results/`
or re-run the suite before regenerating.

The number is the check pass rate at `--spec-version 2026-07-28`. It is **not** the SEP-1730 tier
percentage, which is computed over a narrower denominator and a more forgiving rule (see above).

## The baseline

[`expected-failures.yaml`](expected-failures.yaml) lists what does not pass yet. The referee exits 0
when the only failures are listed, 1 on an unlisted failure, and **1 on a stale entry**: a listed
scenario that has started passing. That last rule is the point. It forces the list to burn down
rather than rot, so every entry names the work that will retire it.

An unmet SHOULD arrives as a `WARNING`, and the referee's exit code treats it exactly like a failure.
[`score.php`](score.php) does the same, so the reported number cannot flatter the SDK by quietly
ignoring them.

The client leg passes whole, so its half of the file is an empty list rather than an absent key. The
referee reads the key either way, and keeping it there makes admitting a new failure a deliberate
edit instead of a new section nobody reviews.

## Bumping the referee

Change `CONFORMANCE_VERSION` in [`run-server.sh`](run-server.sh) and [`run-client.sh`](run-client.sh),
and reconcile `expected-failures.yaml` **in the same change**. A new release routinely adds scenarios
and checks, so bumping alone turns that into unexplained CI failures, and reconciling alone hides a
regression.

Take the version from npm's **`alpha`** tag. `latest` still points at the older `0.1.x` line, which
predates every 2026-07-28 scenario, so tracking it would be a downgrade:

```bash
npm view @modelcontextprotocol/conformance@alpha version
```

You do not have to watch for releases yourself. `.github/workflows/conformance-weekly.yml` runs both
legs every Monday against whatever `alpha` currently resolves to, overriding the pin through the
`CONFORMANCE_VERSION` environment variable. It fails on either of two things, and says which:

- **A newer version is published.** This fails even when the suite still passes at it, because
  adopting one is a deliberate bump plus a baseline reconcile rather than something to drift into.
- **The suite no longer passes** at that version, which names the scenarios that moved. The uploaded
  results artefact has the detail.

Neither is a regression in this SDK on its own. The fix for both is the bump-and-reconcile above, and
the job goes green once the pin catches up. It does not touch the badges, which record the score at
the pinned version.

## The fixture

[`EverythingServer.php`](EverythingServer.php) is one `#[AsServer]` class whose public methods carry
`#[AsTool]`, `#[AsPrompt]`, `#[AsResource]`, and `#[AsResourceTemplate]`. It is the largest worked
example of attribute discovery in the repo: `ServerBuilder::register()` derives every `inputSchema`
from parameter types and `@param` lines, injects `ServerContext` without exposing it to the client,
and adapts a plain return value to the matching result, so most methods are a signature plus a
return.

That also puts the feature under third-party pressure. `tools-list` validates every derived schema
against the spec, which is a sharper test of the generator than the SDK's own unit tests.

[`MultiRoundServer.php`](MultiRoundServer.php) carries the `input-required-result-*` fixtures, and
[`TasksServer.php`](TasksServer.php) the tools the `tasks-*` scenarios name (`greet`,
`slow_compute`, `failing_job`, and friends), with their per-tool `ToolTaskPolicy` map living in
[`server.php`](server.php)'s `TasksServerExtension` registration.

### The client side

[`client.php`](client.php) is a scenario-name to closure registry, because in client mode the
referee names the behaviour it wants through `MCP_CONFORMANCE_SCENARIO`. On an unknown name it
prints every registered scenario before exiting, which is what makes a name mismatch diagnosable
instead of a silent failure. There is no attribute-discovery equivalent by design: a client has no
collection to co-locate and its handlers are singletons.

[`HeadlessUserAuthorization.php`](HeadlessUserAuthorization.php) is the piece worth knowing about.
The OAuth scenarios need consent granted without a browser, and the referee's mock authorization
server grants it on the first request, so following the authorization URL once with redirects
disabled and reading `Location` yields the callback the SDK expects.

One authorized client serves the whole OAuth block, since what varies between those scenarios lives
in the referee's mock rather than in anything the client chooses. Two of them are the exception and
carry their own handler, because the obligation under test is only reachable after the first
request succeeds: a step-up challenge answers a `tools/call`, and an authorization-server change is
announced to the request that follows the one it accepted. The Client ID Metadata Document URL is a
third such detail, hardcoded because the referee compares the string and never fetches it.

### Deliberate exceptions on the server side

Two things sit outside the attribute-discovered fixture:

- `json_schema_2020_12_tool` uses `#[InputSchema(definition: ...)]`, because the scenario asserts a
  hand-written 2020-12 document survives verbatim, down to `$anchor` and `if`/`then`/`else`.
- Completions have no discovery attribute, so [`server.php`](server.php) registers a `CompletionStore`
  the explicit way.
- `test_trigger_tool_change` and `test_trigger_prompt_change` mutate the listings at runtime, which the
  subscription checks need in order to observe a `list_changed` on an open stream. They are the one place
  the fixture reaches for the stores `build()` assembled rather than declaring through attributes, so
  [`server.php`](server.php) hands them over with `useStores()` after building.
