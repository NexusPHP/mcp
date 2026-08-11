# Tasks

The client half of the tasks extension (`io.modelcontextprotocol/tasks`, SEP-2663) pairs
`TasksClientExtension`, which advertises the capability and gates the outbound `tasks/*` methods,
with the `TaskClient` facade, which speaks them:

```php
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Extension\Tasks\Client\TaskClient;
use Nexus\Mcp\Extension\Tasks\Client\TasksClientExtension;

$client = (new ClientBuilder())
    ->setClientInfo('demo', '1.0.0')
    ->enableExtension(new TasksClientExtension())
    ->build();

$client->connect($transport);
$client->discover();

$tasks = new TaskClient($client);
```

Enabling the extension stamps `io.modelcontextprotocol/tasks` into the `_meta`
`io.modelcontextprotocol/clientCapabilities` envelope on every request, which is what permits the
server to answer a tool call with a task handle. After `discover()`, the outbound gate refuses
`tasks/get`, `tasks/update`, and `tasks/cancel` against a server that did not advertise the
extension.

## Calling a tool that may become a task

Once the extension is enabled, route every possibly-policied tool through the facade. The typed
`Client::callTool()` decodes with the core response envelope, which cannot represent a task
handle, so a call the server diverts into a task fails with a decode error while the task runs on
unobserved. `callToolAsTask()` decodes all three shapes the server may choose:

```php
use Nexus\Mcp\Extension\Tasks\Schema\Result\CreateTaskResult;

$outcome = $tasks->callToolAsTask('slow_compute', ['seconds' => 30, 'label' => 'report']);

if ($outcome instanceof CreateTaskResult) {
    $terminal = $tasks->awaitTask($outcome);
}
```

A `CallToolResult` is the ordinary synchronous answer. A `CreateTaskResult` is a task handle. An
`InputRequiredResult` is the synchronous multi-round input flow, which a task-supporting tool may
still use before creating a task (see
[answering an `InputRequiredResult`](input-required.md)). Answer it by re-issuing the call with
the continuation parameters, and the resumed round may then become the task:

```php
$outcome = $tasks->callToolAsTask(
    'test_tool_with_task',
    inputResponses: ['task_user_name' => new ElicitResult(action: ElicitAction::Accept, content: ['name' => 'Alice'])],
    requestState: $outcome->requestState,
);
```

## Polling with `awaitTask()`

`awaitTask()` polls `tasks/get` at the server-suggested `pollIntervalMs` until the task settles,
and returns the terminal `GetTaskResult` whatever its status (`completed`, `failed`, or
`cancelled`). When the task parks in `input_required`, new input requests are dispatched to the
optional resolver, and its answers ride `tasks/update`:

```php
use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;

$terminal = $tasks->awaitTask($handle, static fn(array $inputRequests): array => [
    'confirm_delete' => new ElicitResult(action: ElicitAction::Accept, content: ['confirm' => true]),
]);
```

The resolver receives the unanswered requests keyed by their request key, and answers what it
can. Anything unanswered stays pending on the server.

Keys already answered in the current park are not re-dispatched, a key the server re-issues after
the task resumes `working` is offered again, and an answer for a key that was never offered is
discarded rather than sent. A task that stays `input_required` while the resolver sends nothing
for `stallCeiling` consecutive polls (default 60, constructor-tunable) throws
`StalledTaskException` rather than spinning forever, and a `working` poll breaks the streak. A
task that stays `working` is polled for as
long as it takes: pass an `Amp\Cancellation` as the third argument to bound the wait, and the
loop aborts with `CancelledException` when it fires.

## The typed methods

`getTask()`, `updateTask()`, and `cancelTask()` are the direct counterparts of the three
`tasks/*` methods, riding [`sendRequest()`](requests.md#the-escape-hatch-sendrequest) with the
lifecycle `_meta` stamped. `cancelTask()` is cooperative: the server acks immediately and the
task settles as `cancelled` once its fiber observes the cancellation.

The server half is documented in [Server tasks](../server/tasks.md), and
[examples/tasks.php](../../examples/tasks.php) runs the whole loop in one process.
