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
```

Both runners pass arguments straight through to the referee:

```bash
./conformance/run-server.sh --suite all               # adds draft and pending scenarios
./conformance/run-server.sh --scenario tools-list -v  # one scenario, verbose
./conformance/run-client.sh --scenario tools_call
```

The two modes invert. In **server mode** the referee is the client, so `run-server.sh` boots
[`server.php`](server.php) first and tears it down after. In **client mode** the referee is the
server: it stands a mock up per scenario and spawns [`client.php`](client.php) once per scenario,
so nothing needs starting first. Both write into the same `results/` directory, so score a run
before starting the other.

Needs Node (for `npx`) and a free port. `PORT` and `HOST` override the default `127.0.0.1:3000`.
Results land in `results/`, which is gitignored. The referee declares no engine constraint, so any
maintained Node works. CI tracks the active LTS.

None of this runs inside `composer test:with-untracked`. The gate suite is hermetic and offline, and
conformance is neither.

## What is being targeted

`--spec-version 2026-07-28`, pinned in [`run-server.sh`](run-server.sh). This SDK implements that
revision only, and the referee filters scenarios by a `removedIn` field, so pinning the version is
also what drops the 2025-era scenarios for features this SDK deliberately does not implement
(`initialize`, `logging/setLevel`, sampling, `resources/subscribe`) instead of failing them.

A tier percentage from the suite's own `tier-check` is not reachable yet, and that is upstream's
state rather than a gap here: its `DATED_SPEC_VERSIONS` still ends at `2025-11-25`, and it scores
only over dated versions. When upstream dates 2026-07-28, these scenarios begin to count.

## The baseline

[`expected-failures.yaml`](expected-failures.yaml) lists what does not pass yet. The referee exits 0
when the only failures are listed, 1 on an unlisted failure, and **1 on a stale entry**: a listed
scenario that has started passing. That last rule is the point. It forces the list to burn down
rather than rot, so every entry names the work that will retire it.

An unmet SHOULD arrives as a `WARNING`, and the referee's exit code treats it exactly like a failure.
[`score.php`](score.php) does the same, so the reported number cannot flatter the SDK by quietly
ignoring them.

## Bumping the referee

Change `CONFORMANCE_VERSION` in [`run-server.sh`](run-server.sh) and reconcile
`expected-failures.yaml` **in the same change**. A new release routinely adds scenarios and checks,
so bumping alone turns that into unexplained CI failures, and reconciling alone hides a regression.

## The fixture

[`EverythingServer.php`](EverythingServer.php) is one `#[AsServer]` class whose public methods carry
`#[AsTool]`, `#[AsPrompt]`, `#[AsResource]`, and `#[AsResourceTemplate]`. It is the largest worked
example of attribute discovery in the repo: `ServerBuilder::register()` derives every `inputSchema`
from parameter types and `@param` lines, injects `ServerContext` without exposing it to the client,
and adapts a plain return value to the matching result, so most methods are a signature plus a
return.

That also puts the feature under third-party pressure. `tools-list` validates every derived schema
against the spec, which is a sharper test of the generator than the SDK's own unit tests.

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

### Deliberate exceptions on the server side

Two things sit outside the attribute-discovered fixture:

- `json_schema_2020_12_tool` uses `#[InputSchema(definition: ...)]`, because the scenario asserts a
  hand-written 2020-12 document survives verbatim, down to `$anchor` and `if`/`then`/`else`.
- Completions have no discovery attribute, so [`server.php`](server.php) registers a `CompletionStore`
  the explicit way.
