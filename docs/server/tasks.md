# Tasks

The tasks extension (`io.modelcontextprotocol/tasks`, SEP-2663) lets a tool call run as a
long-lived task the client polls, instead of holding the request open. It ships in
`Nexus\Mcp\Extension\Tasks` and, like every extension, is disabled until enabled explicitly:

```php
use Nexus\Mcp\Extension\Tasks\Server\TasksServerExtension;
use Nexus\Mcp\Extension\Tasks\Server\TaskSupport;
use Nexus\Mcp\Extension\Tasks\Server\ToolTaskPolicy;
use Nexus\Mcp\Server\ServerBuilder;

$server = new ServerBuilder()
    ->setServerInfo('demo', '1.0.0')
    ->register($tools)
    ->enableExtension(new TasksServerExtension(
        toolPolicies: [
            'slow_compute' => new ToolTaskPolicy(support: TaskSupport::Optional),
            'batch_import' => new ToolTaskPolicy(support: TaskSupport::Required),
        ],
    ))
    ->build();
```

Enabling the extension advertises the capability, serves `tasks/get`, `tasks/update`, and
`tasks/cancel`, and wraps the `tools/call` handler in a broker that decides per request whether
the call runs synchronously or as a task. `tasks/list` and the v1 `tasks/result` stay
unregistered, so both answer `-32601`. The server must serve `tools/call` (register at least one
tool), or `build()` fails: a decorated method needs a handler to wrap. Enable each extension
instance on exactly one builder, since the broker binds the built server's `tools/call` chain.

## Per-tool policies

The broker consults `ToolTaskPolicy` by tool name. A tool absent from the map is always
synchronous. For a listed tool, `TaskSupport` decides what happens per request:

| Support | Client declared the extension | Client did not declare it |
| --- | --- | --- |
| `Optional` | Runs as a task. | Runs synchronously. |
| `Required` | Runs as a task. | Rejected with `-32021`, naming the extension in `error.data.requiredCapabilities`. |

The declaration is per request: the broker reads the `_meta`
`io.modelcontextprotocol/clientCapabilities` envelope, so a session that never negotiated the
extension can still opt in on a single call. A task handle is never returned to a client that did
not declare the extension.

`ToolTaskPolicy(resolvesInputFirst: true)` makes the broker delegate synchronously until the call
carries a `requestState` continuation token. A tool that asks for input via
[`InputRequiredResult`](input-required.md) then resolves its input rounds synchronously first,
and only the resumed call becomes a task.

## The task lifecycle

A task-bound call durably creates the record, starts the tool in a background fiber, and answers
immediately with a flat `CreateTaskResult` (`resultType: "task"`, `taskId`, `status`, timestamps,
`ttlMs`, `pollIntervalMs`). The outcome of the fiber settles the record:

- A `CallToolResult` completes the task, `isError` included: a tool-level failure is `completed`
  with `result.isError`, never the `failed` status.
- An `InputRequiredResult` parks the task in `input_required` with its `inputRequests` map. Each
  request key must be unique over the task's lifetime, so a tool that re-asks must mint a fresh
  key per round: reusing one fails the task, as does parking with a `requestState` but no
  `inputRequests` (an unresumable state no poll could answer).
- A protocol exception fails the task, inlining `error.code` and `error.message`. `failed` is
  reserved for protocol errors.
- Cooperative cancellation (`tasks/cancel` cancels the fiber's token) settles it as `cancelled`.

`tasks/get` projects the record: a completed task inlines `result`, a failed one inlines `error`,
an `input_required` one carries the pending `inputRequests`. An unknown `taskId` on any tasks
method is `-32602`. Terminal states are sticky, so a completion racing a cancel loses, and
`tasks/cancel` on a terminal task still acks.

`tasks/update` merges the client's `inputResponses` into the pending set. Responses for keys that
were never issued are ignored whatever their shape, and a partial answer keeps the task in
`input_required`. Once nothing is pending, the tool call re-dispatches in a fresh background
fiber with the accumulated responses and the stored `requestState`, byte-for-byte what a
synchronous client would re-issue, so the tool's continuation token carries all the state.

A background task cannot reach the creating request's connection: outbound notifications from a
task fiber are dropped with a debug log, and outbound requests throw.

## Storage and retention

`TasksServerExtension` takes a `TaskStoreInterface` (default: `InMemoryTaskStore`). Retention
starts at the terminal transition: a live task never expires, and a terminal record stays
readable for `ttlMs` milliseconds after it settles (`null` retains it indefinitely). Defaults are
`ttlMs: 300_000` and `pollIntervalMs: 1_000`, both constructor-tunable.

The in-memory store confines tasks to the process. The record survives in whatever store you
implement, but the in-process cancellation map does not, so cancellation is cooperative only
within the serving process.

The broker is what upholds the never-to-a-non-declaring-client rule. A
`replaceRequestHandler('tools/call')` replacement that returns a `CreateTaskResult` itself
bypasses the broker, and upholding that rule is then the replacement's own job.

## Routing headers

The tasks methods carry the [SEP-2243 routing headers](../transports.md): `Mcp-Name` mirrors
`params.taskId` on `tasks/get`, `tasks/update`, and `tasks/cancel`, and a mismatched header is
rejected with `-32020` like any other header mismatch.

The client half is documented in [Client tasks](../client/tasks.md).
